<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Commissions\Events\CommissionTierCreated;
use App\Modules\Commissions\Events\CommissionTierDeleted;
use App\Modules\Commissions\Events\CommissionTierUpdated;
use App\Modules\Commissions\Models\CommissionTier;
use App\Shared\Helpers\PageSize;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * The commission configuration an administrator maintains: the bands, and the
 * single question the rest of the platform asks of them — "what rate applies
 * to this amount?".
 *
 * Two active bands may never claim the same peso amount, or the rate charged
 * would depend on row order. That rule is enforced here for every database
 * driver, and again by an exclusion constraint on PostgreSQL, which is what
 * actually closes the race between two administrators saving at once.
 * Disabled and deleted bands are exempt: they charge nobody.
 */
class CommissionTierService extends BaseService
{
    /**
     * @param  array{search?: string, is_active?: bool, sort?: string, direction?: string, per_page?: int}  $filters
     */
    public function index(array $filters): LengthAwarePaginator
    {
        $query = CommissionTier::query()->with(['createdBy:id,name', 'updatedBy:id,name']);

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $sort = in_array($filters['sort'] ?? null, ['min_amount', 'percentage', 'name', 'created_at'], true)
            ? $filters['sort']
            : 'min_amount';
        $direction = ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc';

        // The bands read as a ladder, so the default order is by amount.
        return $query->orderBy($sort, $direction)->orderBy('id')->paginate(PageSize::from($filters));
    }

    public function show(CommissionTier $tier): CommissionTier
    {
        return $tier->load(['createdBy:id,name', 'updatedBy:id,name']);
    }

    /**
     * Every band currently in force, ordered so that the tightest (highest)
     * lower bound comes first. The order only matters if a hand-edited
     * database contains an overlap the application would have refused; it
     * makes the resolved rate deterministic either way.
     *
     * Bands are few, so callers that price many amounts fetch this once and
     * match in memory rather than querying per amount.
     *
     * @return Collection<int, CommissionTier>
     */
    public function activeTiers(): Collection
    {
        return CommissionTier::query()
            ->active()
            ->orderByDesc('min_amount')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The band that applies to [$amount], or null when the configuration
     * leaves that amount uncovered. A gap is a valid (if unusual)
     * configuration; the caller decides what an uncovered amount costs.
     */
    public function resolve(float $amount): ?CommissionTier
    {
        return $this->activeTiers()->first(fn (CommissionTier $tier): bool => $tier->covers($amount));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function store(array $validated, User $actor): CommissionTier
    {
        return $this->guardAgainstDatabaseOverlap(fn (): CommissionTier => $this->transaction(
            function () use ($validated, $actor): CommissionTier {
                $isActive = (bool) ($validated['is_active'] ?? true);
                $min = (float) $validated['min_amount'];
                $max = isset($validated['max_amount']) ? (float) $validated['max_amount'] : null;

                if ($isActive) {
                    $this->assertNoOverlap($min, $max, null);
                }

                $tier = CommissionTier::create([
                    'name' => $validated['name'],
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'percentage' => $validated['percentage'],
                    'is_active' => $isActive,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                event(new CommissionTierCreated(tier: $tier, actor: $actor, data: $validated));

                return $this->show($tier);
            },
        ));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function update(CommissionTier $tier, array $validated, User $actor): CommissionTier
    {
        return $this->guardAgainstDatabaseOverlap(fn (): CommissionTier => $this->transaction(
            function () use ($tier, $validated, $actor): CommissionTier {
                $tier = CommissionTier::query()->lockForUpdate()->findOrFail($tier->id);
                $before = $this->snapshot($tier);

                $isActive = array_key_exists('is_active', $validated)
                    ? (bool) $validated['is_active']
                    : (bool) $tier->is_active;

                $min = array_key_exists('min_amount', $validated)
                    ? (float) $validated['min_amount']
                    : (float) $tier->min_amount;

                $max = array_key_exists('max_amount', $validated)
                    ? ($validated['max_amount'] === null ? null : (float) $validated['max_amount'])
                    : ($tier->max_amount === null ? null : (float) $tier->max_amount);

                if ($isActive) {
                    $this->assertNoOverlap($min, $max, $tier->id);
                }

                $tier->update([
                    ...array_intersect_key($validated, array_flip(['name', 'percentage'])),
                    'min_amount' => $min,
                    'max_amount' => $max,
                    'is_active' => $isActive,
                    'updated_by' => $actor->id,
                ]);

                event(new CommissionTierUpdated(
                    tier: $tier,
                    actor: $actor,
                    before: $before,
                    after: $this->snapshot($tier),
                ));

                return $this->show($tier);
            },
        ));
    }

    /**
     * Retire a band. Soft deletion keeps it readable from the bookings that
     * were charged under it; those carry their own rate snapshot regardless.
     */
    public function destroy(CommissionTier $tier, User $actor): void
    {
        $this->transaction(function () use ($tier, $actor): void {
            $tier->delete();

            event(new CommissionTierDeleted(tier: $tier, actor: $actor));
        });
    }

    /**
     * Refuse a band that would claim peso amounts an active band already
     * claims. Ranges are inclusive at both ends, and a NULL bound is
     * unbounded, so [$min, $max] and [emin, emax] collide when
     * `$min <= emax` and `emin <= $max`.
     */
    private function assertNoOverlap(float $min, ?float $max, ?int $ignoreId): void
    {
        $query = CommissionTier::query()
            ->active()
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            // $min <= emax
            ->where(fn (Builder $q) => $q->whereNull('max_amount')->orWhere('max_amount', '>=', $min));

        // emin <= $max; an open-ended new band has no upper bound to fail.
        if ($max !== null) {
            $query->where('min_amount', '<=', $max);
        }

        // Locks the rows that would collide. On PostgreSQL the exclusion
        // constraint is what makes a concurrent INSERT safe as well.
        $clash = $query->lockForUpdate()->first();

        if ($clash === null) {
            return;
        }

        throw ValidationException::withMessages([
            'min_amount' => [sprintf(
                'This range overlaps the active tier "%s" (%s–%s).',
                $clash->name,
                number_format((float) $clash->min_amount, 2, '.', ''),
                $clash->max_amount === null ? 'above' : number_format((float) $clash->max_amount, 2, '.', ''),
            )],
        ]);
    }

    /**
     * Translate the PostgreSQL exclusion constraint into the same validation
     * error the application check raises, so a lost race reads like a
     * rejected form rather than a server error.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function guardAgainstDatabaseOverlap(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            if (! str_contains($exception->getMessage(), 'commission_tiers_no_active_overlap')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'min_amount' => ['This range overlaps an active commission tier. Reload the page and try again.'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CommissionTier $tier): array
    {
        return [
            'name' => $tier->name,
            'min_amount' => $tier->min_amount,
            'max_amount' => $tier->max_amount,
            'percentage' => $tier->percentage,
            'is_active' => $tier->is_active,
        ];
    }
}

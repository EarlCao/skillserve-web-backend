<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes every stored rating under the service-based provider rating.
 *
 * A provider's `average_rating` used to be the average of all its active
 * reviews, so a service with many reviews outweighed one with few. It is now
 * the average of its rated services' ratings, each service counting once
 * (`RecalculateRatingAggregatesAction`). Migrations run on every deploy, which
 * is what brings the ratings already stored in production into line.
 *
 * ## Data impact
 *
 * Only `services.average_rating` / `total_reviews` and
 * `provider_profiles.average_rating` / `total_reviews` are rewritten, from the
 * active reviews. Provider ratings change wherever a provider's services have
 * different review counts. Service ratings change only where they were stale:
 * hiding, removing or restoring a review from the admin side did not
 * recalculate them before. Reviews themselves are untouched. No schema change.
 *
 * Idempotent: running it again produces the same values.
 *
 * ## Deployment order and rollback
 *
 * Ships with the code that keeps the new rule; the order does not matter
 * because both write the same values. `down()` puts provider ratings back to
 * the plain average of their active reviews, which is what the previous code
 * expects, and leaves the corrected service ratings as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $serviceRatings = $this->activeReviews()
                ->groupBy('provider_id', 'service_id')
                ->selectRaw('provider_id, service_id, ROUND(AVG(rating), 2) as average_rating, COUNT(*) as total_reviews')
                ->get();

            $this->resetAll('services');
            foreach ($serviceRatings as $row) {
                DB::table('services')->where('id', $row->service_id)->update([
                    'average_rating' => round((float) $row->average_rating, 2),
                    'total_reviews' => (int) $row->total_reviews,
                ]);
            }

            $this->resetAll('provider_profiles');
            foreach ($serviceRatings->groupBy('provider_id') as $providerId => $services) {
                DB::table('provider_profiles')->where('id', $providerId)->update([
                    'average_rating' => round((float) $services->avg(fn ($row) => (float) $row->average_rating), 2),
                    'total_reviews' => (int) $services->sum('total_reviews'),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $providerRatings = $this->activeReviews()
                ->groupBy('provider_id')
                ->selectRaw('provider_id, AVG(rating) as average_rating, COUNT(*) as total_reviews')
                ->get();

            $this->resetAll('provider_profiles');
            foreach ($providerRatings as $row) {
                DB::table('provider_profiles')->where('id', $row->provider_id)->update([
                    'average_rating' => round((float) $row->average_rating, 2),
                    'total_reviews' => (int) $row->total_reviews,
                ]);
            }
        });
    }

    private function activeReviews(): Builder
    {
        return DB::table('reviews')->where('status', 'active')->whereNull('deleted_at');
    }

    private function resetAll(string $table): void
    {
        DB::table($table)
            ->where(fn ($query) => $query->where('average_rating', '<>', 0)->orWhere('total_reviews', '<>', 0))
            ->update(['average_rating' => 0, 'total_reviews' => 0]);
    }
};

<?php

namespace App\Modules\Commissions\Services;

use App\Modules\Commissions\Models\CommissionTier;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The single authority on what SkillServe charges for a given booking amount.
 *
 * The commission is *inclusive*: it lives inside the price the provider
 * advertises, so the customer pays that price and nothing more, and the
 * provider receives the remainder.
 *
 *     ₱200 service · 10% tier  →  ₱20 commission · ₱180 to the provider
 *
 * Which rate applies:
 *
 * - a matching active tier        → the tier's percentage
 * - tiers exist but none matches  → 0%, and the gap is logged, because a
 *                                   configuration hole must not silently
 *                                   become a charge
 * - no active tiers at all        → the legacy flat
 *                                   `marketplace.commission_rate` setting, so
 *                                   a deployment that has not configured tiers
 *                                   yet behaves exactly as it did before
 *
 * Never trust a client-supplied commission figure; every amount the platform
 * charges comes from here.
 */
class CommissionCalculator
{
    /**
     * Fetched once and reused: a provider's service list prices many rows,
     * and the bands are the same for all of them. Registered as a scoped
     * binding so one request shares one instance (see AppServiceProvider).
     *
     * @var Collection<int, CommissionTier>|null
     */
    private ?Collection $activeTiers = null;

    public function __construct(
        private readonly CommissionTierService $tiers,
        private readonly SettingsService $settings,
    ) {}

    /**
     * The full breakdown for [$amount], with the amount itself as the base.
     *
     * @return array{
     *     base_amount: float,
     *     rate: float,
     *     commission_amount: float,
     *     net_amount: float,
     *     tier_id: int|null,
     *     tier_name: string|null,
     *     source: string,
     * }
     */
    public function for(float $amount): array
    {
        $amount = round(max(0.0, $amount), 2);

        [$rate, $tier, $source] = $this->rateFor($amount);

        $commission = round($amount * $rate / 100, 2);

        return [
            'base_amount' => $amount,
            'rate' => $rate,
            'commission_amount' => $commission,
            // What the provider actually receives.
            'net_amount' => round($amount - $commission, 2),
            'tier_id' => $tier?->id,
            'tier_name' => $tier?->name,
            // tier · gap · fallback — why this rate was used.
            'source' => $source,
        ];
    }

    /**
     * @return array{0: float, 1: CommissionTier|null, 2: string}
     */
    private function rateFor(float $amount): array
    {
        $tiers = $this->activeTiers();

        $tier = $tiers->first(fn (CommissionTier $candidate): bool => $candidate->covers($amount));

        if ($tier !== null) {
            return [(float) $tier->percentage, $tier, 'tier'];
        }

        if ($tiers->isNotEmpty()) {
            // Configured tiers that leave this amount uncovered: charge
            // nothing rather than guess, and make the hole visible.
            Log::warning('No commission tier covers this amount; charging 0%.', [
                'amount' => $amount,
            ]);

            return [0.0, null, 'gap'];
        }

        $rate = (float) $this->settings->value('marketplace', 'commission_rate');

        return [max(0.0, min(100.0, $rate)), null, 'fallback'];
    }

    /** @return Collection<int, CommissionTier> */
    private function activeTiers(): Collection
    {
        return $this->activeTiers ??= $this->tiers->activeTiers();
    }
}

<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Commissions\Events\CommissionTierCreated;
use App\Modules\Commissions\Events\CommissionTierDeleted;
use App\Modules\Commissions\Events\CommissionTierUpdated;

/**
 * Persists Commission Management events into the Spatie activity log.
 *
 * Commission rates decide what SkillServe charges, so every change is
 * attributable. Registered explicitly in AppServiceProvider (module listeners
 * live outside app/Listeners, so auto-discovery does not apply).
 */
class LogCommissionActivity
{
    public function handle(CommissionTierCreated|CommissionTierUpdated|CommissionTierDeleted $event): void
    {
        [$properties, $description] = match (true) {
            $event instanceof CommissionTierCreated => [
                ['created' => $event->data],
                'commission_tier_created',
            ],
            $event instanceof CommissionTierUpdated => [
                ['before' => $event->before, 'after' => $event->after],
                'commission_tier_updated',
            ],
            default => [
                [
                    'name' => $event->tier->name,
                    'min_amount' => $event->tier->min_amount,
                    'max_amount' => $event->tier->max_amount,
                    'percentage' => $event->tier->percentage,
                ],
                'commission_tier_deleted',
            ],
        };

        activity('commissions')
            ->causedBy($event->actor)
            ->performedOn($event->tier)
            ->withProperties($properties)
            ->log($description);
    }
}

<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;
use App\Shared\Enums\AccountRole;
use Illuminate\Database\Seeder;

/**
 * The "Standard" commission tiers from `config/commissions.php` (5%, 10%,
 * 15%, 20% by booking amount), so a fresh database charges a sensible
 * commission without an administrator setting it up first.
 *
 * Runs only on a database that has never had a tier, retired ones included:
 * an administrator's own tiers, or a deliberate decision to have none, are
 * never overwritten. Recorded as created by the super-admin.
 */
class DefaultCommissionTierSeeder extends Seeder
{
    public const PRESET = 'standard';

    public function run(): void
    {
        if (CommissionTier::withTrashed()->exists()) {
            return;
        }

        $adminId = User::query()->where('role_id', AccountRole::SuperAdmin->value)->value('id');

        foreach (config('commissions.presets.'.self::PRESET.'.tiers', []) as $band) {
            CommissionTier::create([
                ...$band,
                'is_active' => true,
                'created_by' => $adminId,
                'updated_by' => $adminId,
            ]);
        }
    }
}

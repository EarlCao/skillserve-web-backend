<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds at least 150 customer accounts so the User Management screens have
 * realistic data to list, search, filter and moderate.
 *
 * Only customers are seeded: every account is created WITHOUT roles and with
 * user_type "customer". Role-bearing accounts are administrators and belong
 * to the Administrator Management module, so they are never created here —
 * the only administrator in the database is the bootstrap super-admin from
 * RolePermissionSeeder.
 *
 * A realistic spread is applied: the vast majority are active and verified,
 * with a slice of suspended / banned / unverified accounts so the status and
 * verification filters and the moderation badges have data to show. The
 * bootstrap super-admin (RolePermissionSeeder) is recorded as the actor on
 * created_by / moderation timestamps so the UI shows who acted.
 *
 * Idempotent: re-running only tops the platform-user count back up to the
 * target, so existing seeded data and any real accounts are left alone.
 */
class UsersSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Minimum number of platform users to guarantee.
     */
    private const TARGET_USERS = 150;

    public function run(): void
    {
        // Any role-bearing account works as the actor on moderation stamps.
        $actor = User::query()->whereHas('roles')->first();

        $existing = User::query()
            ->where('user_type', 'customer')
            ->doesntHave('roles')
            ->count();
        $missing = max(0, self::TARGET_USERS - $existing);

        if ($missing === 0) {
            $this->command?->info("Already {$existing} customers — nothing to seed.");

            return;
        }

        for ($i = 0; $i < $missing; $i++) {
            User::factory()->create($this->profileState($actor));
        }

        $this->command?->info("Seeded {$missing} customers — status breakdown: ".$this->statusBreakdown().'.');
    }

    /**
     * Build the profile attributes for one seeded platform user.
     *
     * @param  User|null  $actor  The administrator recorded as the actor.
     * @return array<string, mixed>
     */
    private function profileState(?User $actor): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        // Roughly 80% active / 12% suspended / 8% banned.
        $status = $this->weightedStatus();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => "{$firstName} {$lastName}",
            'user_type' => 'customer',
            'phone' => fake()->numerify('+1 555 ###-####'),
            'address' => sprintf(
                '%s, %s, %s %s',
                fake()->streetAddress(),
                fake()->city(),
                fake()->stateAbbr(),
                fake()->postcode(),
            ),
            'birthday' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'status' => $status,
            'email_verified_at' => $status === 'banned' || fake()->boolean(12)
                ? null
                : fake()->dateTimeBetween('-400 days', 'now'),
            'last_login_at' => $status === 'active' && fake()->boolean(75)
                ? fake()->dateTimeBetween('-90 days', 'now')
                : null,
            'created_by' => $actor?->id,
            'suspended_at' => $status === 'suspended' ? fake()->dateTimeBetween('-30 days', 'now') : null,
            'suspended_by' => $status === 'suspended' ? $actor?->id : null,
            'suspension_reason' => $status === 'suspended' ? fake()->sentence(5) : null,
            'banned_at' => $status === 'banned' ? fake()->dateTimeBetween('-90 days', 'now') : null,
            'banned_by' => $status === 'banned' ? $actor?->id : null,
            'ban_reason' => $status === 'banned' ? fake()->sentence(5) : null,
        ];
    }

    /**
     * Pick a status with a realistic spread for demo data.
     */
    private function weightedStatus(): string
    {
        $roll = fake()->numberBetween(1, 100);

        return match (true) {
            $roll <= 80 => 'active',
            $roll <= 92 => 'suspended',
            default => 'banned',
        };
    }

    /**
     * Human-readable status counts of the platform users (for the console).
     */
    private function statusBreakdown(): string
    {
        return User::query()
            ->where('user_type', 'customer')
            ->doesntHave('roles')
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count, string $status) => "{$count} {$status}")
            ->join(', ');
    }
}

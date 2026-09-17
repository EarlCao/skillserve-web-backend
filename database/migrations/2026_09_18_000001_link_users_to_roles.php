<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every account now references its role: users.role_id → roles.id, with the
 * fixed rows 1 super-admin, 2 admin, 3 provider, 4 customer. Replaces the
 * free-text users.user_type column.
 *
 * Data impact:
 * - Any other role currently occupying ids 1–4 is moved to a new id; its
 *   administrator assignments, permissions and audit-log references follow it.
 * - role_id is backfilled: staff accounts get their role (super-admin, then
 *   admin, then their lowest custom role); everyone else gets provider or
 *   customer from user_type.
 *
 * Rollback restores user_type from role_id and drops role_id. Roles moved off
 * ids 1–4 keep their new ids.
 */
return new class extends Migration
{
    private const USER_MODEL = 'App\\Models\\User';

    private const ROLE_MODEL = 'Spatie\\Permission\\Models\\Role';

    private const FIXED_ROLES = [1 => 'super-admin', 2 => 'admin', 3 => 'provider', 4 => 'customer'];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::FIXED_ROLES as $id => $name) {
                $this->pinRole($id, $name);
            }
            $this->resetRoleSequence();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('email_verified_at')->constrained('roles')->restrictOnDelete();
            $table->index('role_id');
        });

        DB::transaction(function (): void {
            $this->backfillStaffRoles();

            DB::table('users')->whereNull('role_id')->where('user_type', 'provider')->update(['role_id' => 3]);
            DB::table('users')->whereNull('role_id')->update(['role_id' => 4]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id')->nullable(false)->change();
            $table->dropColumn('user_type');
        });

        app()['cache']->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type')->default('customer')->after('email_verified_at');
        });

        DB::table('users')->where('role_id', 3)->update(['user_type' => 'provider']);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropIndex(['role_id']);
            $table->dropColumn('role_id');
        });

        DB::table('roles')->whereIn('id', [3, 4])->whereIn('name', ['provider', 'customer'])
            ->whereNotExists(fn ($query) => $query->from('role_has_permissions')->whereColumn('role_has_permissions.role_id', 'roles.id'))
            ->delete();
    }

    /**
     * Make sure role $name lives at id $id, relocating whatever is in the way.
     */
    private function pinRole(int $id, string $name): void
    {
        $occupant = DB::table('roles')->where('id', $id)->first();

        if ($occupant && $occupant->name === $name && $occupant->guard_name === 'web') {
            return;
        }

        if ($occupant) {
            $this->moveRole($occupant->id, ((int) DB::table('roles')->max('id')) + 1);
        }

        $existing = DB::table('roles')->where('name', $name)->where('guard_name', 'web')->first();

        if ($existing) {
            $this->moveRole($existing->id, $id);

            return;
        }

        DB::table('roles')->insert([
            'id' => $id,
            'name' => $name,
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function moveRole(int $from, int $to): void
    {
        $row = (array) DB::table('roles')->where('id', $from)->first();

        // Free the unique (name, guard_name) pair before inserting the copy.
        DB::table('roles')->where('id', $from)->update(['name' => $row['name'].'__moving_'.$from]);
        DB::table('roles')->insert(array_merge($row, ['id' => $to]));

        DB::table('model_has_roles')->where('role_id', $from)->update(['role_id' => $to]);
        DB::table('role_has_permissions')->where('role_id', $from)->update(['role_id' => $to]);

        if (Schema::hasTable('activity_log')) {
            DB::table('activity_log')->where('subject_type', self::ROLE_MODEL)->where('subject_id', $from)->update(['subject_id' => $to]);
        }

        DB::table('roles')->where('id', $from)->delete();
    }

    private function resetRoleSequence(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('roles', 'id'), (SELECT MAX(id) FROM roles))");
        }
    }

    private function backfillStaffRoles(): void
    {
        DB::table('model_has_roles')
            ->where('model_type', self::USER_MODEL)
            ->orderBy('model_id')
            ->get(['model_id', 'role_id'])
            ->groupBy('model_id')
            ->each(function ($assignments, $userId): void {
                $roleIds = $assignments->pluck('role_id')->map(fn ($id) => (int) $id)->all();
                $primary = in_array(1, $roleIds, true) ? 1 : (in_array(2, $roleIds, true) ? 2 : min($roleIds));

                DB::table('users')->where('id', $userId)->update(['role_id' => $primary]);
            });
    }
};

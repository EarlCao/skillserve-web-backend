<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured Philippine addresses (Region → Province → City/Municipality →
 * Barangay, PSGC codes from `ph_locations`) next to the free-text address
 * columns that already exist:
 *
 * | Table | Codes | Also | Text column kept |
 * |---|---|---|---|
 * | users, pending_registrations | `address_*` | street, ZIP | `users.address` |
 * | bookings | `service_*` | street, ZIP | `bookings.service_address` |
 * | services | `location_*` | — (an area, not a door) | `services.location` |
 *
 * `pending_registrations` also gains `birthday`, read from the National ID at
 * sign-up and copied to `users.birthday` when the account is created.
 *
 * The codes are not foreign keys: `ph_locations` is replaced wholesale when
 * PSA publishes a new release, and an address must survive that.
 *
 * ## Data impact, deployment and rollback
 *
 * Additive and nullable; no row changes. Existing addresses stay as text and
 * gain codes the next time someone picks them, so no backfill. Deploy before
 * the app version that sends structured addresses; older app versions keep
 * sending text, which is still accepted. Rolling back drops only the new
 * columns; the text columns hold a formatted copy of every structured address.
 */
return new class extends Migration
{
    private const CODES = ['region_code', 'province_code', 'city_code', 'barangay_code'];

    public function up(): void
    {
        foreach (['users', 'pending_registrations'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $this->addCodes($table, 'address');
                $table->string('address_street')->nullable();
                $table->string('address_postal_code', 4)->nullable();
            });
        }

        Schema::table('pending_registrations', function (Blueprint $table): void {
            $table->date('birthday')->nullable();
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $this->addCodes($table, 'service');
            $table->string('service_street')->nullable();
            $table->string('service_postal_code', 4)->nullable();
        });

        Schema::table('services', function (Blueprint $table): void {
            $this->addCodes($table, 'location');
            // Finding services in a city is the marketplace filter this serves.
            $table->index('location_city_code');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropIndex(['location_city_code']);
            $table->dropColumn($this->columns('location'));
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn([...$this->columns('service'), 'service_street', 'service_postal_code']);
        });

        Schema::table('pending_registrations', function (Blueprint $table): void {
            $table->dropColumn('birthday');
        });

        foreach (['users', 'pending_registrations'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn([...$this->columns('address'), 'address_street', 'address_postal_code']);
            });
        }
    }

    private function addCodes(Blueprint $table, string $prefix): void
    {
        foreach (self::CODES as $code) {
            $table->char("{$prefix}_{$code}", 9)->nullable();
        }
    }

    /** @return array<int, string> */
    private function columns(string $prefix): array
    {
        return array_map(fn (string $code): string => "{$prefix}_{$code}", self::CODES);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the provider profile's free-text JSON lists with accented letters
 * as written ("Niño") instead of escaped ("Niño"), matching how
 * ProviderProfile now saves them, so the marketplace search finds them.
 *
 * Data only: the decoded values are identical before and after, nothing in
 * the schema changes, and rows without escapes are left untouched. There is
 * nothing to roll back — `down()` is a no-op because both encodings decode to
 * the same lists.
 */
return new class extends Migration
{
    private const COLUMNS = ['skills', 'certifications', 'languages'];

    public function up(): void
    {
        DB::table('provider_profiles')
            ->select(['id', ...self::COLUMNS])
            ->orderBy('id')
            ->chunkById(200, function ($profiles): void {
                foreach ($profiles as $profile) {
                    $changes = [];

                    foreach (self::COLUMNS as $column) {
                        $stored = $profile->{$column};
                        if (! is_string($stored) || ! str_contains($stored, '\\u')) {
                            continue;
                        }

                        $decoded = json_decode($stored, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            continue;
                        }

                        $rewritten = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                        if ($rewritten !== false && $rewritten !== $stored) {
                            $changes[$column] = $rewritten;
                        }
                    }

                    if ($changes !== []) {
                        DB::table('provider_profiles')->where('id', $profile->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        // Both encodings decode to the same values; nothing to undo.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Move known verification files out of the public disk without changing
     * their database-relative paths. Missing files are left for deployment
     * operators to recover from backup rather than silently deleting metadata.
     */
    public function up(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('verification');

        DB::table('verification_documents')->pluck('file_path')->each(function (string $path) use ($public, $private): void {
            if (! $public->exists($path)) {
                return;
            }

            if (! $private->exists($path)) {
                $stream = $public->readStream($path);
                if (is_resource($stream)) {
                    $private->put($path, $stream);
                    fclose($stream);
                }
            }

            if ($private->exists($path)) {
                $public->delete($path);
            }
        });
    }

    /**
     * Restore files to the public disk only when rolling back this migration.
     * A rollback must be followed by disabling the private download endpoint.
     */
    public function down(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('verification');

        DB::table('verification_documents')->pluck('file_path')->each(function (string $path) use ($public, $private): void {
            if (! $private->exists($path)) {
                return;
            }

            if (! $public->exists($path)) {
                $stream = $private->readStream($path);
                if (is_resource($stream)) {
                    $public->put($path, $stream);
                    fclose($stream);
                }
            }

            if ($public->exists($path)) {
                $private->delete($path);
            }
        });
    }
};

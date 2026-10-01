<?php

namespace App\Shared\Helpers;

use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * Guards a download whose database row outlived its file.
 *
 * Uploads live under storage/app, which on Render survives a deploy only on
 * the persistent disk. Without one, every deploy deletes the files while the
 * rows that point at them remain, so the admin gets a 404 they cannot explain.
 * This answers with a message that says what happened, and logs the path so a
 * missing disk shows up in the server log.
 */
final class StoredFile
{
    /** @throws ApiException 404 when [$path] is not on [$disk] */
    public static function ensureExists(Filesystem $disk, string $path, string $message): void
    {
        if ($disk->exists($path)) {
            return;
        }

        Log::warning('A stored upload is missing from its disk; check that the persistent disk is mounted.', [
            'path' => $path,
        ]);

        throw new ApiException($message, 404);
    }
}

<?php

namespace App\Shared\Helpers;

use App\Shared\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photos sent as proof (with a report or a support ticket): stored on a
 * private disk, never reachable by URL, and streamed only after the caller
 * has been authorised.
 *
 * Files are written before the database transaction that records them, so no
 * lock is held during storage I/O; the caller deletes them again with
 * [delete] if that transaction fails.
 */
final class PrivateImages
{
    /** What one photo may be; the apps send camera or gallery photos. */
    public const RULES = ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];

    public const RULE_MESSAGES = [
        'images.max' => 'Attach at most :max photos.',
        'images.*.image' => 'Each attachment must be a photo.',
        'images.*.mimes' => 'Each photo must be a JPG, PNG or WebP.',
        'images.*.max' => 'Each photo must be 5 MB or smaller.',
    ];

    /**
     * Stores [files] under [directory] on [disk] with random names.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{path: string, mime_type: string, size_bytes: int}>
     */
    public static function store(array $files, string $disk, string $directory): array
    {
        $stored = [];

        try {
            foreach ($files as $file) {
                $path = $file->storeAs($directory, Str::uuid().'.'.$file->extension(), ['disk' => $disk]);

                if ($path === false) {
                    throw new ApiException('A photo could not be saved. Please try again.', 500);
                }

                $stored[] = [
                    'path' => $path,
                    'mime_type' => (string) $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                ];
            }
        } catch (\Throwable $exception) {
            self::delete($disk, $stored);

            throw $exception;
        }

        return $stored;
    }

    /**
     * Removes photos stored by [store] whose rows were never written.
     *
     * @param  array<int, array{path: string}>  $stored
     */
    public static function delete(string $disk, array $stored): void
    {
        if ($stored !== []) {
            Storage::disk($disk)->delete(array_column($stored, 'path'));
        }
    }

    /** Streams one stored photo, or a 404 that says it is gone. */
    public static function stream(string $disk, string $path): StreamedResponse
    {
        $storage = Storage::disk($disk);
        StoredFile::ensureExists($storage, $path, 'This photo is no longer stored on the server.');

        return $storage->response($path, basename($path), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}

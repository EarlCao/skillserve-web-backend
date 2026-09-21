<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Providers\Events\ProviderVerificationSubmitted;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationRequest;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A provider's side of verification: see where it stands, and submit
 * documents for an administrator to review.
 *
 * unverified / rejected ──submit──▶ pending (new request)
 * additional_info_required ──submit──▶ pending (same request, more documents)
 *
 * Files go to the private `verification` disk; administrators open them
 * through the authorized download in the Providers module.
 */
class ProviderVerificationService extends BaseService
{
    private const SUBMITTABLE = ['unverified', 'rejected', 'additional_info_required'];

    /** @return array{profile: ProviderProfile, request: ?VerificationRequest} */
    public function show(ProviderProfile $profile): array
    {
        return [
            'profile' => $profile->fresh(),
            'request' => $profile->verificationRequests()->with('documents')->latest('id')->first(),
        ];
    }

    /**
     * @param  array<int, array{type: string, file: UploadedFile}>  $documents
     * @return array{profile: ProviderProfile, request: ?VerificationRequest}
     */
    public function submit(ProviderProfile $profile, User $actor, array $documents, ?string $notes): array
    {
        if (! in_array($profile->verification_status, self::SUBMITTABLE, true)) {
            throw new ApiException(
                $profile->verification_status === 'pending'
                    ? 'Your documents are already waiting for review.'
                    : 'Your account is already verified.',
                422,
                errors: ['verification_status' => ['This account is '.$profile->verification_status.'.']],
            );
        }

        // Write the files before the row lock, so storage I/O never holds it;
        // they are removed again if the database refuses the submission.
        $stored = [];
        foreach ($documents as $document) {
            $file = $document['file'];
            $path = $file->storeAs(
                "verification/{$profile->id}",
                Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: $file->extension()),
                ['disk' => 'verification'],
            );

            if ($path === false) {
                $this->deleteFiles($stored);
                throw new ApiException('A document could not be saved. Please try again.', 500);
            }

            $stored[] = ['type' => $document['type'], 'file' => $file, 'path' => $path];
        }

        try {
            [$request, $isResponse] = $this->transaction(function () use ($profile, $notes, $stored): array {
                $profile = ProviderProfile::query()->lockForUpdate()->findOrFail($profile->id);

                if (! in_array($profile->verification_status, self::SUBMITTABLE, true)) {
                    throw new ApiException('Your documents are already waiting for review.', 422);
                }

                $latest = $profile->verificationRequests()->latest('id')->first();
                $isResponse = $latest?->status === 'additional_info_required';

                $request = $isResponse
                    ? tap($latest)->update([
                        'status' => 'pending',
                        'notes' => trim(($latest->notes ? $latest->notes."\n\n" : '').($notes ?? '')) ?: $latest->notes,
                        'submitted_at' => now(),
                    ])
                    : $profile->verificationRequests()->create([
                        'status' => 'pending',
                        'notes' => $notes,
                        'submitted_at' => now(),
                    ]);

                foreach ($stored as $item) {
                    $request->documents()->create([
                        'document_type' => $item['type'],
                        'file_name' => $item['file']->getClientOriginalName(),
                        'file_path' => $item['path'],
                        'file_mime_type' => $item['file']->getMimeType() ?? 'application/octet-stream',
                        'file_size' => $item['file']->getSize(),
                    ]);
                }

                $profile->update(['verification_status' => 'pending', 'rejection_reason' => null]);

                return [$request, $isResponse];
            });
        } catch (\Throwable $exception) {
            $this->deleteFiles($stored);
            throw $exception;
        }

        event(new ProviderVerificationSubmitted(
            providerProfile: $profile->fresh(),
            actor: $actor,
            request: $request,
            documentCount: count($stored),
            isResponse: $isResponse,
        ));

        return $this->show($profile);
    }

    /** @param  array<int, array{path: string}>  $stored */
    private function deleteFiles(array $stored): void
    {
        foreach ($stored as $item) {
            Storage::disk('verification')->delete($item['path']);
        }
    }
}

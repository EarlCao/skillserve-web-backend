<?php

namespace App\Modules\ClientMarketplace\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\DisputeService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Raising a dispute on a booking, from either side.
 *
 * This is the entry point the administrative dispute workflow
 * ({@see DisputeService}) was waiting for: it
 * sets `dispute_reason` and opens the case at `dispute_status = pending`, which
 * is what makes the booking show up in the admin console's dispute queue.
 */
class BookingDisputeService extends BaseService
{
    /**
     * A dispute is about work: it can be raised once the job is under way or
     * finished. Nothing has happened yet on a pending or confirmed booking, and
     * a cancelled one has no work to argue about.
     */
    private const DISPUTABLE_STATUSES = ['active', 'completed'];

    /** Evidence is accepted while the case is still being worked. */
    private const OPEN_DISPUTE_STATUSES = ['pending', 'investigated'];

    /** Enough to make a case; not a photo album. */
    public const MAX_EVIDENCE = 5;

    public function raise(User $actor, Booking $booking, string $reason): Booking
    {
        return $this->transaction(function () use ($actor, $booking, $reason): Booking {
            $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($booking->dispute_reason !== null) {
                throw new ApiException(
                    'This booking is already under dispute.',
                    409,
                    errors: ['dispute' => ['A dispute has already been raised for this booking.']],
                );
            }

            if (! in_array($booking->status, self::DISPUTABLE_STATUSES, true)) {
                throw new ApiException(
                    'Only a job in progress or completed can be disputed.',
                    422,
                    errors: ['status' => ['This booking is '.$booking->status.' and cannot be disputed.']],
                );
            }

            $oldStatus = $booking->status;

            $booking->update([
                'status' => 'disputed',
                'dispute_reason' => trim($reason),
                'disputed_at' => now(),
                'dispute_status' => 'pending',
            ]);

            $booking = $booking->fresh()->load($this->relations());

            event(new BookingStatusChanged(
                booking: $booking,
                actor: $actor,
                oldStatus: $oldStatus,
                newStatus: 'disputed',
            ));

            return $booking;
        });
    }

    /**
     * Attach a photo to an open dispute, from either party.
     *
     * The file goes to private storage and the booking records only a pointer
     * to it; administrators open it through an authorized download, never a
     * public URL. The file is written before the row is locked, so the lock is
     * never held during storage I/O, and removed again if the row refuses it.
     *
     * @return array<string, mixed> the stored evidence item
     */
    public function addEvidence(User $actor, Booking $booking, UploadedFile $image, ?string $caption): array
    {
        $disk = Storage::disk('dispute_evidence');
        $path = $image->storeAs(
            (string) $booking->id,
            Str::uuid().'.'.$image->extension(),
            ['disk' => 'dispute_evidence'],
        );

        if ($path === false) {
            throw new ApiException('The image could not be saved. Please try again.', 500);
        }

        try {
            return $this->transaction(function () use ($actor, $booking, $image, $caption, $path): array {
                $booking = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

                if ($booking->dispute_reason === null
                    || ! in_array($booking->dispute_status, self::OPEN_DISPUTE_STATUSES, true)) {
                    throw new ApiException(
                        'Evidence can only be added while the dispute is open.',
                        422,
                        errors: ['dispute' => ['This booking has no open dispute.']],
                    );
                }

                $evidence = $booking->dispute_evidence ?? [];

                if (count($evidence) >= self::MAX_EVIDENCE) {
                    throw new ApiException(
                        'A dispute can hold up to '.self::MAX_EVIDENCE.' photos.',
                        422,
                        errors: ['image' => ['The evidence limit for this dispute has been reached.']],
                    );
                }

                $item = [
                    'id' => (string) Str::uuid(),
                    'label' => $caption ? trim($caption) : $image->getClientOriginalName(),
                    'content' => $caption ? trim($caption) : null,
                    'path' => $path,
                    'mime_type' => $image->getMimeType(),
                    'uploaded_by' => $actor->id,
                    'uploaded_by_role' => (int) $booking->client_id === (int) $actor->id ? 'customer' : 'provider',
                    'uploaded_at' => now()->toIso8601String(),
                ];

                $booking->update(['dispute_evidence' => [...$evidence, $item]]);

                return $item;
            });
        } catch (\Throwable $exception) {
            $disk->delete($path);

            throw $exception;
        }
    }

    /**
     * The disputes the account is party to, so the app can show their status
     * without exposing the administrators' internal notes.
     */
    public function index(User $actor, array $filters): LengthAwarePaginator
    {
        $providerProfileId = $actor->providerProfile?->id;

        return Booking::query()
            ->whereNotNull('dispute_reason')
            ->where(function ($query) use ($actor, $providerProfileId): void {
                $query->where('client_id', $actor->id);

                if ($providerProfileId) {
                    $query->orWhere('provider_id', $providerProfileId);
                }
            })
            ->when($filters['dispute_status'] ?? null, fn ($query, $status) => $query->where('dispute_status', $status))
            ->with($this->relations())
            ->orderByDesc('disputed_at')
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));
    }

    private function relations(): array
    {
        return [
            'service:id,provider_id,title',
            'provider:id,business_name',
        ];
    }
}

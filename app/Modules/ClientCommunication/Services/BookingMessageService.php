<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Events\ClientMessageCreated;
use App\Modules\ClientCommunication\Notifications\BookingMessageNotification;
use App\Modules\ClientCommunication\Policies\BookingMessagePolicy;
use App\Modules\ClientCommunication\Resources\BookingMessageResource;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\StoredFile;
use App\Shared\Services\BaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class BookingMessageService extends BaseService
{
    public function __construct(private readonly BookingMessagePolicy $policy) {}

    public function authorizeParticipant(User $user, Booking $booking): void
    {
        if (! $this->policy->viewAny($user, $booking)) {
            throw new ApiException('Only the booking client or provider may access these messages.', 403);
        }
    }

    public function index(User $user, Booking $booking): LengthAwarePaginator
    {
        $this->authorizeParticipant($user, $booking);

        $this->markThreadRead($user, $booking);

        return Message::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'active')
            ->with(['sender:id,name', 'receiver:id,name'])
            ->oldest()
            ->paginate(50);
    }

    /**
     * Marks what this participant received on the booking as read, without
     * returning the thread — for an app that already shows the message it was
     * pushed and only needs the read state to catch up.
     *
     * @return int how many messages were newly marked read
     */
    public function markRead(User $user, Booking $booking): int
    {
        $this->authorizeParticipant($user, $booking);

        return $this->markThreadRead($user, $booking);
    }

    private function markThreadRead(User $user, Booking $booking): int
    {
        return Message::query()
            ->where('booking_id', $booking->id)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function create(User $user, Booking $booking, ?string $content, ?string $idempotencyKey, ?UploadedFile $image = null): Message
    {
        $this->authorizeParticipant($user, $booking);
        $content = trim((string) $content);

        $receiverId = $booking->client_id === $user->id
            ? $booking->provider()->value('user_id')
            : $booking->client_id;

        if (! $receiverId) {
            throw new ApiException('The booking provider is not available for messaging.', 422);
        }

        // Stored before the transaction (no file I/O inside it) and removed
        // again if the message is not written.
        $imagePath = $image?->store((string) $booking->id, 'message_images');

        try {
            $message = $this->transaction(function () use ($booking, $user, $receiverId, $content, $idempotencyKey, $imagePath): Message {
                if ($idempotencyKey) {
                    $existing = Message::query()
                        ->where('booking_id', $booking->id)
                        ->where('sender_id', $user->id)
                        ->where('client_idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $this->assertSameIdempotentRequest($existing, $receiverId, $content);

                        return $existing->load(['sender:id,name', 'receiver:id,name']);
                    }
                }

                return Message::query()->create([
                    'booking_id' => $booking->id,
                    'sender_id' => $user->id,
                    'receiver_id' => $receiverId,
                    'content' => $content,
                    'image_path' => $imagePath,
                    'status' => 'active',
                    'client_idempotency_key' => $idempotencyKey,
                ])->load(['sender:id,name', 'receiver:id,name']);
            });

            // A replayed request returns the first message; this upload is spare.
            if ($imagePath && $message->image_path !== $imagePath) {
                Storage::disk('message_images')->delete($imagePath);
            }

            $this->deliver($message);

            return $message;
        } catch (QueryException $exception) {
            if ($imagePath) {
                Storage::disk('message_images')->delete($imagePath);
            }

            if (! $idempotencyKey) {
                throw $exception;
            }

            $existing = Message::query()
                ->where('booking_id', $booking->id)
                ->where('sender_id', $user->id)
                ->where('client_idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $this->assertSameIdempotentRequest($existing, $receiverId, $content);

            return $existing->load(['sender:id,name', 'receiver:id,name']);
        }
    }

    /**
     * Push a freshly written message to its receiver: a realtime event so an
     * open chat updates at once, and a notification for the feed and banner.
     *
     * Runs after the transaction commits, so a slow or failing queue write can
     * never roll back a message the sender already saw accepted. A replayed
     * idempotency key delivers nothing, because nothing was written.
     */
    private function deliver(Message $message): void
    {
        if (! $message->wasRecentlyCreated) {
            return;
        }

        try {
            event(new ClientMessageCreated(
                receiverId: (int) $message->receiver_id,
                bookingId: (int) $message->booking_id,
                message: (new BookingMessageResource($message))->resolve(),
            ));
        } catch (Throwable $exception) {
            // The message is stored; the other side sees it on its next fetch.
            Log::warning('A chat message could not be pushed live.', ['message_id' => $message->id, 'error' => $exception->getMessage()]);
        }

        // Load the whole account rather than use the eager-loaded relation:
        // messages load their parties as id+name only, which would hide
        // role_id and make the realtime push skip a genuine mobile account.
        User::query()->find($message->receiver_id)?->notify(new BookingMessageNotification(
            $message,
            $message->sender?->name ?? 'Someone',
        ));
    }

    /**
     * The photo of a message in [booking], for one of its two parties. A
     * message from another booking, removed by a moderator or without a photo
     * is reported as missing.
     */
    public function image(User $user, Booking $booking, Message $message): StreamedResponse
    {
        $this->authorizeParticipant($user, $booking);
        abort_unless((int) $message->booking_id === (int) $booking->id && $message->isActive() && $message->hasImage(), 404);

        return self::streamImage($message);
    }

    /** Streams a message's photo from private storage. */
    public static function streamImage(Message $message): StreamedResponse
    {
        $disk = Storage::disk('message_images');
        StoredFile::ensureExists($disk, $message->image_path, 'This photo is no longer stored on the server.');

        return $disk->response($message->image_path, basename($message->image_path), [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function assertSameIdempotentRequest(Message $message, int $receiverId, string $content): void
    {
        if ((int) $message->receiver_id !== $receiverId || $message->content !== trim($content)) {
            throw new ApiException(
                'This idempotency key was already used for a different message request.',
                409,
            );
        }
    }
}

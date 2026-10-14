<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\ClientCommunication\Events\ClientMessageCreated;
use App\Modules\ClientCommunication\Notifications\BookingMessageNotification;
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

/**
 * The mechanics every chat thread shares — a booking's or an inquiry's:
 * reading it, read state, idempotent sends with an optional photo, and live
 * delivery. A thread is addressed by its column on `messages` (`booking_id`
 * or `inquiry_id`) and its id; callers authorise the participant first.
 */
class ChatMessageService extends BaseService
{
    public const THREAD_COLUMNS = ['booking_id', 'inquiry_id'];

    /** The thread, oldest first; reading it marks what [user] received as read. */
    public function list(User $user, string $column, int $threadId): LengthAwarePaginator
    {
        $this->markRead($user, $column, $threadId);

        return Message::query()
            ->where($column, $threadId)
            ->where('status', 'active')
            ->with(['sender:id,name', 'receiver:id,name'])
            ->oldest()
            ->paginate(50);
    }

    /** @return int how many messages were newly marked read */
    public function markRead(User $user, string $column, int $threadId): int
    {
        return Message::query()
            ->where($column, $threadId)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Writes a message — text, a photo, or both — to [receiverId]. A repeated
     * [idempotencyKey] returns the message already stored instead of a copy.
     */
    public function create(User $sender, string $column, int $threadId, int $receiverId, ?string $content, ?string $idempotencyKey, ?UploadedFile $image = null): Message
    {
        $this->assertThreadColumn($column);
        $content = trim((string) $content);

        // Stored before the transaction (no file I/O inside it) and removed
        // again if the message is not written.
        $folder = $column === 'booking_id' ? (string) $threadId : "inquiries/{$threadId}";
        $imagePath = $image?->store($folder, 'message_images');

        try {
            $message = $this->transaction(function () use ($sender, $column, $threadId, $receiverId, $content, $idempotencyKey, $imagePath): Message {
                if ($idempotencyKey) {
                    $existing = $this->byKey($sender, $column, $threadId, $idempotencyKey)?->lockForUpdate()->first();

                    if ($existing) {
                        $this->assertSameIdempotentRequest($existing, $receiverId, $content);

                        return $existing->load(['sender:id,name', 'receiver:id,name']);
                    }
                }

                return Message::query()->create([
                    $column => $threadId,
                    'sender_id' => $sender->id,
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

            $existing = $idempotencyKey ? $this->byKey($sender, $column, $threadId, $idempotencyKey)?->first() : null;

            if (! $existing) {
                throw $exception;
            }

            $this->assertSameIdempotentRequest($existing, $receiverId, $content);

            return $existing->load(['sender:id,name', 'receiver:id,name']);
        }
    }

    /** A message's photo, when it belongs to this thread and is still shown. */
    public function image(string $column, int $threadId, Message $message): StreamedResponse
    {
        abort_unless((int) $message->{$column} === $threadId && $message->isActive() && $message->hasImage(), 404);

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
                bookingId: $message->booking_id ? (int) $message->booking_id : null,
                message: (new BookingMessageResource($message))->resolve(),
                inquiryId: $message->inquiry_id ? (int) $message->inquiry_id : null,
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

    private function byKey(User $sender, string $column, int $threadId, string $key)
    {
        return Message::query()
            ->where($column, $threadId)
            ->where('sender_id', $sender->id)
            ->where('client_idempotency_key', $key);
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

    private function assertThreadColumn(string $column): void
    {
        if (! in_array($column, self::THREAD_COLUMNS, true)) {
            throw new \InvalidArgumentException("Unknown chat thread column [{$column}].");
        }
    }
}

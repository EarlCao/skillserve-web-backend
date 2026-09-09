<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Policies\BookingMessagePolicy;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

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

        Message::query()
            ->where('booking_id', $booking->id)
            ->where('receiver_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return Message::query()
            ->where('booking_id', $booking->id)
            ->where('status', 'active')
            ->with(['sender:id,name', 'receiver:id,name'])
            ->oldest()
            ->paginate(50);
    }

    public function create(User $user, Booking $booking, string $content, ?string $idempotencyKey): Message
    {
        $this->authorizeParticipant($user, $booking);

        $receiverId = $booking->client_id === $user->id
            ? $booking->provider()->value('user_id')
            : $booking->client_id;

        if (! $receiverId) {
            throw new ApiException('The booking provider is not available for messaging.', 422);
        }

        try {
            return $this->transaction(function () use ($booking, $user, $receiverId, $content, $idempotencyKey): Message {
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
                    'content' => trim($content),
                    'status' => 'active',
                    'client_idempotency_key' => $idempotencyKey,
                ])->load(['sender:id,name', 'receiver:id,name']);
            });
        } catch (QueryException $exception) {
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

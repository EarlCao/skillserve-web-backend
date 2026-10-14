<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Policies\BookingMessagePolicy;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A booking's chat: only its customer and provider may read or write it. The
 * thread mechanics are shared with inquiries ({@see ChatMessageService}).
 */
class BookingMessageService extends BaseService
{
    public function __construct(
        private readonly BookingMessagePolicy $policy,
        private readonly ChatMessageService $chat,
    ) {}

    public function authorizeParticipant(User $user, Booking $booking): void
    {
        if (! $this->policy->viewAny($user, $booking)) {
            throw new ApiException('Only the booking client or provider may access these messages.', 403);
        }
    }

    public function index(User $user, Booking $booking): LengthAwarePaginator
    {
        $this->authorizeParticipant($user, $booking);

        return $this->chat->list($user, 'booking_id', $booking->id);
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

        return $this->chat->markRead($user, 'booking_id', $booking->id);
    }

    public function create(User $user, Booking $booking, ?string $content, ?string $idempotencyKey, ?UploadedFile $image = null): Message
    {
        $this->authorizeParticipant($user, $booking);

        $receiverId = $booking->client_id === $user->id
            ? $booking->provider()->value('user_id')
            : $booking->client_id;

        if (! $receiverId) {
            throw new ApiException('The booking provider is not available for messaging.', 422);
        }

        return $this->chat->create($user, 'booking_id', $booking->id, (int) $receiverId, $content, $idempotencyKey, $image);
    }

    /**
     * The photo of a message in [booking], for one of its two parties. A
     * message from another booking, removed by a moderator or without a photo
     * is reported as missing.
     */
    public function image(User $user, Booking $booking, Message $message): StreamedResponse
    {
        $this->authorizeParticipant($user, $booking);

        return $this->chat->image('booking_id', $booking->id, $message);
    }
}

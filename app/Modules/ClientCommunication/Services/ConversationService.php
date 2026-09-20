<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The inbox behind the app's Messages tab.
 *
 * A booking *is* the conversation, so this lists the bookings the signed-in
 * account can talk on that someone has actually written on, newest first,
 * each with its counterpart, last message and unread count.
 *
 * Reading the inbox never marks anything read — only opening the thread does
 * ({@see BookingMessageService::index()}).
 */
class ConversationService extends BaseService
{
    public function index(User $user, array $filters): LengthAwarePaginator
    {
        // A provider's bookings hang off their profile; a customer's off their
        // user id. An account can be one or the other, never both.
        $providerProfileId = $user->providerProfile?->id;

        return Booking::query()
            ->where(function ($query) use ($user, $providerProfileId): void {
                $query->where('client_id', $user->id);

                if ($providerProfileId) {
                    $query->orWhere('provider_id', $providerProfileId);
                }
            })
            ->whereHas('messages', fn ($query) => $query->where('status', 'active'))
            ->withCount(['messages as unread_count' => fn ($query) => $query
                ->where('status', 'active')
                ->where('receiver_id', $user->id)
                ->whereNull('read_at')])
            ->withMax(['messages as last_message_at' => fn ($query) => $query
                ->where('status', 'active')], 'created_at')
            ->with([
                // No column list: ofMany() joins the messages table to itself,
                // where an unqualified select is ambiguous.
                'latestMessage',
                'service:id,title',
                'client:id,name,profile_photo_path',
                'provider:id,user_id,business_name',
                'provider.user:id,name,profile_photo_path',
            ])
            ->orderByDesc('last_message_at')
            ->paginate($this->perPage($filters));
    }

    /** Every unread message addressed to this account, for the tab badge. */
    public function unreadCount(User $user): int
    {
        return Message::query()
            ->where('receiver_id', $user->id)
            ->where('status', 'active')
            ->whereNull('read_at')
            ->count();
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 20)));
    }
}

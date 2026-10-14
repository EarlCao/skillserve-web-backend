<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * The inbox behind the app's Messages tab.
 *
 * Lists the threads the signed-in account can talk on that someone has
 * actually written in, newest activity first, each with its counterpart, last
 * message and unread count. A thread is a booking's chat or — when the app
 * asks for them with `include_inquiries` — an inquiry, a customer's
 * conversation with a provider before booking.
 *
 * Reading the inbox never marks anything read — only opening the thread does
 * ({@see ChatMessageService::list()}).
 */
class ConversationService extends BaseService
{
    /** What an inquiry row needs to render in the inbox. */
    public const INQUIRY_RELATIONS = [
        'latestMessage',
        'customer:id,name,profile_photo_path',
        'provider:id,user_id,business_name',
        'provider.user:id,name,profile_photo_path',
    ];

    public function index(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = $this->perPage($filters);

        if (! ($filters['include_inquiries'] ?? false)) {
            return $this->bookingThreads($user)->orderByDesc('last_message_at')->paginate($perPage);
        }

        // Two kinds of thread in one list, so they are merged in memory. An
        // account's conversations number in the tens, which this handles
        // comfortably; a later page is cut from the same sorted list.
        $threads = $this->bookingThreads($user)->get()
            ->concat($this->inquiryThreads($user)->get())
            ->sortByDesc(fn ($thread) => $thread->latestMessage?->created_at?->getTimestamp() ?? 0)
            ->values();
        $page = Paginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $threads->forPage($page, $perPage)->values(),
            $threads->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
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

    /** Bookings the account is a party to that carry at least one message. */
    private function bookingThreads(User $user): Builder
    {
        // A provider's bookings hang off their profile; a customer's off their
        // user id. An account can be one or the other, never both.
        $providerProfileId = $user->providerProfile?->id;

        return $this->withThreadStats(Booking::query(), $user)
            ->where(function ($query) use ($user, $providerProfileId): void {
                $query->where('client_id', $user->id);

                if ($providerProfileId) {
                    $query->orWhere('provider_id', $providerProfileId);
                }
            })
            ->with([
                // No column list: ofMany() joins the messages table to itself,
                // where an unqualified select is ambiguous.
                'latestMessage',
                'service:id,title',
                'client:id,name,profile_photo_path',
                'provider:id,user_id,business_name',
                'provider.user:id,name,profile_photo_path',
            ]);
    }

    /** Inquiries the account is a party to that carry at least one message. */
    private function inquiryThreads(User $user): Builder
    {
        $providerProfileId = $user->providerProfile?->id;

        return $this->withThreadStats(Inquiry::query(), $user)
            ->where(function ($query) use ($user, $providerProfileId): void {
                $query->where('customer_id', $user->id);

                if ($providerProfileId) {
                    $query->orWhere('provider_profile_id', $providerProfileId);
                }
            })
            ->with(self::INQUIRY_RELATIONS);
    }

    private function withThreadStats(Builder $query, User $user): Builder
    {
        return $query
            ->whereHas('messages', fn ($messages) => $messages->where('status', 'active'))
            ->withCount(['messages as unread_count' => fn ($messages) => $messages
                ->where('status', 'active')
                ->where('receiver_id', $user->id)
                ->whereNull('read_at')])
            ->withMax(['messages as last_message_at' => fn ($messages) => $messages
                ->where('status', 'active')], 'created_at');
    }

    private function perPage(array $filters): int
    {
        return max(1, min(100, (int) ($filters['per_page'] ?? 20)));
    }
}

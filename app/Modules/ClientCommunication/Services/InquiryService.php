<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ClientCommunication\Policies\InquiryPolicy;
use App\Modules\ClientMarketplace\Services\ClientCatalogService;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat before booking: a customer asks a provider about a job before
 * booking it. The customer starts the conversation (one per provider);
 * after that, either side writes in it like in a booking's chat.
 */
class InquiryService extends BaseService
{
    public function __construct(
        private readonly InquiryPolicy $policy,
        private readonly ChatMessageService $chat,
        private readonly ClientCatalogService $catalog,
    ) {}

    /**
     * The customer's conversation with [providerId], started if there is none
     * yet. Only a provider the marketplace shows can be messaged, so a hidden,
     * unverified or suspended provider is reported as missing.
     */
    public function start(User $customer, int $providerId): Inquiry
    {
        $provider = $this->catalog->publicProvidersQuery()->whereKey($providerId)->first()
            ?? throw new ApiException('Provider not found.', 404);

        if ((int) $provider->user_id === (int) $customer->id) {
            throw new ApiException('You cannot message yourself.', 422, errors: ['provider_id' => ['You cannot message your own profile.']]);
        }

        $attributes = ['customer_id' => $customer->id, 'provider_profile_id' => $provider->id];

        try {
            $inquiry = Inquiry::query()->firstOrCreate($attributes);
        } catch (QueryException $exception) {
            // Two taps racing past the lookup meet the unique index; the
            // loser gets the conversation the winner made.
            $inquiry = Inquiry::query()->where($attributes)->first() ?? throw $exception;
        }

        return $this->forInbox($inquiry);
    }

    public function messages(User $user, Inquiry $inquiry): LengthAwarePaginator
    {
        $this->authorizeParticipant($user, $inquiry);

        return $this->chat->list($user, 'inquiry_id', $inquiry->id);
    }

    /** @return int how many messages were newly marked read */
    public function markRead(User $user, Inquiry $inquiry): int
    {
        $this->authorizeParticipant($user, $inquiry);

        return $this->chat->markRead($user, 'inquiry_id', $inquiry->id);
    }

    public function send(User $user, Inquiry $inquiry, ?string $content, ?string $idempotencyKey, ?UploadedFile $image = null): Message
    {
        $this->authorizeParticipant($user, $inquiry);

        $receiverId = (int) $inquiry->customer_id === (int) $user->id
            ? $inquiry->provider()->value('user_id')
            : $inquiry->customer_id;

        if (! $receiverId) {
            throw new ApiException('This provider is not available for messaging.', 422);
        }

        return $this->chat->create($user, 'inquiry_id', $inquiry->id, (int) $receiverId, $content, $idempotencyKey, $image);
    }

    public function image(User $user, Inquiry $inquiry, Message $message): StreamedResponse
    {
        $this->authorizeParticipant($user, $inquiry);

        return $this->chat->image('inquiry_id', $inquiry->id, $message);
    }

    public function authorizeParticipant(User $user, Inquiry $inquiry): void
    {
        if (! $this->policy->viewAny($user, $inquiry)) {
            throw new ApiException('Only the customer and the provider may access this conversation.', 403);
        }
    }

    /** What the Messages inbox shows for this conversation. */
    private function forInbox(Inquiry $inquiry): Inquiry
    {
        return $inquiry->load(ConversationService::INQUIRY_RELATIONS)->loadCount([
            'messages as unread_count' => fn ($query) => $query
                ->where('status', 'active')
                ->where('receiver_id', $inquiry->customer_id)
                ->whereNull('read_at'),
        ]);
    }
}

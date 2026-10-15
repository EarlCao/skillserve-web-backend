<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\ClientMarketplace\Resources\ProviderStandingPresenter;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Resources\BaseResource;

/**
 * One thread as it appears in the Messages tab: a booking's chat, or an
 * inquiry (a customer's conversation with a provider before booking).
 *
 * `counterpart` is resolved against the signed-in account, so each side sees
 * the other: a customer sees the provider's business name, a provider sees the
 * customer's name.
 */
class ConversationResource extends BaseResource
{
    public function toArray($request): array
    {
        $viewer = $request->user();
        $isInquiry = $this->resource instanceof Inquiry;
        $customer = $isInquiry ? $this->customer : $this->client;
        $isCustomer = (int) ($isInquiry ? $this->customer_id : $this->client_id) === (int) $viewer?->id;

        return [
            'kind' => $isInquiry ? 'inquiry' : 'booking',
            // The thread's address: /bookings/{id}/messages or /inquiries/{id}/messages.
            'booking_id' => $isInquiry ? null : $this->id,
            'inquiry_id' => $isInquiry ? $this->id : null,
            'booking_number' => $isInquiry ? null : $this->booking_number,
            'booking_status' => $isInquiry ? null : $this->status,
            'service_title' => $isInquiry ? null : $this->service?->title,
            'counterpart' => $isCustomer ? $this->providerSide($this->provider, $isInquiry ? null : $this->resource) : $this->clientSide($customer),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'id' => $this->latestMessage->id,
                // "📷 Photo" for a photo sent without a caption.
                'content' => $this->latestMessage->preview(),
                'has_image' => $this->latestMessage->hasImage(),
                'created_at' => $this->latestMessage->created_at?->toIso8601String(),
                'read_at' => $this->latestMessage->read_at?->toIso8601String(),
                // Lets the list show "You: …" without knowing the viewer's id.
                'is_mine' => (int) $this->latestMessage->sender_id === (int) $viewer?->id,
            ] : null),
            'unread_count' => (int) ($this->unread_count ?? 0),
            // The same instant the query sorts on, taken from the cast
            // relation rather than the raw aggregate string.
            'last_message_at' => $this->latestMessage?->created_at?->toIso8601String(),
        ];
    }

    /**
     * The provider, as the customer sees them — with a notice once an
     * administrator suspended, banned or deleted them.
     */
    private function providerSide(?ProviderProfile $provider, ?Booking $booking): ?array
    {
        if (! $provider) {
            return null;
        }

        return [
            'id' => $provider->user_id,
            // Their public profile, so the customer can open it from the chat.
            'provider_id' => $provider->id,
            'name' => $provider->displayName(),
            'profile_picture' => ClientProfileService::photoUrl($provider->user?->profile_photo_path),
            ...ProviderStandingPresenter::present($provider, $booking),
        ];
    }

    /** The customer, as the provider sees them. */
    private function clientSide(?User $customer): ?array
    {
        return $customer ? [
            'id' => $customer->id,
            'name' => $customer->name,
            'profile_picture' => ClientProfileService::photoUrl($customer->profile_photo_path),
        ] : null;
    }
}

<?php

namespace App\Modules\ClientCommunication\Resources;

use App\Modules\ClientAuthentication\Services\ClientProfileService;
use App\Shared\Resources\BaseResource;

/**
 * One booking thread as it appears in the Messages tab.
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
        $isClient = (int) $this->client_id === (int) $viewer?->id;

        return [
            // The booking is the conversation, so its id addresses the thread.
            'booking_id' => $this->id,
            'booking_number' => $this->booking_number,
            'booking_status' => $this->status,
            'service_title' => $this->service?->title,
            'counterpart' => $isClient ? $this->providerSide() : $this->clientSide(),
            'last_message' => $this->whenLoaded('latestMessage', fn () => $this->latestMessage ? [
                'id' => $this->latestMessage->id,
                'content' => $this->latestMessage->content,
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

    /** The provider, as the customer on the booking sees them. */
    private function providerSide(): ?array
    {
        $provider = $this->provider;

        if (! $provider) {
            return null;
        }

        return [
            'id' => $provider->user_id,
            'name' => $provider->business_name ?: $provider->user?->name,
            'profile_picture' => ClientProfileService::photoUrl($provider->user?->profile_photo_path),
        ];
    }

    /** The customer, as the provider on the booking sees them. */
    private function clientSide(): ?array
    {
        return $this->client ? [
            'id' => $this->client->id,
            'name' => $this->client->name,
            'profile_picture' => ClientProfileService::photoUrl($this->client->profile_photo_path),
        ] : null;
    }
}

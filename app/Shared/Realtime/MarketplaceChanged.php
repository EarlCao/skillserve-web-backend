<?php

namespace App\Shared\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells open mobile apps that the public catalog changed — a service was
 * published, edited or hidden, a provider's profile or rating moved, a
 * category was renamed — so Home, Explore and the profile pages refetch.
 *
 * A public channel, because the catalog itself is public: guests browse it
 * too. It therefore carries resource names only, never record data; the app
 * reads what changed through the same public endpoints as before.
 *
 * Broadcast immediately (not queued), like {@see AdminDataChanged}.
 */
class MarketplaceChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CHANNEL = 'marketplace';

    /**
     * @param  array<int, string>  $resources
     */
    public function __construct(public readonly array $resources) {}

    public function broadcastOn(): array
    {
        return [new Channel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'marketplace.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['resources' => $this->resources];
    }
}

<?php

namespace App\Shared\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells connected admin dashboards which kinds of data changed so they can
 * refetch the affected lists. Carries resource names only, never record data.
 *
 * Broadcast immediately (not queued): production runs no queue worker.
 */
class AdminDataChanged implements ShouldBroadcastNow
{
    use Dispatchable;

    public const CHANNEL = 'admin.data';

    /**
     * @param  array<int, string>  $resources
     */
    public function __construct(public readonly array $resources) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::CHANNEL)];
    }

    public function broadcastAs(): string
    {
        return 'admin.data.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['resources' => $this->resources];
    }
}

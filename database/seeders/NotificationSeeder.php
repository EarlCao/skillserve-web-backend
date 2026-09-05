<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Notifications\Models\Announcement;
use App\Modules\Notifications\Notifications\AnnouncementNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class NotificationSeeder extends Seeder
{
    public function run(): void
    {
        $recipients = User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles')
            ->whereIn('user_type', ['customer', 'provider'])
            ->pluck('id')
            ->values()
            ->all();

        $this->seedSentAnnouncement(
            title: 'Welcome to SkillServe',
            message: 'Thank you for joining SkillServe. Explore services and connect with trusted professionals.',
            target: 'all',
            recipientIds: $recipients,
        );

        $customerIds = User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles')
            ->where('user_type', 'customer')
            ->pluck('id')
            ->values()
            ->all();

        $this->seedSentAnnouncement(
            title: 'New services are available',
            message: 'Discover new services from verified providers in the SkillServe marketplace.',
            target: 'customers',
            recipientIds: $customerIds,
        );

        $providerIds = User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles')
            ->where('user_type', 'provider')
            ->pluck('id')
            ->values()
            ->all();

        Announcement::query()->firstOrCreate(
            ['title' => 'Provider profile reminder'],
            [
                'created_by' => $this->administratorId(),
                'message' => 'Keep your provider profile and service information up to date.',
                'target' => 'providers',
                'recipient_ids' => $providerIds,
                'recipient_count' => count($providerIds),
                'status' => 'scheduled',
                'scheduled_at' => Carbon::now()->addDays(7),
            ],
        );
    }

    /**
     * Create a sent history record and its database notifications once.
     *
     * @param  array<int, int>  $recipientIds
     */
    private function seedSentAnnouncement(string $title, string $message, string $target, array $recipientIds): void
    {
        $announcement = Announcement::query()->firstOrCreate(
            ['title' => $title],
            [
                'created_by' => $this->administratorId(),
                'message' => $message,
                'target' => $target,
                'recipient_ids' => $recipientIds,
                'recipient_count' => count($recipientIds),
                'status' => 'sent',
                'sent_at' => Carbon::now()->subDay(),
            ],
        );

        if (! $announcement->wasRecentlyCreated) {
            return;
        }

        User::query()
            ->whereIn('id', $recipientIds)
            ->chunkById(250, fn ($users) => Notification::send($users, new AnnouncementNotification($announcement)));
    }

    private function administratorId(): ?int
    {
        return User::query()->whereHas('roles')->orderBy('id')->value('id');
    }
}

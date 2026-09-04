<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic set of reports so the Reports and Moderation screens
 * have data to list, filter, investigate, resolve, reject, and moderate.
 *
 * The reviews table has no seeder of its own yet, so this seeder also tops up
 * a small set of reviews and messages to serve as report targets. Idempotent —
 * re-running only tops up what is missing.
 */
class ReportSeeder extends Seeder
{
    use WithoutModelEvents;

    private const TARGET_REPORTS = 30;

    private const REASONS = [
        'spam', 'harassment', 'inappropriate_content', 'fraud', 'misleading', 'offensive', 'other',
    ];

    public function run(): void
    {
        $this->seedMessages();
        $this->seedReviews();

        $existing = Report::count();
        $missing = max(0, self::TARGET_REPORTS - $existing);

        if ($missing === 0) {
            $this->command?->info("Already {$existing} reports — nothing to seed.");

            return;
        }

        $reporters = User::query()->where('user_type', 'customer')->doesntHave('roles')->get();
        $users = User::query()->where('user_type', 'customer')->doesntHave('roles')->get();
        $services = Service::query()->where('approval_status', 'approved')->get();
        $reviews = Review::query()->get();
        $messages = Message::query()->get();

        if ($reporters->isEmpty() || $users->isEmpty() || $services->isEmpty()) {
            $this->command?->warn('Missing seed data (users/services) — skipping report seeding.');

            return;
        }

        $created = 0;

        for ($i = 0; $i < $missing; $i++) {
            $type = $this->pickType($i, $reviews, $messages);
            $target = match ($type) {
                'user' => $users->random(),
                'service' => $services->random(),
                'review' => $reviews->isEmpty() ? $users->random() : $reviews->random(),
                'message' => $messages->isEmpty() ? $users->random() : $messages->random(),
                default => $users->random(),
            };

            $status = $this->pickStatus($i);
            $reviewed = in_array($status, ['investigating', 'resolved', 'rejected'], true);
            $actionTaken = $status === 'resolved' && $this->hasApplicableAction($type);

            Report::create([
                'reportable_type' => $target->getMorphClass(),
                'reportable_id' => $target->id,
                'reporter_id' => $reporters->random()->id,
                'reason' => fake()->randomElement(self::REASONS),
                'description' => fake()->boolean(70) ? fake()->sentence(8) : null,
                'status' => $status,
                'investigation_notes' => $reviewed ? [$this->note()] : null,
                'investigated_by' => $reviewed ? $this->adminId() : null,
                'investigated_at' => $reviewed ? now()->subDays(rand(1, 5)) : null,
                'resolved_by' => $status === 'resolved' ? $this->adminId() : null,
                'resolved_at' => $status === 'resolved' ? now()->subDays(rand(0, 3)) : null,
                'resolution_note' => $status === 'resolved' ? 'The appropriate action has been completed for this report.' : null,
                'rejected_by' => $status === 'rejected' ? $this->adminId() : null,
                'rejected_at' => $status === 'rejected' ? now()->subDays(rand(0, 3)) : null,
                'reject_reason' => $status === 'rejected' ? 'No violation was found during review.' : null,
                'moderation_action' => $actionTaken ? $this->pickAction($type) : null,
                'action_taken_by' => $actionTaken ? $this->adminId() : null,
                'action_taken_at' => $actionTaken ? now()->subDays(rand(0, 3)) : null,
                'action_note' => $actionTaken ? 'Action applied by an administrator after review.' : null,
                'created_at' => now()->subDays(rand(1, 20)),
                'updated_at' => now()->subDays(rand(0, 5)),
            ]);

            $created++;
        }

        $this->command?->info("Seeded {$created} reports — total: ".Report::count().'.');
    }

    /**
     * A spread of statuses across the report lifecycle.
     */
    private function pickStatus(int $index): string
    {
        return match ($index % 10) {
            0, 1, 2 => 'pending',
            3, 4, 5 => 'investigating',
            6, 7, 8 => 'resolved',
            default => 'rejected',
        };
    }

    /**
     * Spread reports across all available types (review/message are skipped
     * when their target tables are empty).
     */
    private function pickType(int $index, $reviews, $messages): string
    {
        $types = ['user', 'service', 'review', 'message'];

        if ($reviews->isEmpty()) {
            unset($types[array_search('review', $types, true)]);
        }

        if ($messages->isEmpty()) {
            unset($types[array_search('message', $types, true)]);
        }

        $types = array_values($types);

        return $types[$index % count($types)];
    }

    private function pickAction(string $type): string
    {
        return match ($type) {
            'user' => fake()->randomElement(['warning', 'suspend', 'ban']),
            'service' => 'hide',
            'review' => fake()->randomElement(['hide', 'remove']),
            'message' => 'remove',
            default => 'warning',
        };
    }

    private function hasApplicableAction(string $type): bool
    {
        return in_array($type, ['user', 'service', 'review', 'message'], true);
    }

    /**
     * @return array{note: string, created_at: string, created_by: int|null}
     */
    private function note(): array
    {
        return [
            'note' => 'Initial review completed by an administrator.',
            'created_at' => now()->subDays(rand(0, 4))->toIso8601String(),
            'created_by' => $this->adminId(),
        ];
    }

    private function adminId(): ?int
    {
        return User::query()->whereHas('roles')->value('id');
    }

    /**
     * Create a small set of messages when the table is empty so message
     * reports have real targets.
     */
    private function seedMessages(): void
    {
        if (Message::count() > 0) {
            return;
        }

        $customers = User::query()->where('user_type', 'customer')->doesntHave('roles')->get();
        $providers = ProviderProfile::query()->with('user:id')->get()->pluck('user')->filter();

        if ($customers->isEmpty() || $providers->isEmpty()) {
            return;
        }

        $samples = [
            'I can do the job for half the price if you pay me directly outside the app.',
            'You should really watch out for this client — they never pay on time.',
            'This provider is a scam, do not book them!',
            'Are you free this weekend for an extra session?',
            'Thanks for the great service, I will recommend you to friends.',
            'Please cancel my booking — I found another provider.',
        ];

        foreach ($samples as $content) {
            Message::create([
                'sender_id' => $customers->random()->id,
                'receiver_id' => $providers->random()->id,
                'content' => $content,
                'status' => 'active',
                'created_at' => now()->subDays(rand(1, 15)),
                'updated_at' => now()->subDays(rand(0, 5)),
            ]);
        }
    }

    /**
     * Create a small set of reviews when the table is empty so review reports
     * have real targets. Reviews attach to completed, reviewed bookings.
     */
    private function seedReviews(): void
    {
        if (Review::count() > 0) {
            return;
        }

        $existingBookingIds = Review::query()->pluck('booking_id');

        $bookings = Booking::query()
            ->where('status', 'completed')
            ->where('is_reviewed', true)
            ->whereNotIn('id', $existingBookingIds)
            ->limit(8)
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $comments = [
            'Great work, would hire again.',
            'Terrible experience, showed up late.',
            'Excellent quality service!',
            'The provider was rude and unprofessional.',
            'Amazing value for the price.',
            'Did not finish the job properly.',
            'Very professional and courteous.',
            'Average service, nothing special.',
        ];

        foreach ($bookings as $index => $booking) {
            Review::create([
                'booking_id' => $booking->id,
                'reviewer_id' => $booking->client_id,
                'provider_id' => $booking->provider_id,
                'service_id' => $booking->service_id,
                'rating' => rand(1, 5),
                'comment' => $comments[$index % count($comments)],
                'status' => 'active',
                'is_reported' => false,
                'created_at' => $booking->completed_at ?? now(),
                'updated_at' => $booking->completed_at ?? now(),
            ]);
        }
    }
}

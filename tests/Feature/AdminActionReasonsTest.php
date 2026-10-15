<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientAuthentication\Events\ClientAccountRestricted;
use App\Modules\ProviderRecognition\Models\ProviderBadge;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Notifications\ProviderAccountNotification;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Notifications\MessageRemovedNotification;
use App\Modules\Reviews\Models\Review;
use App\Modules\Reviews\Notifications\ReviewModerationNotification;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Services\Notifications\ServiceModerationNotification;
use App\Modules\Users\Mail\UserDeletedMail;
use App\Modules\Users\Notifications\AccountStatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Every administrator action against someone says why, and the person is
 * told: deleting, hiding, removing a verification or a badge, cancelling.
 * Actions in someone's favour may carry a note, passed on the same way.
 */
class AdminActionReasonsTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Repeated no-shows reported by customers.';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
        Mail::fake();
        Event::fake([ClientAccountRestricted::class]);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole(Role::findOrCreate('super-admin'));
        $this->token = $admin->createToken('a')->plainTextToken;
    }

    public function test_deleting_a_customer_needs_a_reason_and_tells_them_by_email_and_in_the_app(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        $this->withToken($this->token)->deleteJson("/api/users/{$customer->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->withToken($this->token)->deleteJson("/api/users/{$customer->id}", ['reason' => self::REASON])->assertOk();

        $this->assertSame(self::REASON, User::withTrashed()->find($customer->id)->deletion_reason);
        Mail::assertSent(UserDeletedMail::class, fn (UserDeletedMail $mail) => $mail->hasTo($customer->email) && $mail->reason === self::REASON);
        Event::assertDispatched(ClientAccountRestricted::class, fn ($event) => $event->account['status'] === 'deleted' && $event->account['reason'] === self::REASON);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'description' => 'user_deleted']);
    }

    public function test_reactivating_a_customer_may_carry_a_note(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'suspended', 'suspended_at' => now()]);

        $this->withToken($this->token)->patchJson("/api/users/{$customer->id}/activate", ['reason' => 'Appeal accepted.'])->assertOk();

        Notification::assertSentTo($customer, AccountStatusNotification::class, fn ($n) => $n->toArray($customer)['reason'] === 'Appeal accepted.'
            && str_contains($n->toArray($customer)['message'], 'Note from SkillServe: Appeal accepted.'));
    }

    public function test_deleting_a_provider_and_removing_a_verification_need_a_reason_the_provider_sees(): void
    {
        [$profile, $user] = $this->provider();

        $this->withToken($this->token)->patchJson("/api/providers/{$profile->id}/verification/remove")
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->token)->patchJson("/api/providers/{$profile->id}/verification/remove", ['reason' => self::REASON])->assertOk();
        Notification::assertSentTo($user, ProviderAccountNotification::class, fn ($n) => $n->toArray($user)['action'] === 'removed'
            && $n->toArray($user)['reason'] === self::REASON
            && str_contains($n->toArray($user)['message'], 'Reason: '.self::REASON));

        $this->withToken($this->token)->deleteJson("/api/providers/{$profile->id}")->assertUnprocessable();
        $this->withToken($this->token)->deleteJson("/api/providers/{$profile->id}", ['reason' => self::REASON])->assertOk();
        Mail::assertSent(UserDeletedMail::class, fn (UserDeletedMail $mail) => $mail->hasTo($user->email) && $mail->reason === self::REASON);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $profile->id, 'description' => 'provider_deleted']);
    }

    public function test_hiding_or_deleting_a_service_needs_a_reason_the_provider_sees(): void
    {
        [$profile, $user] = $this->provider();
        $service = $this->service($profile);

        $this->withToken($this->token)->patchJson("/api/services/{$service->id}/hide", ['is_hidden' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->token)->patchJson("/api/services/{$service->id}/hide", ['is_hidden' => true, 'reason' => self::REASON])->assertOk();
        // Showing it again needs no reason.
        $this->withToken($this->token)->patchJson("/api/services/{$service->id}/hide", ['is_hidden' => false])->assertOk();

        $this->withToken($this->token)->deleteJson("/api/services/{$service->id}")->assertUnprocessable();
        $this->withToken($this->token)->deleteJson("/api/services/{$service->id}", ['reason' => self::REASON])->assertOk();

        foreach (['hidden', 'deleted'] as $action) {
            Notification::assertSentTo($user, ServiceModerationNotification::class, fn ($n) => $n->toArray($user)['action'] === $action
                && str_contains($n->toArray($user)['message'], 'Reason: '.self::REASON));
        }
    }

    public function test_hiding_or_removing_a_review_needs_a_reason_the_reviewer_sees(): void
    {
        $review = $this->review();
        $reviewer = User::query()->find($review->reviewer_id);

        $this->withToken($this->token)->patchJson("/api/reviews/{$review->id}/hide", ['is_hidden' => true])->assertUnprocessable();
        $this->withToken($this->token)->patchJson("/api/reviews/{$review->id}/hide", ['is_hidden' => true, 'reason' => 'Contains a phone number.'])->assertOk();
        $this->withToken($this->token)->deleteJson("/api/reviews/{$review->id}")->assertUnprocessable();
        $this->withToken($this->token)->deleteJson("/api/reviews/{$review->id}", ['reason' => 'Contains a phone number.'])->assertOk();

        Notification::assertSentToTimes($reviewer, ReviewModerationNotification::class, 2);
        Notification::assertSentTo($reviewer, ReviewModerationNotification::class, fn ($n) => str_contains($n->toArray($reviewer)['message'], 'Reason: Contains a phone number.'));
    }

    public function test_cancelling_a_booking_needs_a_reason(): void
    {
        [$profile] = $this->provider();
        $booking = $this->booking($profile);

        $this->withToken($this->token)->patchJson("/api/bookings/{$booking->id}/cancel")
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->token)->patchJson("/api/bookings/{$booking->id}/cancel", ['reason' => 'The provider is unreachable.'])
            ->assertOk()
            ->assertJsonPath('data.cancellation_reason', 'The provider is unreachable.');
    }

    public function test_badges_and_the_featured_spot_tell_the_provider_and_taking_them_away_needs_a_reason(): void
    {
        [$profile, $user] = $this->provider();
        $badge = ProviderBadge::create(['name' => 'Trusted', 'slug' => 'trusted', 'color' => 'success']);
        $base = "/api/provider-recognition/providers/{$profile->id}";

        $this->withToken($this->token)->postJson("{$base}/badges", ['badge_id' => $badge->id, 'reason' => 'Fifty five-star jobs.'])->assertOk();
        $this->withToken($this->token)->deleteJson("{$base}/badges/{$badge->id}")->assertUnprocessable();
        $this->withToken($this->token)->deleteJson("{$base}/badges/{$badge->id}", ['reason' => self::REASON])->assertOk();
        $this->withToken($this->token)->patchJson("{$base}/featured", ['is_featured' => true])->assertOk();
        $this->withToken($this->token)->patchJson("{$base}/featured", ['is_featured' => false])->assertUnprocessable();
        $this->withToken($this->token)->patchJson("{$base}/featured", ['is_featured' => false, 'reason' => self::REASON])->assertOk();

        $sent = fn (string $action, string $text) => Notification::assertSentTo($user, ProviderAccountNotification::class, fn ($n) => $n->toArray($user)['type'] === 'provider_recognition'
            && $n->toArray($user)['action'] === $action
            && str_contains($n->toArray($user)['message'], $text));
        $sent('badge_assigned', 'You received the Trusted badge. Customers see it on your profile. Note from SkillServe: Fifty five-star jobs.');
        $sent('badge_removed', 'Your Trusted badge was removed. Reason: '.self::REASON);
        $sent('featured', 'Your profile is now featured');
        $sent('unfeatured', 'Reason: '.self::REASON);
    }

    public function test_removing_a_reported_chat_message_tells_the_sender_why(): void
    {
        [$profile, $providerUser] = $this->provider();
        $booking = $this->booking($profile);
        $message = Message::query()->create([
            'booking_id' => $booking->id, 'sender_id' => $providerUser->id, 'receiver_id' => $booking->client_id,
            'content' => 'Pay me outside the app.', 'status' => 'active',
        ]);
        $report = Report::create([
            'reportable_type' => $message->getMorphClass(), 'reportable_id' => $message->id,
            'reporter_id' => $booking->client_id, 'reason' => 'scam', 'description' => 'Asked me to pay outside the app.', 'status' => 'pending',
        ]);

        $this->withToken($this->token)->patchJson("/api/reports/{$report->id}/action", ['action' => 'remove'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->withToken($this->token)->patchJson("/api/reports/{$report->id}/action", ['action' => 'remove', 'reason' => 'Asking for payment outside SkillServe.'])->assertOk();

        Notification::assertSentTo($providerUser, MessageRemovedNotification::class, fn ($n) => $n->toArray($providerUser)['booking_id'] === $booking->id
            && str_contains($n->toArray($providerUser)['message'], 'Reason: Asking for payment outside SkillServe.'));
    }

    /** @return array{0: ProviderProfile, 1: User} */
    private function provider(): array
    {
        $user = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Reason Plumbing',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        return [$profile, $user];
    }

    private function service(ProviderProfile $profile): Service
    {
        $category = ServiceCategory::create(['name' => 'Reasons '.Str::random(5), 'status' => 'enabled']);

        return Service::create([
            'provider_id' => $profile->id, 'category_id' => $category->id, 'title' => 'Pipe Repair',
            'price' => 1000, 'status' => 'published', 'approval_status' => 'approved',
        ]);
    }

    private function booking(ProviderProfile $profile, string $status = 'confirmed'): Booking
    {
        $client = User::factory()->create(['user_type' => 'customer', 'status' => 'active']);

        return Booking::create([
            'service_id' => $this->service($profile)->id, 'client_id' => $client->id, 'provider_id' => $profile->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)), 'status' => $status, 'payment_status' => 'unpaid',
            'total_price' => 1000, 'service_price' => 1000, 'platform_fee' => 100, 'currency' => 'PHP',
            'scheduled_date' => now()->addDays(3), 'scheduled_end_date' => now()->addDays(3)->addHours(2),
        ]);
    }

    private function review(): Review
    {
        [$profile] = $this->provider();
        $booking = $this->booking($profile, 'completed');

        return Review::create([
            'booking_id' => $booking->id, 'reviewer_id' => $booking->client_id, 'provider_id' => $profile->id,
            'service_id' => $booking->service_id, 'rating' => 5, 'comment' => 'Call me on 0917 000 0000.', 'status' => 'active',
        ]);
    }
}

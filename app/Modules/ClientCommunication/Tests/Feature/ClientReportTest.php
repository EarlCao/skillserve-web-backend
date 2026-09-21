<?php

namespace App\Modules\ClientCommunication\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_reports_the_provider_on_their_booking(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        $response = $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'reason' => 'no_show',
                'description' => 'The provider never arrived for the appointment.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reason', 'no_show')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.reported.name', $providerUser->name)
            // Nothing has been decided yet, so there is no outcome to show.
            ->assertJsonPath('data.outcome', null);

        $report = Report::query()->findOrFail($response->json('data.id'));
        // The subject is the provider's account, the one reportable type the
        // admin console can warn, suspend or ban.
        $this->assertSame(User::class, $report->reportable_type);
        $this->assertSame($providerUser->id, (int) $report->reportable_id);
        $this->assertSame($client->id, (int) $report->reporter_id);
        // The booking is named so a moderator knows which job this is about.
        $this->assertStringContainsString($booking->booking_number, $report->description);
    }

    public function test_a_provider_can_report_the_customer_on_their_booking(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'reason' => 'harassment',
                'description' => 'The customer was abusive on arrival.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.reported.name', $client->name);
    }

    public function test_a_booking_the_caller_was_not_on_cannot_be_reported(): void
    {
        $stranger = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($this->customer(), $provider);

        $this->withToken($this->clientToken($stranger))
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'reason' => 'other',
                'description' => 'I was never part of this booking.',
            ])
            // Reported as missing, not forbidden, so booking ids cannot be probed.
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_the_report_form_is_validated(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider);
        $token = $this->clientToken($client);

        // An unknown reason is refused.
        $this->withToken($token)
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'reason' => 'because',
                'description' => 'Something went wrong here.',
            ])
            ->assertStatus(422);

        // So is a description too short to act on.
        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'reason' => 'other',
                'description' => 'bad',
            ])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson('/api/client/v1/reports', ['reason' => 'other', 'description' => 'No booking given here.'])
            ->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_a_second_report_about_the_same_person_waits_for_the_first(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider);
        $token = $this->clientToken($client);
        $payload = [
            'booking_id' => $booking->id,
            'reason' => 'service_quality',
            'description' => 'The work was left unfinished.',
        ];

        $this->withToken($token)->postJson('/api/client/v1/reports', $payload)->assertCreated();

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/client/v1/reports', $payload)->assertStatus(409);

        $this->assertDatabaseCount('reports', 1);

        // Once the first is closed, the same person can be reported again.
        Report::query()->update(['status' => 'resolved']);

        Auth::forgetGuards();
        $this->withToken($token)->postJson('/api/client/v1/reports', $payload)->assertCreated();

        $this->assertDatabaseCount('reports', 2);
    }

    public function test_the_list_is_scoped_to_the_reporter_and_hides_moderation_internals(): void
    {
        $client = $this->customer();
        $other = $this->customer();
        [$provider, $providerUser] = $this->provider();

        $mine = Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $client->id,
            'reason' => 'safety_concern',
            'description' => 'Felt unsafe.',
            'status' => 'resolved',
            'resolution_note' => 'We spoke with the provider.',
            'investigation_notes' => [['note' => 'Internal only.']],
            'moderation_action' => 'warning',
        ]);
        Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $other->id,
            'reason' => 'other',
            'description' => 'Not yours.',
            'status' => 'pending',
        ]);

        $row = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/reports')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $mine->id)
            // The reporter is told the outcome…
            ->assertJsonPath('data.0.outcome', 'We spoke with the provider.')
            ->json('data.0');

        // …but never the internal record or what was done to the other account.
        $this->assertArrayNotHasKey('investigation_notes', $row);
        $this->assertArrayNotHasKey('moderation_action', $row);
        $this->assertArrayNotHasKey('resolution_note', $row);
        $this->assertArrayNotHasKey('reporter', $row);
    }

    public function test_a_rejected_report_shows_the_rejection_reason_as_its_outcome(): void
    {
        $client = $this->customer();
        [, $providerUser] = $this->provider();

        $report = Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $client->id,
            'reason' => 'other',
            'description' => 'A complaint.',
            'status' => 'rejected',
            'reject_reason' => 'No evidence of a breach.',
        ]);

        $this->withToken($this->clientToken($client))
            ->getJson("/api/client/v1/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.outcome', 'No evidence of a breach.');
    }

    public function test_another_persons_report_cannot_be_read(): void
    {
        $client = $this->customer();
        [, $providerUser] = $this->provider();
        $report = Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $this->customer()->id,
            'reason' => 'other',
            'description' => 'Someone else reported this.',
            'status' => 'pending',
        ]);

        $this->withToken($this->clientToken($client))
            ->getJson("/api/client/v1/reports/{$report->id}")
            ->assertForbidden();
    }

    public function test_a_published_review_can_be_reported_and_is_flagged_for_moderators(): void
    {
        $author = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($author, $provider);
        $review = $this->review($booking, $author, 'Terrible, avoid this person!!!');

        $data = $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->postJson('/api/client/v1/reports', [
                'review_id' => $review->id,
                'reason' => 'inappropriate_content',
                'description' => 'This review is abusive and untrue.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'review')
            ->assertJsonPath('data.reported.name', $author->name)
            ->assertJsonPath('data.reported.excerpt', 'Terrible, avoid this person!!!')
            ->json('data');

        $report = Report::query()->findOrFail($data['id']);
        $this->assertSame(Review::class, $report->reportable_type);
        // The admin Reviews page filters on this flag.
        $this->assertTrue((bool) $review->fresh()->is_reported);
        $this->assertSame('inappropriate_content', $review->fresh()->report_reason);
    }

    public function test_you_cannot_report_your_own_review_or_a_hidden_one(): void
    {
        $author = $this->customer();
        [$provider] = $this->provider();
        $review = $this->review($this->booking($author, $provider), $author, 'My own words.');

        $this->withToken($this->clientToken($author))
            ->postJson('/api/client/v1/reports', [
                'review_id' => $review->id,
                'reason' => 'other',
                'description' => 'Reporting myself for some reason.',
            ])
            ->assertStatus(422);

        $review->update(['status' => 'hidden']);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($this->customer()))
            ->postJson('/api/client/v1/reports', [
                'review_id' => $review->id,
                'reason' => 'spam',
                'description' => 'Already hidden by a moderator.',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_only_a_received_message_can_be_reported(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider);

        $received = Message::query()->create([
            'booking_id' => $booking->id,
            'sender_id' => $providerUser->id,
            'receiver_id' => $client->id,
            'content' => 'Pay me outside the app or else.',
            'status' => 'active',
        ]);
        $sent = Message::query()->create([
            'booking_id' => $booking->id,
            'sender_id' => $client->id,
            'receiver_id' => $providerUser->id,
            'content' => 'My own message.',
            'status' => 'active',
        ]);

        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/reports', [
                'message_id' => $received->id,
                'reason' => 'harassment',
                'description' => 'The provider is threatening me.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'message')
            ->assertJsonPath('data.reported.name', $providerUser->name);

        // Your own message, or a stranger's conversation, is not yours to report.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/reports', [
                'message_id' => $sent->id,
                'reason' => 'other',
                'description' => 'Reporting what I wrote.',
            ])
            ->assertNotFound();

        Auth::forgetGuards();
        $this->withToken($this->clientToken($this->customer()))
            ->postJson('/api/client/v1/reports', [
                'message_id' => $received->id,
                'reason' => 'other',
                'description' => 'Not my conversation.',
            ])
            ->assertNotFound();
    }

    public function test_a_report_names_exactly_one_subject(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider);
        $review = $this->review($booking, $this->customer(), 'Someone else.');

        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/reports', [
                'booking_id' => $booking->id,
                'review_id' => $review->id,
                'reason' => 'other',
                'description' => 'Two subjects at once.',
            ])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->postJson('/api/client/v1/reports', [
                'reason' => 'other',
                'description' => 'No subject at all here.',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('reports', 0);
    }

    public function test_the_database_refuses_a_second_open_report_that_slips_past_the_check(): void
    {
        $client = $this->customer();
        [, $providerUser] = $this->provider();
        $row = [
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $client->id,
            'reason' => 'other',
            'description' => 'First.',
            'status' => 'pending',
        ];

        Report::query()->create($row);

        // What a racing second request would hit after both passed the check.
        $this->expectException(QueryException::class);
        Report::query()->create([...$row, 'description' => 'Second.']);
    }

    public function test_a_closed_report_does_not_block_a_new_one_at_the_database(): void
    {
        $client = $this->customer();
        [, $providerUser] = $this->provider();
        $row = [
            'reportable_type' => User::class,
            'reportable_id' => $providerUser->id,
            'reporter_id' => $client->id,
            'reason' => 'other',
            'description' => 'Decided already.',
        ];

        Report::query()->create([...$row, 'status' => 'resolved']);
        Report::query()->create([...$row, 'status' => 'rejected']);
        Report::query()->create([...$row, 'status' => 'pending']);

        $this->assertDatabaseCount('reports', 3);
    }

    public function test_reports_require_a_mobile_account(): void
    {
        $this->getJson('/api/client/v1/reports')->assertUnauthorized();

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/reports')
            ->assertForbidden();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'email' => 'customer.'.Str::random(8).'@skillserve.test',
            'user_type' => 'customer',
            'status' => 'active',
        ]);
    }

    private function clientToken(User $client): string
    {
        return $client->createToken('client-test', ['client:auth'])->plainTextToken;
    }

    /**
     * @return array{0: ProviderProfile, 1: User}
     */
    private function provider(): array
    {
        $user = User::factory()->create([
            'email' => 'provider.'.Str::random(8).'@skillserve.test',
            'user_type' => 'provider',
            'status' => 'active',
        ]);
        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Reported Provider',
            'verification_status' => 'verified',
        ]);

        return [$profile, $user];
    }

    private function review(Booking $booking, User $author, string $comment): Review
    {
        return Review::query()->create([
            'booking_id' => $booking->id,
            'reviewer_id' => $author->id,
            'provider_id' => $booking->provider_id,
            'service_id' => $booking->service_id,
            'rating' => 1,
            'comment' => $comment,
            'status' => 'active',
        ]);
    }

    private function booking(User $client, ProviderProfile $provider, array $attributes = []): Booking
    {
        $category = ServiceCategory::create(['name' => 'Reports '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Reported service',
            'price' => 100,
            'price_type' => 'fixed',
            'currency' => 'PHP',
            'status' => 'published',
            'approval_status' => 'approved',
            'is_hidden' => false,
        ]);

        return Booking::create(array_merge([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(12)),
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->subDay(),
        ], $attributes));
    }
}

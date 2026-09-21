<?php

namespace App\Modules\ClientMarketplace\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BookingDisputeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_raises_a_dispute_on_a_completed_booking(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);

        $this->withToken($this->clientToken($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'The aircon still leaks after the visit.',
            ])
            ->assertOk()
            ->assertJsonPath('data.booking_id', $booking->id)
            ->assertJsonPath('data.booking_status', 'disputed')
            ->assertJsonPath('data.dispute_status', 'pending')
            ->assertJsonPath('data.reason', 'The aircon still leaks after the visit.');

        $booking->refresh();
        $this->assertSame('disputed', $booking->status);
        $this->assertSame('pending', $booking->dispute_status);
        $this->assertNotNull($booking->disputed_at);
    }

    public function test_a_provider_can_also_raise_a_dispute(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'active']);

        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'The customer refused access to the unit.',
            ])
            ->assertOk()
            ->assertJsonPath('data.dispute_status', 'pending');
    }

    public function test_only_work_that_started_or_finished_can_be_disputed(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $token = $this->clientToken($client);

        foreach (['pending', 'confirmed', 'cancelled'] as $status) {
            $booking = $this->booking($client, $provider, ['status' => $status]);

            Auth::forgetGuards();
            $this->withToken($token)
                ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                    'reason' => 'Trying to dispute a job that has not happened.',
                ])
                ->assertStatus(422);

            $this->assertSame($status, $booking->fresh()->status);
            $this->assertNull($booking->fresh()->dispute_reason);
        }
    }

    public function test_a_booking_can_only_be_disputed_once(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);
        $token = $this->clientToken($client);
        $payload = ['reason' => 'The work was not finished as agreed.'];

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", $payload)
            ->assertOk();

        Auth::forgetGuards();
        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", $payload)
            ->assertStatus(409);
    }

    public function test_a_stranger_cannot_dispute_someone_elses_booking(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);

        $this->withToken($this->clientToken($this->customer()))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'This booking has nothing to do with me.',
            ])
            ->assertForbidden();

        $this->assertNull($booking->fresh()->dispute_reason);
    }

    public function test_the_reason_is_validated(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);
        $token = $this->clientToken($client);

        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", ['reason' => 'bad'])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($token)
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [])
            ->assertStatus(422);

        $this->assertNull($booking->fresh()->dispute_reason);
    }

    public function test_both_parties_are_notified_when_a_dispute_is_raised(): void
    {
        Notification::fake();

        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);

        $this->withToken($this->clientToken($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'The job was left half done.',
            ])
            ->assertOk();

        // The provider hears about it; the customer who raised it does not get
        // told their own news.
        Notification::assertSentTo($providerUser, BookingStatusNotification::class);
        Notification::assertNotSentTo($client, BookingStatusNotification::class);
    }

    public function test_a_client_raised_dispute_reaches_the_administrator_queue(): void
    {
        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);

        $this->withToken($this->clientToken($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'Damage was caused during the visit.',
            ])
            ->assertOk();

        // The admin dispute queue selects on dispute_reason, so setting it is
        // what actually hands the case over.
        $booking->refresh();
        $this->assertNotNull($booking->dispute_reason);
        $this->assertSame('pending', $booking->dispute_status);
    }

    public function test_the_parties_can_list_their_disputes_without_internal_notes(): void
    {
        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->booking($client, $provider, ['status' => 'completed']);
        $this->booking($this->customer(), $provider, ['status' => 'completed']);

        $this->withToken($this->clientToken($client))
            ->patchJson("/api/client/v1/bookings/{$booking->id}/dispute", [
                'reason' => 'Unfinished work on the unit.',
            ])
            ->assertOk();

        // An administrator works the case and leaves internal notes.
        $booking->refresh()->update([
            'dispute_status' => 'resolved',
            'dispute_resolution' => 'Refund arranged with the provider.',
            'dispute_notes' => [['note' => 'Internal: spoke to both parties.']],
        ]);

        Auth::forgetGuards();
        $row = $this->withToken($this->clientToken($client))
            ->getJson('/api/client/v1/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_id', $booking->id)
            ->assertJsonPath('data.0.dispute_status', 'resolved')
            ->assertJsonPath('data.0.resolution', 'Refund arranged with the provider.')
            ->json('data.0');

        $this->assertArrayNotHasKey('dispute_notes', $row);

        // The provider on the booking sees the same case.
        Auth::forgetGuards();
        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->getJson('/api/client/v1/disputes')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.booking_id', $booking->id);
    }

    public function test_either_party_attaches_photos_that_stay_private(): void
    {
        Storage::fake('dispute_evidence');

        $client = $this->customer();
        [$provider, $providerUser] = $this->provider();
        $booking = $this->disputed($client, $provider);

        $this->withToken($this->clientToken($client))
            ->postJson("/api/client/v1/bookings/{$booking->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('leak.jpg'),
                'caption' => 'Water still leaking',
            ])
            ->assertCreated()
            ->assertJsonPath('data.evidence.0.label', 'Water still leaking')
            ->assertJsonPath('data.evidence.0.uploaded_by_role', 'customer')
            ->assertJsonPath('data.evidence.0.is_mine', true)
            ->assertJsonMissingPath('data.evidence.0.path');

        Auth::forgetGuards();
        $this->withToken($providerUser->createToken('provider', ['client:auth'])->plainTextToken)
            ->postJson("/api/client/v1/bookings/{$booking->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('access.png'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.evidence.1.uploaded_by_role', 'provider')
            ->assertJsonPath('data.evidence.0.is_mine', false);

        $stored = $booking->fresh()->dispute_evidence;
        $this->assertCount(2, $stored);
        Storage::disk('dispute_evidence')->assertExists($stored[0]['path']);
    }

    public function test_evidence_needs_an_open_dispute_and_stops_at_the_limit(): void
    {
        Storage::fake('dispute_evidence');

        $client = $this->customer();
        [$provider] = $this->provider();
        $token = $this->clientToken($client);

        // No dispute yet.
        $undisputed = $this->booking($client, $provider, ['status' => 'completed']);
        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$undisputed->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('a.jpg'),
            ])
            ->assertStatus(422);

        // A decided dispute takes no more evidence.
        $decided = $this->disputed($client, $provider, ['dispute_status' => 'resolved']);
        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$decided->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('b.jpg'),
            ])
            ->assertStatus(422);

        $open = $this->disputed($client, $provider);
        for ($i = 0; $i < 5; $i++) {
            Auth::forgetGuards();
            $this->withToken($token)
                ->postJson("/api/client/v1/bookings/{$open->id}/dispute/evidence", [
                    'image' => UploadedFile::fake()->image("p{$i}.jpg"),
                ])
                ->assertCreated();
        }

        Auth::forgetGuards();
        $this->withToken($token)
            ->postJson("/api/client/v1/bookings/{$open->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('p6.jpg'),
            ])
            ->assertStatus(422);

        $this->assertCount(5, $open->fresh()->dispute_evidence);
        // A refused upload leaves no orphan file behind.
        $this->assertCount(5, Storage::disk('dispute_evidence')->allFiles());
    }

    public function test_evidence_must_be_an_image_from_a_party_to_the_booking(): void
    {
        Storage::fake('dispute_evidence');

        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->disputed($client, $provider);

        $this->withToken($this->clientToken($client))
            ->postJson("/api/client/v1/bookings/{$booking->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertStatus(422);

        Auth::forgetGuards();
        $this->withToken($this->clientToken($this->customer()))
            ->postJson("/api/client/v1/bookings/{$booking->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('x.jpg'),
            ])
            ->assertForbidden();

        $this->assertNull($booking->fresh()->dispute_evidence);
    }

    public function test_administrators_download_evidence_and_never_see_its_storage_path(): void
    {
        Storage::fake('dispute_evidence');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Roles are set up before any request, so they bind to the web guard
        // the admin console uses.
        foreach (['manage bookings', 'view bookings', 'manage booking disputes'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $role = Role::findOrCreate('evidence-reviewer');
        $role->syncPermissions(['view bookings', 'manage booking disputes']);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole($role);
        $adminToken = $admin->createToken('admin')->plainTextToken;

        $client = $this->customer();
        [$provider] = $this->provider();
        $booking = $this->disputed($client, $provider);

        $this->withToken($this->clientToken($client))
            ->postJson("/api/client/v1/bookings/{$booking->id}/dispute/evidence", [
                'image' => UploadedFile::fake()->image('leak.jpg'),
            ])
            ->assertCreated();

        Auth::forgetGuards();
        $item = $this->withToken($adminToken)
            ->getJson("/api/disputes/{$booking->id}")
            ->assertOk()
            ->json('data.dispute_evidence.0');

        $this->assertArrayNotHasKey('path', $item);
        $this->assertSame("/disputes/{$booking->id}/evidence/{$item['id']}", $item['download_path']);

        Auth::forgetGuards();
        $this->withToken($adminToken)
            ->get("/api/disputes/{$booking->id}/evidence/{$item['id']}")
            ->assertOk();

        // A mobile account cannot use the admin download.
        Auth::forgetGuards();
        $this->withToken($this->clientToken($client))
            ->get("/api/disputes/{$booking->id}/evidence/{$item['id']}")
            ->assertForbidden();
    }

    public function test_the_dispute_list_needs_a_mobile_account(): void
    {
        $this->getJson('/api/client/v1/disputes')->assertUnauthorized();

        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 'active']);
        $this->withToken($admin->createToken('admin')->plainTextToken)
            ->getJson('/api/client/v1/disputes')
            ->assertForbidden();
    }

    private function disputed(User $client, ProviderProfile $provider, array $attributes = []): Booking
    {
        return $this->booking($client, $provider, array_merge([
            'status' => 'disputed',
            'dispute_reason' => 'The work was not finished.',
            'disputed_at' => now(),
            'dispute_status' => 'pending',
        ], $attributes));
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
            'business_name' => 'Disputed Provider',
            'verification_status' => 'verified',
        ]);

        return [$profile, $user];
    }

    private function booking(User $client, ProviderProfile $provider, array $attributes = []): Booking
    {
        $category = ServiceCategory::create(['name' => 'Disputes '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Disputed service',
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

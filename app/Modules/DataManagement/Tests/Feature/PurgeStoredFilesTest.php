<?php

namespace App\Modules\DataManagement\Tests\Feature;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\DataManagement\Services\DataManagementService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\ReportsAndModeration\Models\ReportAttachment;
use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Photos in private storage go when their record is removed for good, and a
 * permanently deleted account takes its own uploads with it.
 */
class PurgeStoredFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['message_images', 'dispute_evidence', 'report_attachments', 'support_attachments', 'public'] as $disk) {
            Storage::fake($disk);
        }
    }

    public function test_a_purged_account_takes_its_proof_photos_and_profile_photo_but_the_records_stay(): void
    {
        $customer = User::factory()->create(['user_type' => 'customer', 'status' => 'active', 'profile_photo_path' => 'profile-photos/me.jpg']);
        Storage::disk('public')->put('profile-photos/me.jpg', 'x');
        $report = Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reporter_id' => $customer->id,
            'reason' => 'other',
            'description' => 'Booking BK-1: something happened.',
            'status' => 'pending',
        ]);
        $reportPhoto = $this->file('report_attachments', "{$customer->id}/a.jpg");
        $report->attachments()->create(['uploaded_by' => $customer->id, 'path' => $reportPhoto, 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);
        $ticket = SupportTicket::query()->create([
            'ticket_number' => 'SUP-'.Str::upper(Str::random(8)),
            'requester_id' => $customer->id,
            'subject' => 'Help',
            'description' => 'Screenshot attached.',
        ]);
        $ticketPhoto = $this->file('support_attachments', "{$customer->id}/b.jpg");
        $ticket->attachments()->create(['uploaded_by' => $customer->id, 'path' => $ticketPhoto, 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);

        $customer->delete();
        $customer->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();
        app(DataManagementService::class)->purgeExpired();

        $this->assertNull(User::withTrashed()->find($customer->id));
        Storage::disk('report_attachments')->assertMissing($reportPhoto);
        Storage::disk('support_attachments')->assertMissing($ticketPhoto);
        Storage::disk('public')->assertMissing('profile-photos/me.jpg');
        $this->assertSame(0, ReportAttachment::query()->count());
        $this->assertSame(0, SupportTicketAttachment::query()->count());
        // The moderation and support records remain, without the person.
        $this->assertNull($report->fresh()->reporter_id);
        $this->assertNull($ticket->fresh()->requester_id);
    }

    public function test_a_report_or_ticket_removed_for_good_takes_its_photos(): void
    {
        $report = Report::query()->create([
            'reportable_type' => User::class,
            'reportable_id' => User::factory()->create()->id,
            'reason' => 'other',
            'description' => 'Something happened.',
            'status' => 'pending',
        ]);
        $reportPhoto = $this->file('report_attachments', '1/a.jpg');
        $report->attachments()->create(['path' => $reportPhoto, 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);
        $ticket = SupportTicket::query()->create(['ticket_number' => 'SUP-X1', 'subject' => 'Help', 'description' => 'See photo.']);
        $ticketPhoto = $this->file('support_attachments', '1/b.jpg');
        $ticket->attachments()->create(['path' => $ticketPhoto, 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);

        $reply = $ticket->messages()->create(['body' => '']);
        $replyPhoto = $this->file('support_attachments', '1/r.jpg');
        $ticket->attachments()->create(['support_ticket_message_id' => $reply->id, 'path' => $replyPhoto, 'mime_type' => 'image/jpeg', 'size_bytes' => 1]);
        $reply->delete();
        Storage::disk('support_attachments')->assertMissing($replyPhoto);

        // A soft delete keeps them (it can be restored).
        $report->delete();
        Storage::disk('report_attachments')->assertExists($reportPhoto);

        $report->forceDelete();
        $ticket->forceDelete();

        Storage::disk('report_attachments')->assertMissing($reportPhoto);
        Storage::disk('support_attachments')->assertMissing($ticketPhoto);
    }

    public function test_a_chat_photo_and_dispute_evidence_go_with_their_message_and_booking(): void
    {
        $booking = $this->booking();
        $chatPhoto = $this->file('message_images', "{$booking->id}/c.jpg");
        $message = Message::query()->create([
            'booking_id' => $booking->id,
            'sender_id' => $booking->client_id,
            'receiver_id' => User::factory()->create()->id,
            'content' => '',
            'image_path' => $chatPhoto,
        ]);
        $evidence = $this->file('dispute_evidence', "{$booking->id}/d.jpg");
        $booking->update(['dispute_evidence' => [['id' => 'e1', 'path' => $evidence, 'label' => 'Leak']]]);

        $message->forceDelete();
        Storage::disk('message_images')->assertMissing($chatPhoto);

        $booking->forceDelete();
        Storage::disk('dispute_evidence')->assertMissing($evidence);
    }

    private function file(string $disk, string $path): string
    {
        Storage::disk($disk)->put($path, 'photo');

        return $path;
    }

    private function booking(): Booking
    {
        $providerUser = User::factory()->create(['user_type' => 'provider', 'status' => 'active']);
        $provider = ProviderProfile::create(['user_id' => $providerUser->id, 'business_name' => 'Purge Provider']);
        $category = ServiceCategory::create(['name' => 'Purge '.Str::random(6), 'status' => 'enabled']);
        $service = Service::create([
            'provider_id' => $provider->id,
            'category_id' => $category->id,
            'title' => 'Fix',
            'price' => 100,
            'status' => 'published',
            'approval_status' => 'approved',
        ]);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => User::factory()->create(['user_type' => 'customer'])->id,
            'provider_id' => $provider->id,
            'booking_number' => 'BK-'.Str::upper(Str::random(10)),
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total_price' => 100,
            'service_price' => 100,
            'platform_fee' => 10,
            'currency' => 'PHP',
            'scheduled_date' => now()->subDay(),
        ]);
    }
}

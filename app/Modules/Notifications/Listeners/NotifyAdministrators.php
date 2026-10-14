<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingDisputeEvidenceAdded;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Events\CommissionRemittanceFlagged;
use App\Modules\IdentityVerification\Events\IdentityVerificationSubmitted;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Modules\Notifications\Notifications\AdminAlertNotification as Alert;
use App\Modules\Notifications\Services\AdminAlertService;
use App\Modules\Providers\Events\ProviderVerificationSubmitted;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Events\ReportSubmitted;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Services\Events\ServiceCreated;
use App\Modules\Services\Events\ServiceUpdated;
use App\Modules\Services\Models\Service;
use App\Modules\Support\Events\SupportTicketCreated;
use App\Modules\Support\Events\SupportTicketResponseAdded;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Support\Str;

/**
 * Turns what users do in the app into admin Inbox alerts. Each alert goes
 * only to administrators allowed to open the page that handles it — the same
 * ability that page's list endpoint checks.
 */
class NotifyAdministrators
{
    public function __construct(private readonly AdminAlertService $alerts) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof ProviderVerificationSubmitted => $this->providerVerification($event),
            $event instanceof IdentityVerificationSubmitted => $this->identityVerification($event),
            $event instanceof ServiceCreated, $event instanceof ServiceUpdated => $this->service($event),
            $event instanceof ReportSubmitted => $this->report($event),
            $event instanceof BookingStatusChanged => $this->disputeRaised($event),
            $event instanceof BookingDisputeEvidenceAdded => $this->disputeEvidence($event),
            $event instanceof SupportTicketCreated => $this->ticketCreated($event),
            $event instanceof SupportTicketResponseAdded => $this->ticketReply($event),
            $event instanceof CommissionRemittanceFlagged => $this->paymentFlagged($event),
            default => null,
        };
    }

    private function providerVerification(ProviderVerificationSubmitted $event): void
    {
        $profile = $event->providerProfile;
        $name = $profile->displayName();

        $this->alerts->send(new Alert(
            Alert::PROVIDER_VERIFICATION,
            $event->isResponse ? 'Provider sent the requested information' : 'New provider verification request',
            "{$name} submitted {$event->documentCount} ".Str::plural('document', $event->documentCount).' for review.',
            "/admin/providers/{$profile->id}",
            ['provider_id' => $profile->id],
        ), 'viewAny', ProviderProfile::class, $event->actor);
    }

    private function identityVerification(IdentityVerificationSubmitted $event): void
    {
        $name = trim((string) $event->verification->user?->name) ?: 'A user';

        $this->alerts->send(new Alert(
            Alert::IDENTITY_VERIFICATION,
            'National ID to review',
            "{$name} submitted their National ID for verification.",
            '/admin/identity-verifications',
            ['identity_verification_id' => $event->verification->id],
        ), 'viewAny', IdentityVerification::class, $event->actor);
    }

    /** A provider's new service, or an edit that sent it back for approval. */
    private function service(ServiceCreated|ServiceUpdated $event): void
    {
        $service = $event->service;

        // Administrators editing a service, and services that need no
        // approval, leave nothing to review.
        if ($event->actor->isAdministrator() || $service->approval_status !== 'pending') {
            return;
        }

        $provider = $service->provider?->displayName() ?: 'A provider';

        $this->alerts->send(new Alert(
            Alert::SERVICE_REVIEW,
            $event instanceof ServiceCreated ? 'New service awaiting approval' : 'Edited service awaiting approval',
            "{$provider} ".($event instanceof ServiceCreated ? 'added' : 'changed')." “{$service->title}”.",
            '/admin/services',
            ['service_id' => $service->id],
        ), 'viewAny', Service::class, $event->actor);
    }

    private function report(ReportSubmitted $event): void
    {
        $report = $event->report;
        $about = Str::lower(class_basename($report->reportable_type));

        $this->alerts->send(new Alert(
            Alert::REPORT,
            'New report: '.Str::headline((string) $report->reason),
            ($event->actor->name ?: 'A user')." reported a {$about}.",
            '/admin/reports',
            ['report_id' => $report->id],
        ), 'viewAny', Report::class, $event->actor);
    }

    private function disputeRaised(BookingStatusChanged $event): void
    {
        if ($event->newStatus !== 'disputed' || $event->fromDispute) {
            return;
        }

        $this->alerts->send(new Alert(
            Alert::DISPUTE,
            'New dispute: '.$this->bookingLabel($event->booking),
            ($event->actor->name ?: 'A user').' says: '.Str::limit((string) $event->booking->dispute_reason, 120),
            '/admin/disputes',
            ['booking_id' => $event->booking->id],
        ), 'viewDisputes', Booking::class, $event->actor);
    }

    private function disputeEvidence(BookingDisputeEvidenceAdded $event): void
    {
        $this->alerts->send(new Alert(
            Alert::DISPUTE,
            'New dispute evidence: '.$this->bookingLabel($event->booking),
            ($event->actor->name ?: 'A user').' added a photo to the dispute.',
            '/admin/disputes',
            ['booking_id' => $event->booking->id],
        ), 'viewDisputes', Booking::class, $event->actor);
    }

    private function ticketCreated(SupportTicketCreated $event): void
    {
        $ticket = $event->ticket;

        $this->alerts->send(new Alert(
            Alert::SUPPORT_TICKET,
            "New support request {$ticket->ticket_number}",
            ($event->actor->name ?: 'A user')." asks: {$ticket->subject}",
            '/admin/support',
            ['ticket_id' => $ticket->id],
        ), 'viewAny', SupportTicket::class, $event->actor);
    }

    /** Only the user's own replies; staff replies are what answers them. */
    private function ticketReply(SupportTicketResponseAdded $event): void
    {
        $ticket = $event->ticket;

        if ((int) $event->actor->id !== (int) $ticket->requester_id) {
            return;
        }

        $alert = new Alert(
            Alert::SUPPORT_TICKET,
            "New reply on {$ticket->ticket_number}",
            ($event->actor->name ?: 'The user').': '.Str::limit((string) $event->message->body, 120),
            '/admin/support',
            ['ticket_id' => $ticket->id],
        );

        // An assigned ticket is that administrator's to answer.
        $assignee = $ticket->assigned_to ? User::query()->find($ticket->assigned_to) : null;
        if ($assignee?->isAdministrator() && $assignee->isActive()) {
            $this->alerts->sendTo($alert, $assignee);

            return;
        }

        $this->alerts->send($alert, 'viewAny', SupportTicket::class, $event->actor);
    }

    private function paymentFlagged(CommissionRemittanceFlagged $event): void
    {
        $remittance = $event->remittance;
        $provider = $remittance->provider?->displayName() ?: 'A provider';

        $this->alerts->send(new Alert(
            Alert::COMMISSION_PAYMENT,
            'Commission payment needs checking',
            "{$provider}'s QR payment {$remittance->reference}: {$remittance->failure_reason}",
            '/admin/commissions?tab=payments',
            ['remittance_id' => $remittance->id],
        ), 'view commissions');
    }

    private function bookingLabel(Booking $booking): string
    {
        $service = $booking->service?->title;

        return $booking->booking_number.($service ? " ({$service})" : '');
    }
}

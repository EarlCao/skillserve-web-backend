<?php

namespace App\Modules\Notifications\Notifications;

use App\Shared\Notifications\BaseNotification;

/**
 * Something in the app is waiting for an administrator: a verification
 * request, a service to approve, a report, a dispute, a support ticket or a
 * commission payment to check. Shown in the admin Inbox and its bell.
 *
 * Uncategorised, so never muted. [link] is the admin page that handles it.
 */
class AdminAlertNotification extends BaseNotification
{
    public const PROVIDER_VERIFICATION = 'provider_verification';

    public const IDENTITY_VERIFICATION = 'identity_verification';

    public const SERVICE_REVIEW = 'service_review';

    public const REPORT = 'report';

    public const DISPUTE = 'dispute';

    public const SUPPORT_TICKET = 'support_ticket';

    public const COMMISSION_PAYMENT = 'commission_payment';

    public const KINDS = [
        self::PROVIDER_VERIFICATION, self::IDENTITY_VERIFICATION, self::SERVICE_REVIEW,
        self::REPORT, self::DISPUTE, self::SUPPORT_TICKET, self::COMMISSION_PAYMENT,
    ];

    /**
     * @param  array<string, int|string|null>  $subject  ids of what it is about, e.g. ['booking_id' => 12]
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $title,
        private readonly string $message,
        private readonly string $link,
        private readonly array $subject = [],
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'admin_alert',
            'kind' => $this->kind,
            'title' => $this->title,
            'message' => $this->message,
            'link' => $this->link,
            ...$this->subject,
        ];
    }
}

<?php

namespace App\Modules\Commissions\Notifications;

use App\Modules\Commissions\Models\CommissionRemittance;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells a provider that SkillServe confirmed or rejected the GCash commission
 * payment they submitted.
 *
 * Uncategorised, so it is never muted: whether the provider can take new work
 * again depends on it. The app opens the Commission screen for this type.
 */
class CommissionRemittanceNotification extends BaseNotification
{
    public function __construct(
        private readonly CommissionRemittance $remittance,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'commission_remittance',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'remittance_id' => $this->remittance->id,
            'reference' => $this->remittance->reference,
            'amount_sent' => $this->remittance->amount_sent,
        ];
    }
}

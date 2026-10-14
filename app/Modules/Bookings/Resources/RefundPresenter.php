<?php

namespace App\Modules\Bookings\Resources;

use App\Modules\Bookings\Models\BookingRefund;

/**
 * What the provider owes back on a cancelled booking, as each audience reads
 * it. The proof screenshot is private; [proofBase] is the authorised route
 * that streams it for that audience.
 */
final class RefundPresenter
{
    /** @return array<string, mixed>|null */
    public static function present(?BookingRefund $refund, string $proofBase, bool $absolute = true): ?array
    {
        if ($refund === null) {
            return null;
        }

        $proof = $refund->proof_path !== null ? "{$proofBase}/refund/proof" : null;

        return [
            'amount' => $refund->amount,
            'status' => $refund->status,
            // Still due and past the deadline: the provider is held back.
            'is_overdue' => $refund->isOverdue(),
            'due_by' => $refund->due_by?->toIso8601String(),
            'reference' => $refund->reference,
            'sent_at' => $refund->sent_at?->toIso8601String(),
            'confirmed_at' => $refund->confirmed_at?->toIso8601String(),
            'customer_note' => $refund->customer_note,
            'escalated_at' => $refund->escalated_at?->toIso8601String(),
            'resolution' => $refund->resolution,
            'resolution_note' => $refund->resolution_note,
            'resolved_at' => $refund->resolved_at?->toIso8601String(),
            'proof_url' => $absolute && $proof ? url($proof) : null,
            'proof_download_path' => ! $absolute ? $proof : null,
        ];
    }
}

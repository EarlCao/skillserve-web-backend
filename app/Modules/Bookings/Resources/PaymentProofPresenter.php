<?php

namespace App\Modules\Bookings\Resources;

use App\Modules\Bookings\Models\BookingPaymentProof;
use Illuminate\Support\Collection;

/**
 * A booking's payment proofs as each audience reads them. The screenshot is
 * private; [imageBase] is the authorised route that streams it for that
 * audience (customer, provider or administrator).
 */
final class PaymentProofPresenter
{
    /**
     * @param  Collection<int, BookingPaymentProof>  $proofs
     * @return array<int, array<string, mixed>>
     */
    public static function list(Collection $proofs, string $imageBase, bool $absolute = true): array
    {
        return $proofs->map(fn (BookingPaymentProof $proof): array => [
            'id' => $proof->id,
            'amount' => $proof->amount,
            'reference' => $proof->reference,
            'status' => $proof->status,
            // Why the provider rejected it.
            'review_note' => $proof->review_note,
            'reviewed_at' => $proof->reviewed_at?->toIso8601String(),
            'created_at' => $proof->created_at?->toIso8601String(),
            // The app loads it with the user's token; the admin web with the
            // administrator's, relative to its API base.
            'image_url' => $absolute ? url("{$imageBase}/{$proof->id}/image") : null,
            'download_path' => $absolute ? null : "{$imageBase}/{$proof->id}/image",
        ])->values()->all();
    }
}

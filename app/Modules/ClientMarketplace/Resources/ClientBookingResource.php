<?php

namespace App\Modules\ClientMarketplace\Resources;

use App\Modules\Bookings\Resources\PaymentProofPresenter;
use App\Modules\Bookings\Resources\RefundPresenter;
use App\Modules\Bookings\Services\BookingPaymentService;
use App\Modules\Bookings\Services\BookingRules;
use App\Modules\Locations\Services\PhAddressService;
use App\Shared\Helpers\QrPh;
use App\Shared\Resources\BaseResource;

class ClientBookingResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'booking_number' => $this->booking_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            // What has been confirmed so far (downpayments add up) and what is left.
            'amount_paid' => $this->amount_paid,
            'balance_due' => number_format($this->resource->balanceDue(), 2, '.', ''),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_amount' => $this->refunded_amount,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'refund_reason' => $this->refund_reason,
            'service_price' => $this->service_price,
            'total_price' => $this->total_price,
            'currency' => $this->currency,
            'payment_method' => $this->payment_method,
            'cancellation_payment_policy' => $this->when($this->isCancelled(), fn () => $this->cancellationPaymentPolicy()),
            'client_notes' => $this->client_notes,
            'service_address' => $this->service_address,
            'service_address_details' => app(PhAddressService::class)->present($this->resource, 'service', PhAddressService::STREET),
            'contact_phone' => $this->contact_phone,
            'cancellation_reason' => $this->cancellation_reason,
            'cancellation_fee' => $this->cancellation_fee,
            // The cancellation rule and what cancelling now would cost; only
            // while this side can still cancel.
            'cancellation_policy' => $this->when(
                in_array($this->status, ['pending', 'confirmed'], true),
                fn () => app(BookingRules::class)->policyFor($this->resource, 'client'),
            ),
            'scheduled_date' => $this->scheduled_date?->toIso8601String(),
            'scheduled_end_date' => $this->scheduled_end_date?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'rescheduled_at' => $this->rescheduled_at?->toIso8601String(),
            'is_reviewed' => $this->is_reviewed,
            'service' => $this->whenLoaded('service', fn () => new ClientServiceResource($this->service)),
            'provider' => $this->whenLoaded('provider', fn () => $this->provider ? [
                'id' => $this->provider->id,
                'business_name' => $this->provider->business_name,
                'average_rating' => $this->provider->average_rating,
                // 'active', 'suspended', 'banned' or 'deleted', and what to
                // tell the customer when it is not 'active'.
                ...ProviderStandingPresenter::present($this->provider, $this->resource),
            ] : null),

            // Where to send the money. SkillServe is not in the payment path:
            // the customer pays the provider directly and the provider then
            // remits the commission (ADR-021), so the customer needs the
            // provider's own GCash details.
            //
            // Shown only on a GCash booking that is still unpaid, and only on
            // the customer's own booking — these are the provider's personal
            // payment details, not a public profile field, so they are not
            // handed out for a finished or cancelled job.
            'payment_instructions' => $this->when(
                $this->payment_method === 'gcash'
                    && in_array($this->payment_status, BookingPaymentService::OWING_STATUSES, true)
                    && ! in_array($this->status, ['cancelled'], true)
                    && $this->relationLoaded('provider'),
                fn () => $this->provider?->canReceiveGcash() === true
                    ? [
                        'method' => 'gcash',
                        'gcash_number' => $this->provider->gcash_number,
                        'gcash_name' => $this->provider->gcash_name,
                        // What is still to pay, after any confirmed downpayment.
                        'amount' => number_format($this->resource->balanceDue(), 2, '.', ''),
                        'reference' => $this->booking_number,
                        // The provider's own QR with this booking's amount in
                        // it: scanning it pays the provider directly, amount
                        // pre-filled.
                        'qr_payload' => $this->qrWithAmount(),
                        'note' => 'Send this amount — or a downpayment — to the provider in GCash, then send the screenshot below so they can confirm it. '
                            .'Check the name GCash shows you matches before you send.',
                    ]
                    : [
                        'method' => 'gcash',
                        'gcash_number' => null,
                        'gcash_name' => null,
                        'amount' => number_format($this->resource->balanceDue(), 2, '.', ''),
                        'reference' => $this->booking_number,
                        'qr_payload' => $this->qrWithAmount(),
                        'note' => 'This provider has not added their GCash details yet. '
                            .'Message them to arrange payment.',
                    ],
            ),
            // What the provider owes back after a cancellation, when anything was paid.
            'refund' => $this->whenLoaded('refund', fn () => RefundPresenter::present($this->refund, "/api/client/v1/bookings/{$this->id}")),
            // GCash payment proofs the customer sent, newest first.
            'payment_proofs' => $this->whenLoaded('paymentProofs', fn () => PaymentProofPresenter::list(
                $this->paymentProofs,
                "/api/client/v1/bookings/{$this->id}/payment-proofs",
            )),
            'review' => $this->whenLoaded('review', fn () => $this->review
                ? new ClientReviewResource($this->review)
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The provider's GCash QR with this booking's total written into it, or
     * null when they have not added one (or it no longer reads as QR Ph).
     */
    private function qrWithAmount(): ?string
    {
        $payload = $this->provider?->gcash_qr_payload;

        if ($payload === null || ! QrPh::isValid($payload)) {
            return null;
        }

        return QrPh::withAmount($payload, $this->resource->balanceDue());
    }
}

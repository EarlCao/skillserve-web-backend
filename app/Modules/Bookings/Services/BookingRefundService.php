<?php

namespace App\Modules\Bookings\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingPaymentRecorded;
use App\Modules\Bookings\Events\BookingRefundChanged;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingRefund;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Giving back what a customer paid when a booking is cancelled.
 *
 * SkillServe never holds the money, so the provider who received it must
 * return it: they send it in GCash and attach proof, the customer confirms
 * it arrived or reports that it did not, and an administrator settles what
 * the two cannot. Until it is settled the provider is held back from new
 * work once the deadline passes ({@see BookingRefund::scopeBlocking()}).
 */
class BookingRefundService extends BaseService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Opens the refund for a just-cancelled booking that had money paid on
     * it. A customer who cancelled late leaves the provider their recorded
     * late-cancellation fee; everything else goes back.
     */
    public function openFor(Booking $booking, User $actor): ?BookingRefund
    {
        $paid = round((float) $booking->amount_paid, 2);
        if ($paid <= 0 || $booking->refund()->exists()) {
            return null;
        }

        $keptFee = (int) $actor->id === (int) $booking->client_id ? (float) ($booking->cancellation_fee ?? 0) : 0.0;
        $amount = max(0, round($paid - $keptFee, 2));
        if ($amount <= 0) {
            return null;
        }

        $refund = $booking->refund()->create([
            'amount' => $amount,
            'status' => BookingRefund::DUE,
            'due_by' => now()->addDays($this->days()),
        ]);

        event(new BookingRefundChanged($refund, 'opened', $actor));

        return $refund;
    }

    /** The provider sent the money back and shows it ([image] is required proof). */
    public function markSent(User $provider, Booking $booking, ?string $reference, UploadedFile $image): BookingRefund
    {
        $stored = PrivateImages::store([$image], BookingRefund::DISK, 'refunds/'.$booking->id);

        try {
            return $this->transaction(function () use ($provider, $booking, $reference, $stored): BookingRefund {
                $refund = $this->locked($booking);
                $this->assertStatus($refund, [BookingRefund::DUE, BookingRefund::NOT_RECEIVED], 'This refund is not waiting to be returned.');

                $old = $refund->proof_path;
                $refund->update([
                    'status' => BookingRefund::SENT,
                    'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                    'proof_path' => $stored[0]['path'],
                    'sent_at' => now(),
                    'customer_note' => null,
                ]);
                // A new proof replaces one the customer said was wrong.
                PrivateImages::deleteAfterCommit(BookingRefund::DISK, $old);

                event(new BookingRefundChanged($refund, 'sent', $provider));

                return $refund;
            });
        } catch (\Throwable $exception) {
            PrivateImages::delete(BookingRefund::DISK, $stored);

            throw $exception;
        }
    }

    /** The customer got the money back: the refund is recorded on the booking. */
    public function confirm(User $customer, Booking $booking): BookingRefund
    {
        return $this->transaction(function () use ($customer, $booking): BookingRefund {
            $refund = $this->locked($booking);
            $this->assertStatus($refund, [BookingRefund::SENT], 'There is no returned payment to confirm yet.');

            $refund->update(['status' => BookingRefund::CONFIRMED, 'confirmed_at' => now()]);
            $this->recordOnBooking($refund, $customer);

            event(new BookingRefundChanged($refund, 'confirmed', $customer));

            return $refund;
        });
    }

    /** The customer says the money did not arrive; administrators step in. */
    public function reportNotReceived(User $customer, Booking $booking, string $note): BookingRefund
    {
        return $this->transaction(function () use ($customer, $booking, $note): BookingRefund {
            $refund = $this->locked($booking);
            $this->assertStatus($refund, [BookingRefund::SENT], 'You can report this once the provider says they returned it.');

            $refund->update([
                'status' => BookingRefund::NOT_RECEIVED,
                'customer_note' => trim($note),
                'escalated_at' => now(),
            ]);

            event(new BookingRefundChanged($refund, 'not_received', $customer));

            return $refund;
        });
    }

    /**
     * An administrator settles the case: `returned` records the refund (as if
     * the customer had confirmed it); `not_returned` keeps the provider held
     * back and is the basis for suspending or banning them.
     */
    public function resolve(User $admin, Booking $booking, string $resolution, string $note): BookingRefund
    {
        return $this->transaction(function () use ($admin, $booking, $resolution, $note): BookingRefund {
            $refund = $this->locked($booking);
            if ($refund->status === BookingRefund::CONFIRMED) {
                throw new ApiException('The customer already confirmed this refund.', 409);
            }

            $wasRecorded = $refund->resolution === 'returned';
            $refund->update([
                'status' => BookingRefund::RESOLVED,
                'resolution' => $resolution,
                'resolution_note' => trim($note),
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ]);

            if ($resolution === 'returned' && ! $wasRecorded) {
                $this->recordOnBooking($refund, $admin);
            }

            event(new BookingRefundChanged($refund, 'resolved', $admin));

            return $refund;
        });
    }

    /**
     * Refunds past their deadline that administrators have not been told
     * about: each is flagged once. Run by the scheduler; the provider block
     * itself does not wait for it (it reads the deadline directly).
     *
     * @return int how many were escalated
     */
    public function escalateOverdue(): int
    {
        $count = 0;

        BookingRefund::query()
            ->where('status', BookingRefund::DUE)
            ->where('due_by', '<', now())
            ->whereNull('escalated_at')
            ->each(function (BookingRefund $refund) use (&$count): void {
                $refund->update(['escalated_at' => now()]);
                event(new BookingRefundChanged($refund, 'overdue'));
                $count++;
            });

        return $count;
    }

    public function proof(Booking $booking): StreamedResponse
    {
        $refund = $booking->refund()->first();
        abort_unless($refund?->proof_path !== null, 404);

        return PrivateImages::stream(BookingRefund::DISK, $refund->proof_path);
    }

    /** The refund also shows on the booking: payment_status refunded or partly. */
    private function recordOnBooking(BookingRefund $refund, User $actor): void
    {
        $booking = Booking::query()->lockForUpdate()->findOrFail($refund->booking_id);
        $old = $booking->payment_status;
        $refunded = round((float) $booking->refunded_amount + (float) $refund->amount, 2);

        $booking->update([
            'refunded_amount' => $refunded,
            'refunded_at' => now(),
            'refund_reason' => 'Payment returned after the booking was cancelled.',
            'payment_status' => $refunded >= round((float) $booking->amount_paid, 2) ? 'refunded' : 'partially_refunded',
        ]);

        event(new BookingPaymentRecorded($booking, $actor, 'refunded', $old, (float) $refund->amount));
    }

    private function locked(Booking $booking): BookingRefund
    {
        return BookingRefund::query()->where('booking_id', $booking->id)->lockForUpdate()->first()
            ?? throw new ApiException('Nothing has to be returned on this booking.', 404);
    }

    /** @param  array<int, string>  $allowed */
    private function assertStatus(BookingRefund $refund, array $allowed, string $message): void
    {
        if (! in_array($refund->status, $allowed, true)) {
            throw new ApiException($message, 409, errors: ['status' => ['The refund is '.$refund->status.'.']]);
        }
    }

    private function days(): int
    {
        return max(1, (int) $this->settings->value('booking', 'downpayment_refund_days'));
    }
}

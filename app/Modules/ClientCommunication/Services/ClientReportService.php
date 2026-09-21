<?php

namespace App\Modules\ClientCommunication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Message;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Complaints filed from the mobile app.
 *
 * Three things can be reported, each a reportable type administrators can act
 * on from the admin console:
 *
 * - the other party on one of the reporter's bookings (a User — warn, suspend
 *   or ban);
 * - a published review (a Review — hide or remove), because reviews are public;
 * - a message the reporter received (a Message — remove).
 */
class ClientReportService extends BaseService
{
    /** @var array<int, class-string<Model>> */
    private const SUBJECT_TYPES = [User::class, Review::class, Message::class];

    public function index(User $reporter, array $filters): LengthAwarePaginator
    {
        return Report::query()
            ->where('reporter_id', $reporter->id)
            ->whereIn('reportable_type', self::SUBJECT_TYPES)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with(['reportable' => fn (MorphTo $morph) => $this->loadSubjects($morph)])
            ->latest()
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));
    }

    public function show(User $reporter, Report $report): Report
    {
        return Report::query()
            ->where('reporter_id', $reporter->id)
            ->whereKey($report->id)
            ->with(['reportable' => fn (MorphTo $morph) => $this->loadSubjects($morph)])
            ->firstOrFail();
    }

    public function create(User $reporter, array $data): Report
    {
        [$subject, $context] = match (true) {
            ! empty($data['review_id']) => [$this->review($reporter, (int) $data['review_id']), 'Review'],
            ! empty($data['message_id']) => [$this->message($reporter, (int) $data['message_id']), 'Message'],
            default => $this->bookingCounterpart($reporter, (int) $data['booking_id']),
        };

        try {
            return $this->transaction(function () use ($reporter, $subject, $context, $data): Report {
                $this->assertNoOpenReport($reporter, $subject);

                $report = Report::query()->create([
                    'reportable_type' => $subject::class,
                    'reportable_id' => $subject->getKey(),
                    'reporter_id' => $reporter->id,
                    'reason' => $data['reason'],
                    // The reports table has no booking column, so the context a
                    // moderator needs travels in the description.
                    'description' => $context.': '.trim($data['description']),
                    'status' => 'pending',
                ]);

                // The admin Reviews page filters on this flag; without it a
                // reported review would never surface there.
                if ($subject instanceof Review) {
                    $subject->update(['is_reported' => true, 'report_reason' => $data['reason']]);
                }

                return $report;
            });
        } catch (QueryException $exception) {
            // Two submits racing past the check above meet the database's
            // one-open-report index; the loser gets the same answer as a check.
            if ($this->isDuplicateOpenReport($exception)) {
                throw $this->duplicate();
            }

            throw $exception;
        }
    }

    /**
     * The other party on the booking, and proof the reporter was on it. A
     * booking the caller had nothing to do with is reported as missing rather
     * than forbidden, so booking ids cannot be probed.
     *
     * @return array{0: User, 1: string}
     */
    private function bookingCounterpart(User $reporter, int $bookingId): array
    {
        $booking = Booking::query()->findOrFail($bookingId);
        $context = 'Booking '.$booking->booking_number;

        if ((int) $booking->client_id === (int) $reporter->id) {
            $providerUserId = ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id');

            return [
                User::query()->find($providerUserId)
                    ?? throw new ApiException('The provider on this booking is no longer available to report.', 422),
                $context,
            ];
        }

        $isProviderOnBooking = ProviderProfile::query()
            ->whereKey($booking->provider_id)
            ->where('user_id', $reporter->id)
            ->exists();

        if (! $isProviderOnBooking) {
            throw new ApiException('Booking not found.', 404);
        }

        return [
            User::query()->find($booking->client_id)
                ?? throw new ApiException('The customer on this booking is no longer available to report.', 422),
            $context,
        ];
    }

    /**
     * Any published review may be reported — reviews are public — except one's
     * own, which the author can simply edit.
     */
    private function review(User $reporter, int $reviewId): Review
    {
        $review = Review::query()->whereKey($reviewId)->where('status', 'active')->first()
            ?? throw new ApiException('Review not found.', 404);

        if ((int) $review->reviewer_id === (int) $reporter->id) {
            throw new ApiException(
                'You cannot report your own review. Edit it instead.',
                422,
                errors: ['review_id' => ['You cannot report your own review.']],
            );
        }

        return $review;
    }

    /**
     * Only a message the reporter received. Their own messages, and other
     * people's conversations, are reported as missing.
     */
    private function message(User $reporter, int $messageId): Message
    {
        return Message::query()
            ->whereKey($messageId)
            ->where('receiver_id', $reporter->id)
            ->where('status', 'active')
            ->first()
            ?? throw new ApiException('Message not found.', 404);
    }

    /**
     * One open report per reporter per subject. A second one while the first
     * is still being looked at is almost always a double submit, and would
     * only split the moderator's attention.
     */
    private function assertNoOpenReport(User $reporter, Model $subject): void
    {
        $hasOpen = Report::query()
            ->where('reporter_id', $reporter->id)
            ->where('reportable_type', $subject::class)
            ->where('reportable_id', $subject->getKey())
            ->whereIn('status', ['pending', 'investigating'])
            ->exists();

        if ($hasOpen) {
            throw $this->duplicate();
        }
    }

    private function duplicate(): ApiException
    {
        return new ApiException(
            'You already have a report about this under review. Our team will follow up on it.',
            409,
            errors: ['report' => ['An earlier report about this is still open.']],
        );
    }

    /**
     * A unique violation on the reports table. PostgreSQL names the index in
     * the message (SQLSTATE 23505); SQLite only lists the columns (23000).
     */
    private function isDuplicateOpenReport(QueryException $exception): bool
    {
        $message = $exception->getMessage();

        return in_array((string) $exception->getCode(), ['23505', '23000'], true)
            && (str_contains($message, 'reports_one_open_per_subject')
                || str_contains($message, 'reports.reporter_id'));
    }

    /**
     * Only what the reporter may see: names already visible to them in the app,
     * and the text of the review or message they reported.
     */
    private function loadSubjects(MorphTo $morph): void
    {
        $morph->morphWith([
            Review::class => ['reviewer:id,name'],
            Message::class => ['sender:id,name'],
        ]);
    }
}

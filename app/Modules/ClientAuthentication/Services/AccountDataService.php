<?php

namespace App\Modules\ClientAuthentication\Services;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Modules\Reviews\Models\Review;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Services\BaseService;

/**
 * What an account holder may do with their own data: take a copy of it, and
 * close the account.
 */
class AccountDataService extends BaseService
{
    /** Statuses that mean a job is still live and owes someone something. */
    public function __construct(private readonly ClientPreferenceService $preferenceService) {}

    /**
     * Everything the platform holds about this account, in one readable
     * structure. Own data only — nothing about the people they dealt with
     * beyond the names already visible to them in the app.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        $providerProfileId = $user->providerProfile?->id;

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'birthday' => $user->birthday?->toDateString(),
                'account_type' => $user->user_type,
                'status' => $user->status,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'preferences' => $this->preferenceService->forUser($user)->only([
                'booking_notifications', 'service_notifications', 'message_notifications',
                'announcement_notifications', 'private_profile', 'activity_personalization',
                'reduce_motion', 'theme',
            ]),
            'provider_profile' => $this->providerProfile($user),
            'bookings' => Booking::query()
                ->where('client_id', $user->id)
                ->when($providerProfileId, fn ($query) => $query->orWhere('provider_id', $providerProfileId))
                ->with('service:id,title')
                ->latest()
                ->get()
                ->map(fn (Booking $booking): array => [
                    'booking_number' => $booking->booking_number,
                    'service' => $booking->service?->title,
                    'status' => $booking->status,
                    'payment_status' => $booking->payment_status,
                    'total_price' => $booking->total_price,
                    'currency' => $booking->currency,
                    'scheduled_date' => $booking->scheduled_date?->toIso8601String(),
                    'service_address' => $booking->service_address,
                    'client_notes' => $booking->client_notes,
                    'created_at' => $booking->created_at?->toIso8601String(),
                ])->values()->all(),
            'favorite_providers' => $user->favoriteProviders()
                ->orderByPivot('created_at', 'desc')
                ->get(['provider_profiles.id', 'provider_profiles.business_name'])
                ->map(fn ($provider): array => [
                    'business_name' => $provider->business_name,
                    'saved_at' => $provider->pivot->created_at?->toIso8601String(),
                ])->values()->all(),
            'reviews' => Review::query()
                ->where('reviewer_id', $user->id)
                ->with('service:id,title')
                ->latest()
                ->get()
                ->map(fn (Review $review): array => [
                    'service' => $review->service?->title,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at?->toIso8601String(),
                ])->values()->all(),
            'reports_filed' => Report::query()
                ->where('reporter_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (Report $report): array => [
                    'reason' => $report->reason,
                    'description' => $report->description,
                    'status' => $report->status,
                    'created_at' => $report->created_at?->toIso8601String(),
                ])->values()->all(),
            'support_tickets' => SupportTicket::query()
                ->where('requester_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (SupportTicket $ticket): array => [
                    'ticket_number' => $ticket->ticket_number,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    'status' => $ticket->status,
                    'created_at' => $ticket->created_at?->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function providerProfile(User $user): ?array
    {
        $profile = $user->providerProfile;

        if (! $profile instanceof ProviderProfile) {
            return null;
        }

        return [
            'business_name' => $profile->business_name,
            'specialization' => $profile->specialization,
            'experience_years' => $profile->experience_years,
            'bio' => $profile->bio,
            'verification_status' => $profile->verification_status,
            'average_rating' => $profile->average_rating,
            'total_reviews' => $profile->total_reviews,
        ];
    }
}

<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingRules;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

final class CreateClientBookingAction extends BaseAction
{
    public function __construct(private readonly BookingRules $rules) {}

    public function handle(User $client, Service $service, array $data, ?string $idempotencyKey): Booking
    {
        $servicePrice = round((float) $service->price, 2);
        // The commission is inclusive: it comes out of the advertised price,
        // so the customer pays $servicePrice and the provider keeps the rest.
        // Snapshotted onto the booking below, because the administrator may
        // change the tiers later and this booking must not change with them.
        $commission = $this->rules->commission($servicePrice);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $service->provider_id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            // total_price is the advertised service amount — what the
            // customer pays. platform_fee is SkillServe's share of it, and
            // the provider receives the difference.
            'total_price' => $servicePrice,
            'service_price' => $servicePrice,
            'platform_fee' => $commission['commission_amount'],
            'commission_rate' => $commission['rate'],
            'commission_tier_id' => $commission['tier_id'],
            'currency' => $service->currency,
            'payment_method' => $data['payment_method'] ?? null,
            'client_notes' => $data['client_notes'] ?? null,
            'service_address' => $data['service_address'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_end_date' => $data['scheduled_end_date'],
            'client_idempotency_key' => $idempotencyKey,
        ]);
    }
}

<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Services\Models\Service;
use App\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

final class CreateClientBookingAction extends BaseAction
{
    public function handle(User $client, Service $service, array $data, ?string $idempotencyKey): Booking
    {
        $servicePrice = round((float) $service->price, 2);
        $platformFee = round($servicePrice * 0.10, 2);

        return Booking::create([
            'service_id' => $service->id,
            'client_id' => $client->id,
            'provider_id' => $service->provider_id,
            'booking_number' => 'BK-'.strtoupper(Str::random(12)),
            'status' => 'pending',
            'payment_status' => 'unpaid',
            // total_price is the advertised service amount; the platform fee
            // remains separately recorded for settlement/reporting.
            'total_price' => $servicePrice,
            'service_price' => $servicePrice,
            'platform_fee' => $platformFee,
            'currency' => $service->currency,
            'payment_method' => $data['payment_method'] ?? null,
            'client_notes' => $data['client_notes'] ?? null,
            'scheduled_date' => $data['scheduled_date'],
            'scheduled_end_date' => $data['scheduled_end_date'],
            'client_idempotency_key' => $idempotencyKey,
        ]);
    }
}

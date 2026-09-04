<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Services\Models\Service;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds a realistic set of bookings so the Booking Management screens have
 * data to list, search, filter, view, cancel, and manage disputes.
 *
 * Idempotent — re-running only tops up what is missing.
 */
class BookingSeeder extends Seeder
{
    use WithoutModelEvents;

    private const TARGET_BOOKINGS = 50;

    private const BOOKINGS = [
        // ── Pending ─────────────────────────────────────────────────
        ['svc' => 'Emergency Pipe Repair', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 150, 'sp' => 150, 'fee' => 15, 'cn' => 'Kitchen faucet is leaking badly.', 'days' => 3],
        ['svc' => 'Water Heater Installation', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 850, 'sp' => 850, 'fee' => 85, 'cn' => 'Need tankless water heater installed.', 'days' => 5],
        ['svc' => 'Residential Panel Upgrade', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 2200, 'sp' => 2200, 'fee' => 220, 'cn' => 'Upgrading to 200 amp for EV charger.', 'days' => 7],
        ['svc' => 'Deep Tissue Massage', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 160, 'sp' => 160, 'fee' => 16, 'cn' => 'Focus on lower back and shoulders.', 'days' => 2],
        ['svc' => 'Interior Room Painting', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 300, 'sp' => 300, 'fee' => 30, 'cn' => 'Two bedrooms need painting.', 'days' => 6],
        ['svc' => 'Sat Math Prep Course', 'st' => 'pending', 'ps' => 'unpaid', 'price' => 250, 'sp' => 250, 'fee' => 25, 'cn' => '5 sessions for upcoming SAT.', 'days' => 4],

        // ── Confirmed ───────────────────────────────────────────────
        ['svc' => 'EV Charger Installation', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 950, 'sp' => 950, 'fee' => 95, 'cn' => 'Tesla Wall Connector purchased.', 'pn' => 'Confirmed circuit assessment.', 'days' => 4, 'cf' => true],
        ['svc' => 'Full Brake Service', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 350, 'sp' => 350, 'fee' => 35, 'cn' => 'Front and rear brakes on 2022 Honda Civic.', 'pn' => 'Parts ordered.', 'days' => 2, 'cf' => true],
        ['svc' => 'Laptop Screen Replacement', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 180, 'sp' => 180, 'fee' => 18, 'cn' => 'MacBook Pro 14-inch cracked screen.', 'pn' => 'Screen in stock.', 'days' => 1, 'cf' => true],
        ['svc' => 'Small Business Network Setup', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 1500, 'sp' => 1500, 'fee' => 150, 'cn' => 'Office with 30 devices.', 'pn' => 'Site survey complete.', 'days' => 3, 'cf' => true],
        ['svc' => 'Keratin Smoothing Treatment', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 300, 'sp' => 300, 'fee' => 30, 'cn' => 'Long-lasting frizz elimination.', 'pn' => 'Product reserved.', 'days' => 5, 'cf' => true],
        ['svc' => 'Private Vinyasa Yoga Session', 'st' => 'confirmed', 'ps' => 'paid', 'price' => 70, 'sp' => 70, 'fee' => 7, 'cn' => 'First-time student, beginner level.', 'pn' => 'Welcome email sent.', 'days' => 2, 'cf' => true],

        // ── Active ──────────────────────────────────────────────────
        ['svc' => 'Interior Room Painting', 'st' => 'active', 'ps' => 'paid', 'price' => 480, 'sp' => 480, 'fee' => 48, 'cn' => 'Living room and hallway.', 'pn' => 'Prep work started.', 'days' => 0, 'cf' => true, 'st2' => true],
        ['svc' => 'Deep Home Cleaning', 'st' => 'active', 'ps' => 'paid', 'price' => 225, 'sp' => 225, 'fee' => 22.50, 'cn' => '3-bedroom house deep clean.', 'pn' => 'Team on-site.', 'days' => 0, 'cf' => true, 'st2' => true],
        ['svc' => 'Home Wi-Fi Optimization', 'st' => 'active', 'ps' => 'paid', 'price' => 120, 'sp' => 120, 'fee' => 12, 'cn' => 'Dead zones in upstairs bedrooms.', 'pn' => 'Router repositioned.', 'days' => 0, 'cf' => true, 'st2' => true],

        // ── Completed ───────────────────────────────────────────────
        ['svc' => 'Emergency Roadside Assistance', 'st' => 'completed', 'ps' => 'paid', 'price' => 75, 'sp' => 75, 'fee' => 7.50, 'cn' => 'Flat tire on I-35.', 'pn' => 'Tire changed.', 'days' => -10, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Balayage Hair Coloring', 'st' => 'completed', 'ps' => 'paid', 'price' => 180, 'sp' => 180, 'fee' => 18, 'cn' => 'Natural sun-kissed look.', 'pn' => 'Great results.', 'days' => -5, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Premium Hand Car Wash', 'st' => 'completed', 'ps' => 'paid', 'price' => 40, 'sp' => 40, 'fee' => 4, 'cn' => 'Black sedan, extra wheel attention.', 'pn' => 'Done.', 'days' => -7, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Engine Diagnostic', 'st' => 'completed', 'ps' => 'paid', 'price' => 100, 'sp' => 100, 'fee' => 10, 'cn' => 'Check engine light code P0420.', 'pn' => 'Catalytic converter issue.', 'days' => -3, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Gel Manicure & Nail Art', 'st' => 'completed', 'ps' => 'paid', 'price' => 45, 'sp' => 45, 'fee' => 4.50, 'cn' => 'Floral design on accent nails.', 'pn' => 'Client loved it.', 'days' => -14, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Data Recovery Service', 'st' => 'completed', 'ps' => 'paid', 'price' => 250, 'sp' => 250, 'fee' => 25, 'cn' => 'External drive not mounting.', 'pn' => '98% recovered.', 'days' => -2, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => '12-Week Weight Loss Program', 'st' => 'completed', 'ps' => 'paid', 'price' => 660, 'sp' => 660, 'fee' => 66, 'cn' => 'Goal: lose 15 lbs.', 'pn' => 'Lost 18 lbs!', 'days' => -30, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Wedding Photography Package', 'st' => 'completed', 'ps' => 'paid', 'price' => 2500, 'sp' => 2500, 'fee' => 250, 'cn' => 'Outdoor wedding, 150 guests.', 'pn' => 'Gallery delivered.', 'days' => -20, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Corporate Wellness Yoga', 'st' => 'completed', 'ps' => 'paid', 'price' => 200, 'sp' => 200, 'fee' => 20, 'cn' => '12-person team session.', 'pn' => 'Great group energy.', 'days' => -15, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Business English Workshop', 'st' => 'completed', 'ps' => 'paid', 'price' => 120, 'sp' => 120, 'fee' => 12, 'cn' => 'Presentation skills focus.', 'pn' => 'Excellent progress.', 'days' => -8, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'EV Charger Installation', 'st' => 'completed', 'ps' => 'paid', 'price' => 950, 'sp' => 950, 'fee' => 95, 'cn' => 'Level 2 charger for new EV.', 'pn' => 'Installation complete.', 'days' => -12, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Interior Detailing', 'st' => 'completed', 'ps' => 'paid', 'price' => 120, 'sp' => 120, 'fee' => 12, 'cn' => 'Full interior shampoo.', 'pn' => 'Like new.', 'days' => -6, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Luxury Facial Treatment', 'st' => 'completed', 'ps' => 'paid', 'price' => 120, 'sp' => 120, 'fee' => 12, 'cn' => 'Sensitive skin type.', 'pn' => 'Glowing skin.', 'days' => -4, 'cf' => true, 'st2' => true, 'cp' => true],

        // ── Cancelled ───────────────────────────────────────────────
        ['svc' => 'Cabinet Refinishing', 'st' => 'cancelled', 'ps' => 'refunded', 'price' => 1800, 'sp' => 1800, 'fee' => 180, 'cr' => 'Kitchen renovation scope changed.', 'days' => -5, 'cf' => true],
        ['svc' => 'Sports Injury Rehabilitation', 'st' => 'cancelled', 'ps' => 'refunded', 'price' => 330, 'sp' => 330, 'fee' => 33, 'cr' => 'Physiotherapist recommended rest first.', 'days' => -8, 'cf' => true],
        ['svc' => 'Corporate Event Photography', 'st' => 'cancelled', 'ps' => 'refunded', 'price' => 360, 'sp' => 360, 'fee' => 36, 'cr' => 'Event postponed to next quarter.', 'days' => -3, 'cf' => true],
        ['svc' => 'Vietnamese Conversation Classes', 'st' => 'cancelled', 'ps' => 'refunded', 'price' => 180, 'sp' => 180, 'fee' => 18, 'cr' => 'Schedule conflict.', 'days' => -10, 'cf' => true],
        ['svc' => 'Home Wi-Fi Optimization', 'st' => 'cancelled', 'ps' => 'unpaid', 'price' => 120, 'sp' => 120, 'fee' => 12, 'cr' => 'Resolved the issue independently.', 'days' => -6],

        // ── Disputed ────────────────────────────────────────────────
        ['svc' => 'Flatbed Towing', 'st' => 'disputed', 'ps' => 'paid', 'price' => 150, 'sp' => 150, 'fee' => 15, 'cn' => 'Luxury car tow from downtown.', 'dr' => 'Vehicle arrived with a scratch on the bumper.', 'ds' => 'pending', 'days' => -1, 'cf' => true, 'st2' => true],
        ['svc' => 'Deep Home Cleaning', 'st' => 'disputed', 'ps' => 'paid', 'price' => 225, 'sp' => 225, 'fee' => 22.50, 'cn' => 'Full house deep clean.', 'dr' => 'Bathrooms were not cleaned to expected standard.', 'ds' => 'investigated', 'days' => -3, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'BBQ Catering Package', 'st' => 'disputed', 'ps' => 'paid', 'price' => 700, 'sp' => 700, 'fee' => 70, 'cn' => 'Party of 20 guests.', 'dr' => 'Food was cold on arrival. Not enough portions.', 'ds' => 'resolved', 'dr2' => 'Partial refund of $200 issued. Provider coached on food transport.', 'days' => -7, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Calculus Tutoring', 'st' => 'disputed', 'ps' => 'paid', 'price' => 165, 'sp' => 165, 'fee' => 16.50, 'cn' => '3 sessions for mid-term prep.', 'dr' => 'Tutor cancelled 2 of 3 sessions.', 'ds' => 'rejected', 'dr2' => 'Investigation found tutor had valid emergencies. No violation.', 'days' => -5, 'cf' => true, 'st2' => true, 'cp' => true],

        // ── More completed (varied services) ────────────────────────
        ['svc' => 'Emergency Pipe Repair', 'st' => 'completed', 'ps' => 'paid', 'price' => 150, 'sp' => 150, 'fee' => 15, 'pn' => 'Pipe fixed.', 'days' => -18, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Water Heater Installation', 'st' => 'completed', 'ps' => 'paid', 'price' => 850, 'sp' => 850, 'fee' => 85, 'pn' => 'Installed and tested.', 'days' => -25, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Residential Panel Upgrade', 'st' => 'completed', 'ps' => 'paid', 'price' => 2200, 'sp' => 2200, 'fee' => 220, 'pn' => 'Passed inspection.', 'days' => -22, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Full Brake Service', 'st' => 'completed', 'ps' => 'paid', 'price' => 350, 'sp' => 350, 'fee' => 35, 'pn' => 'Brakes feeling great.', 'days' => -16, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Laptop Screen Replacement', 'st' => 'completed', 'ps' => 'paid', 'price' => 180, 'sp' => 180, 'fee' => 18, 'pn' => 'Screen replaced same day.', 'days' => -11, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Dry Needling Therapy', 'st' => 'completed', 'ps' => 'paid', 'price' => 90, 'sp' => 90, 'fee' => 9, 'pn' => 'Significant pain relief.', 'days' => -9, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Post-Rehab Strength Training', 'st' => 'completed', 'ps' => 'paid', 'price' => 195, 'sp' => 195, 'fee' => 19.50, 'pn' => '12 sessions complete.', 'days' => -35, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Calculus Tutoring', 'st' => 'completed', 'ps' => 'paid', 'price' => 165, 'sp' => 165, 'fee' => 16.50, 'pn' => 'Student scored 92% on mid-term.', 'days' => -13, 'cf' => true, 'st2' => true, 'cp' => true],
        ['svc' => 'Emergency Roadside Assistance', 'st' => 'completed', 'ps' => 'paid', 'price' => 75, 'sp' => 75, 'fee' => 7.50, 'pn' => 'Jump start done.', 'days' => -19, 'cf' => true, 'st2' => true, 'cp' => true],
    ];

    public function run(): void
    {
        $existing = Booking::count();
        $missing = max(0, self::TARGET_BOOKINGS - $existing);

        if ($missing === 0) {
            $this->command?->info("Already {$existing} bookings — nothing to seed.");

            return;
        }

        $clients = User::query()->where('user_type', 'customer')->doesntHave('roles')->get();
        $services = Service::query()->where('approval_status', 'approved')->get()->keyBy('title');
        $providers = ProviderProfile::query()->get()->keyBy('id');
        $actor = User::query()->whereHas('roles')->first();

        $toCreate = array_slice(self::BOOKINGS, 0, $missing);
        $created = 0;

        foreach ($toCreate as $data) {
            $service = $services->get($data['svc']);

            if (! $service) {
                $this->command?->warn("Service \"{$data['svc']}\" not found — skipping.");

                continue;
            }

            $provider = $providers->get($service->provider_id);

            if (! $provider) {
                $this->command?->warn("Provider for service \"{$data['svc']}\" not found — skipping.");

                continue;
            }

            $client = $clients->random();

            $scheduledDate = now()->addDays($data['days']);
            $createdAt = now()->addDays($data['days'] - 30);

            Booking::create([
                'service_id' => $service->id,
                'client_id' => $client->id,
                'provider_id' => $provider->id,
                'booking_number' => 'BK-'.date('Y').'-'.str_pad((string) ($created + $existing + 1), 6, '0', STR_PAD_LEFT),
                'status' => $data['st'],
                'payment_status' => $data['ps'],
                'total_price' => $data['price'],
                'service_price' => $data['sp'],
                'platform_fee' => $data['fee'],
                'currency' => 'USD',
                'payment_method' => $data['ps'] === 'paid' ? 'credit_card' : null,
                'client_notes' => $data['cn'] ?? null,
                'provider_notes' => $data['pn'] ?? null,
                'cancellation_reason' => $data['cr'] ?? null,
                'scheduled_date' => $scheduledDate,
                'scheduled_end_date' => $scheduledDate->copy()->addHours(2),
                'confirmed_at' => ($data['cf'] ?? false) ? $createdAt->copy()->addHours(1) : null,
                'started_at' => ($data['st2'] ?? false) ? $scheduledDate : null,
                'completed_at' => ($data['cp'] ?? false) ? $scheduledDate->copy()->addHours(2) : null,
                'cancelled_at' => $data['st'] === 'cancelled' ? $createdAt->copy()->addDays(10) : null,
                'dispute_reason' => $data['dr'] ?? null,
                'disputed_at' => ($data['dr'] ?? null) ? $scheduledDate->copy()->addDays(1) : null,
                'dispute_status' => $data['ds'] ?? null,
                'dispute_resolution' => $data['dr2'] ?? null,
                'dispute_evidence' => ($data['dr'] ?? null) ? [[
                    'label' => 'Submitted dispute statement',
                    'content' => $data['dr'],
                ]] : null,
                'dispute_notes' => ($data['ds'] ?? null) === 'investigated' ? [[
                    'note' => 'Initial evidence review completed.',
                    'created_at' => now()->subDays(1)->toIso8601String(),
                    'created_by' => $actor?->id,
                ]] : null,
                'is_reviewed' => $data['st'] === 'completed' && fake()->boolean(60),
                'created_at' => $createdAt,
                'updated_at' => $createdAt->copy()->addDays(5),
            ]);

            $created++;
        }

        $this->command?->info("Seeded {$created} bookings — total: ".Booking::count().'.');
    }
}

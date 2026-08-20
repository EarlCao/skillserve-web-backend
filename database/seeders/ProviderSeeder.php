<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Providers\Models\VerificationDocument;
use App\Modules\Providers\Models\VerificationRequest;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a realistic set of service-provider accounts so the Provider
 * Management screens have data to list, search, filter and moderate.
 *
 * Each provider gets a user account (user_type "provider"), a ProviderProfile,
 * and — where appropriate — VerificationRequests with matching documents to
 * exercise every verification status the UI supports.
 *
 * Idempotent — re-running only tops up what is missing.
 */
class ProviderSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Minimum number of provider profiles to guarantee.
     */
    private const TARGET_PROVIDERS = 20;

    /**
     * Hand-crafted provider data so the demo looks realistic rather than
     * randomly generated.
     *
     * @var array<int, array{
     *   first_name: string,
     *   last_name: string,
     *   business_name: string,
     *   specialization: string,
     *   bio: string,
     *   experience_years: int,
     *   hourly_rate: float,
     *   location: string,
     *   latitude: float,
     *   longitude: float,
     *   skills: array<int, string>,
     *   certifications: array<int, string>,
     *   languages: array<int, string>,
     *   verification_status: string,
     *   is_suspended: bool,
     *   suspension_reason?: string,
     * }>
     */
    private const PROVIDERS = [
        [
            'first_name' => 'Maria',
            'last_name' => 'Garcia',
            'business_name' => 'Garcia Plumbing Solutions',
            'specialization' => 'Plumbing',
            'bio' => 'Licensed master plumber with over a decade of residential and commercial experience. Specializing in pipe installation, leak detection, and water heater repair.',
            'experience_years' => 12,
            'hourly_rate' => 85.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2672,
            'longitude' => -97.7431,
            'skills' => ['Pipe Installation', 'Leak Detection', 'Water Heater Repair', 'Sewer Line Repair'],
            'certifications' => ['Master Plumber License TX', 'OSHA 30'],
            'languages' => ['English', 'Spanish'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'James',
            'last_name' => 'Wilson',
            'business_name' => 'Wilson Electric Co.',
            'specialization' => 'Electrical',
            'bio' => 'Certified electrician providing residential rewiring, panel upgrades, and lighting installation. Committed to safety and code compliance.',
            'experience_years' => 8,
            'hourly_rate' => 90.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2676,
            'longitude' => -97.7389,
            'skills' => ['Residential Rewiring', 'Panel Upgrades', 'Lighting Installation', 'EV Charger Installation'],
            'certifications' => ['Journeyman Electrician License', 'NFPA 70E'],
            'languages' => ['English'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Aisha',
            'last_name' => 'Patel',
            'business_name' => 'SparkleClean Services',
            'specialization' => 'Home Cleaning',
            'bio' => 'Eco-friendly home and office cleaning. We use non-toxic products and pay attention to every detail.',
            'experience_years' => 5,
            'hourly_rate' => 45.00,
            'location' => 'San Antonio, TX',
            'latitude' => 29.4241,
            'longitude' => -98.4936,
            'skills' => ['Deep Cleaning', 'Move-in/Move-out', 'Eco-Friendly Products', 'Office Cleaning'],
            'certifications' => ['Green Cleaning Certified'],
            'languages' => ['English', 'Hindi'],
            'verification_status' => 'pending',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Carlos',
            'last_name' => 'Rodriguez',
            'business_name' => 'Rodriguez Auto Care',
            'specialization' => 'Car Repair',
            'bio' => 'Full-service auto repair shop. From oil changes to engine rebuilds, we handle it all with ASE-certified technicians.',
            'experience_years' => 15,
            'hourly_rate' => 75.00,
            'location' => 'Houston, TX',
            'latitude' => 29.7604,
            'longitude' => -95.3698,
            'skills' => ['Engine Repair', 'Brake Service', 'Transmission Repair', 'Diagnostics'],
            'certifications' => ['ASE Master Technician', 'EPA 608'],
            'languages' => ['English', 'Spanish'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Linda',
            'last_name' => 'Chen',
            'business_name' => 'Chen Hair Studio',
            'specialization' => 'Hair Care',
            'bio' => 'Creative hairstylist offering cuts, color, and treatments for all hair types. Published in regional beauty magazines.',
            'experience_years' => 10,
            'hourly_rate' => 65.00,
            'location' => 'Dallas, TX',
            'latitude' => 32.7767,
            'longitude' => -96.7970,
            'skills' => ['Hair Cutting', 'Coloring', 'Balayage', 'Keratin Treatments'],
            'certifications' => ['Texas Cosmetology License'],
            'languages' => ['English', 'Mandarin'],
            'verification_status' => 'rejected',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'David',
            'last_name' => 'Kim',
            'business_name' => 'KimTech Solutions',
            'specialization' => 'Computer Repair',
            'bio' => 'IT specialist offering computer repair, data recovery, and network setup for homes and small businesses.',
            'experience_years' => 7,
            'hourly_rate' => 70.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2671,
            'longitude' => -97.7432,
            'skills' => ['Data Recovery', 'Virus Removal', 'Hardware Repair', 'Network Setup'],
            'certifications' => ['CompTIA A+', 'CompTIA Network+'],
            'languages' => ['English', 'Korean'],
            'verification_status' => 'verified',
            'is_suspended' => true,
            'suspension_reason' => 'Multiple customer complaints regarding no-shows and incomplete work.',
        ],
        [
            'first_name' => 'Sarah',
            'last_name' => 'Johnson',
            'business_name' => 'FitLife Personal Training',
            'specialization' => 'Personal Training',
            'bio' => 'NASM-certified personal trainer. Specializing in weight loss, strength training, and post-rehab fitness.',
            'experience_years' => 6,
            'hourly_rate' => 55.00,
            'location' => 'Fort Worth, TX',
            'latitude' => 32.7555,
            'longitude' => -97.3308,
            'skills' => ['Weight Loss', 'Strength Training', 'HIIT', 'Post-Rehab'],
            'certifications' => ['NASM-CPT', 'First Aid/CPR'],
            'languages' => ['English'],
            'verification_status' => 'additional_info_required',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Michael',
            'last_name' => 'Brown',
            'business_name' => 'Brown Painting Co.',
            'specialization' => 'Painting',
            'bio' => 'Professional painter for interiors and exteriors. Quality finishes with minimal disruption.',
            'experience_years' => 20,
            'hourly_rate' => 60.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2678,
            'longitude' => -97.7415,
            'skills' => ['Interior Painting', 'Exterior Painting', 'Drywall Repair', 'Cabinet Refinishing'],
            'certifications' => ['EPA Lead-Safe Certified'],
            'languages' => ['English'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Elena',
            'last_name' => 'Morales',
            'business_name' => 'Morales Massage Therapy',
            'specialization' => 'Massage',
            'bio' => 'Licensed massage therapist offering deep tissue, Swedish, and sports massage. Home visits available.',
            'experience_years' => 4,
            'hourly_rate' => 80.00,
            'location' => 'San Antonio, TX',
            'latitude' => 29.4242,
            'longitude' => -98.4935,
            'skills' => ['Deep Tissue', 'Swedish Massage', 'Sports Massage', 'Trigger Point Therapy'],
            'certifications' => ['Texas Massage Therapist License'],
            'languages' => ['English', 'Spanish'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Robert',
            'last_name' => 'Taylor',
            'business_name' => 'Taylor Photography',
            'specialization' => 'Photography',
            'bio' => 'Event and portrait photographer capturing your special moments. Available for weddings, corporate events, and family sessions.',
            'experience_years' => 9,
            'hourly_rate' => 120.00,
            'location' => 'Houston, TX',
            'latitude' => 29.7605,
            'longitude' => -95.3697,
            'skills' => ['Event Photography', 'Portrait Photography', 'Photo Editing', 'Drone Photography'],
            'certifications' => ['Professional Photographers of America'],
            'languages' => ['English'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Priya',
            'last_name' => 'Sharma',
            'business_name' => 'Sharma Yoga Studio',
            'specialization' => 'Yoga',
            'bio' => 'RYT-500 yoga instructor teaching vinyasa, yin, and restorative yoga. Private and group sessions.',
            'experience_years' => 11,
            'hourly_rate' => 70.00,
            'location' => 'Dallas, TX',
            'latitude' => 32.7768,
            'longitude' => -96.7969,
            'skills' => ['Vinyasa Yoga', 'Yin Yoga', 'Restorative Yoga', 'Prenatal Yoga'],
            'certifications' => ['RYT-500', 'Yoga Alliance'],
            'languages' => ['English', 'Hindi', 'Sanskrit'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Thomas',
            'last_name' => 'Anderson',
            'business_name' => 'Anderson Catering',
            'specialization' => 'Catering',
            'bio' => 'Full-service catering for events of all sizes. From casual BBQ to elegant plated dinners.',
            'experience_years' => 13,
            'hourly_rate' => 95.00,
            'location' => 'Fort Worth, TX',
            'latitude' => 32.7556,
            'longitude' => -97.3307,
            'skills' => ['Menu Planning', 'Buffet Service', 'Plated Dinners', 'Dietary Accommodations'],
            'certifications' => ['ServSafe Manager', 'TABC Certification'],
            'languages' => ['English'],
            'verification_status' => 'pending',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Jennifer',
            'last_name' => 'Lee',
            'business_name' => 'Lee Math Tutoring',
            'specialization' => 'Math Tutoring',
            'bio' => 'High school math teacher offering tutoring in algebra, geometry, and calculus. Patient and encouraging approach.',
            'experience_years' => 8,
            'hourly_rate' => 50.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2675,
            'longitude' => -97.7430,
            'skills' => ['Algebra', 'Geometry', 'Calculus', 'SAT Prep'],
            'certifications' => ['Texas Teaching Certificate', 'Mathematics Specialist'],
            'languages' => ['English', 'Vietnamese'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Ahmed',
            'last_name' => 'Hassan',
            'business_name' => 'Hassan Towing Service',
            'specialization' => 'Towing',
            'bio' => '24/7 roadside assistance and towing. Fast response times across the greater Houston area.',
            'experience_years' => 6,
            'hourly_rate' => 0.00,
            'location' => 'Houston, TX',
            'latitude' => 29.7603,
            'longitude' => -95.3699,
            'skills' => ['Flatbed Towing', 'Emergency Roadside', 'Accident Recovery', 'Long-Distance Towing'],
            'certifications' => ['TDLR Tow Operator License', 'First Aid/CPR'],
            'languages' => ['English', 'Arabic'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Rachel',
            'last_name' => 'Green',
            'business_name' => 'Green Nail Art',
            'specialization' => 'Nail Care',
            'bio' => 'Creative nail artist specializing in gel nails, nail art, and spa pedicures.',
            'experience_years' => 3,
            'hourly_rate' => 40.00,
            'location' => 'Dallas, TX',
            'latitude' => 32.7766,
            'longitude' => -96.7971,
            'skills' => ['Gel Nails', 'Nail Art', 'Spa Pedicure', 'Acrylic Nails'],
            'certifications' => ['Texas Cosmetology License - Manicurist'],
            'languages' => ['English'],
            'verification_status' => 'unverified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'William',
            'last_name' => 'Davis',
            'business_name' => 'Davis Network Solutions',
            'specialization' => 'Network Setup',
            'bio' => 'Network engineer helping small businesses set up secure, reliable Wi-Fi and wired networks.',
            'experience_years' => 10,
            'hourly_rate' => 100.00,
            'location' => 'San Antonio, TX',
            'latitude' => 29.4240,
            'longitude' => -98.4937,
            'skills' => ['Wi-Fi Setup', 'Firewall Configuration', 'VPN Setup', 'Server Maintenance'],
            'certifications' => ['CCNA', 'CompTIA Security+'],
            'languages' => ['English'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Olivia',
            'last_name' => 'Martinez',
            'business_name' => 'Martinez Spa & Wellness',
            'specialization' => 'Spa',
            'bio' => 'Luxurious spa experiences including facials, body wraps, and aromatherapy. Relaxation guaranteed.',
            'experience_years' => 7,
            'hourly_rate' => 85.00,
            'location' => 'Austin, TX',
            'latitude' => 30.2674,
            'longitude' => -97.7433,
            'skills' => ['Facials', 'Body Wraps', 'Aromatherapy', 'Hot Stone Massage'],
            'certifications' => ['Licensed Esthetician', 'Aromatherapy Certified'],
            'languages' => ['English', 'Spanish'],
            'verification_status' => 'additional_info_required',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Daniel',
            'last_name' => 'Thompson',
            'business_name' => 'Thompson Physiotherapy',
            'specialization' => 'Physiotherapy',
            'bio' => 'Doctor of Physical Therapy specializing in sports injuries, post-surgical rehab, and chronic pain management.',
            'experience_years' => 14,
            'hourly_rate' => 110.00,
            'location' => 'Houston, TX',
            'latitude' => 29.7606,
            'longitude' => -95.3696,
            'skills' => ['Sports Injury Rehab', 'Post-Surgical Rehab', 'Manual Therapy', 'Dry Needling'],
            'certifications' => ['DPT', 'OCS Certification'],
            'languages' => ['English'],
            'verification_status' => 'verified',
            'is_suspended' => true,
            'suspension_reason' => 'License under review following patient complaint.',
        ],
        [
            'first_name' => 'Sophia',
            'last_name' => 'Nguyen',
            'business_name' => 'Nguyen Language Academy',
            'specialization' => 'Language Classes',
            'bio' => 'Native Vietnamese speaker offering Vietnamese and English as a Second Language classes for all levels.',
            'experience_years' => 5,
            'hourly_rate' => 45.00,
            'location' => 'Dallas, TX',
            'latitude' => 32.7769,
            'longitude' => -96.7968,
            'skills' => ['Vietnamese', 'ESL', 'Conversational Practice', 'Business Vietnamese'],
            'certifications' => ['TESOL Certificate'],
            'languages' => ['English', 'Vietnamese', 'French'],
            'verification_status' => 'verified',
            'is_suspended' => false,
        ],
        [
            'first_name' => 'Christopher',
            'last_name' => 'White',
            'business_name' => 'White Car Wash Express',
            'specialization' => 'Car Wash',
            'bio' => 'Premium hand car wash and detailing. Interior and exterior packages for every budget.',
            'experience_years' => 4,
            'hourly_rate' => 0.00,
            'location' => 'Fort Worth, TX',
            'latitude' => 32.7554,
            'longitude' => -97.3309,
            'skills' => ['Hand Wash', 'Interior Detailing', 'Ceramic Coating', 'Paint Correction'],
            'certifications' => ['IDA Certified Detailer'],
            'languages' => ['English', 'Spanish'],
            'verification_status' => 'pending',
            'is_suspended' => false,
        ],
    ];

    public function run(): void
    {
        $actor = User::query()->whereHas('roles')->first();

        $existing = ProviderProfile::count();
        $missing = max(0, self::TARGET_PROVIDERS - $existing);

        if ($missing === 0) {
            $this->command?->info("Already {$existing} provider profiles — nothing to seed.");

            return;
        }

        // Only seed providers we haven't created yet.
        $toCreate = array_slice(self::PROVIDERS, 0, $missing);

        foreach ($toCreate as $data) {
            $this->createProvider($data, $actor);
        }

        $this->command?->info("Seeded ".count($toCreate)." providers — total: ".ProviderProfile::count().'.');
    }

    /**
     * Create a single provider user and profile, plus verification history
     * when applicable.
     */
    private function createProvider(array $data, ?User $actor): void
    {
        $email = strtolower($data['first_name'].'.'.$data['last_name'].'@example.com');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'name' => $data['first_name'].' '.$data['last_name'],
                'user_type' => 'provider',
                'password' => Hash::make('password'),
                'phone' => fake()->numerify('+1 555 ###-####'),
                'address' => sprintf(
                    '%s, %s, %s %s',
                    fake()->streetAddress(),
                    $data['location'],
                    'TX',
                    fake()->postcode(),
                ),
                'status' => 'active',
                'email_verified_at' => now(),
                'last_login_at' => fake()->boolean(70) ? fake()->dateTimeBetween('-60 days', 'now') : null,
                'created_by' => $actor?->id,
            ],
        );

        if (ProviderProfile::where('user_id', $user->id)->exists()) {
            return;
        }

        $rating = $data['verification_status'] === 'verified'
            ? round(fake()->randomFloat(2, 3.5, 5.0), 2)
            : round(fake()->randomFloat(2, 2.0, 4.5), 2);

        $totalReviews = $data['verification_status'] === 'verified'
            ? fake()->numberBetween(5, 120)
            : fake()->numberBetween(0, 15);

        $totalBookings = $data['verification_status'] === 'verified'
            ? fake()->numberBetween(20, 500)
            : fake()->numberBetween(0, 30);

        $completedBookings = (int) round($totalBookings * fake()->randomFloat(2, 0.7, 1.0));

        $verifiedAt = $data['verification_status'] === 'verified'
            ? fake()->dateTimeBetween('-180 days', '-10 days')
            : null;

        $suspendedAt = $data['is_suspended']
            ? fake()->dateTimeBetween('-30 days', '-1 day')
            : null;

        $profile = ProviderProfile::create([
            'user_id' => $user->id,
            'business_name' => $data['business_name'],
            'bio' => $data['bio'],
            'specialization' => $data['specialization'],
            'experience_years' => $data['experience_years'],
            'hourly_rate' => $data['hourly_rate'],
            'location' => $data['location'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'website' => 'https://'.str_replace(' ', '', strtolower($data['business_name'])).'.com',
            'social_links' => [
                'facebook' => 'https://facebook.com/'.str_replace(' ', '', strtolower($data['business_name'])),
                'instagram' => 'https://instagram.com/'.str_replace(' ', '', strtolower($data['business_name'])),
            ],
            'portfolio' => [],
            'skills' => $data['skills'],
            'certifications' => $data['certifications'],
            'languages' => $data['languages'],
            'average_rating' => $rating,
            'total_reviews' => $totalReviews,
            'total_bookings' => $totalBookings,
            'completed_bookings' => $completedBookings,
            'verification_status' => $data['verification_status'],
            'verified_at' => $verifiedAt,
            'verified_by' => $verifiedAt ? $actor?->id : null,
            'rejection_reason' => $data['verification_status'] === 'rejected'
                ? 'Submitted documents are expired. Please resubmit with current identification.'
                : null,
            'suspended_at' => $suspendedAt,
            'suspended_by' => $suspendedAt ? $actor?->id : null,
            'suspension_reason' => $data['suspension_reason'] ?? null,
            'created_at' => fake()->dateTimeBetween('-365 days', '-30 days'),
            'updated_at' => now(),
        ]);

        // Create a matching VerificationRequest for non-unverified providers.
        if ($data['verification_status'] !== 'unverified') {
            $this->createVerificationRequest($profile, $data['verification_status'], $actor);
        }
    }

    /**
     * Create a VerificationRequest with the given status, and attach
     * representative documents.
     */
    private function createVerificationRequest(ProviderProfile $profile, string $status, ?User $actor): void
    {
        $submittedAt = fake()->dateTimeBetween('-180 days', '-10 days');
        $reviewedAt = in_array($status, ['verified', 'rejected', 'additional_info_required'], true)
            ? fake()->dateTimeBetween($submittedAt, 'now')
            : null;

        $request = VerificationRequest::create([
            'provider_profile_id' => $profile->id,
            'status' => match ($status) {
                'verified' => 'approved',
                'pending' => 'pending',
                'rejected' => 'rejected',
                'additional_info_required' => 'additional_info_required',
                default => 'pending',
            },
            'notes' => 'Please review my profile and documents.',
            'admin_notes' => $reviewedAt ? 'Documents reviewed.' : null,
            'rejection_reason' => $status === 'rejected'
                ? 'Submitted documents are expired. Please resubmit with current identification.'
                : null,
            'additional_info_request' => $status === 'additional_info_required'
                ? 'Please provide a clearer copy of your government-issued ID and proof of professional certification.'
                : null,
            'submitted_at' => $submittedAt,
            'reviewed_at' => $reviewedAt,
            'reviewed_by' => $reviewedAt ? $actor?->id : null,
            'created_at' => $submittedAt,
            'updated_at' => $reviewedAt ?? $submittedAt,
        ]);

        // Attach sample verification documents.
        $documents = [
            [
                'document_type' => 'government_id',
                'file_name' => 'drivers_license.pdf',
                'file_path' => 'verification/dummy_id.pdf',
                'file_mime_type' => 'application/pdf',
                'file_size' => fake()->numberBetween(200000, 800000),
                'description' => 'Driver\'s license for identity verification.',
            ],
            [
                'document_type' => 'professional_certificate',
                'file_name' => 'professional_cert.pdf',
                'file_path' => 'verification/dummy_cert.pdf',
                'file_mime_type' => 'application/pdf',
                'file_size' => fake()->numberBetween(100000, 500000),
                'description' => 'Professional certification / license.',
            ],
        ];

        foreach ($documents as $doc) {
            VerificationDocument::create(array_merge($doc, [
                'verification_request_id' => $request->id,
                'created_at' => $submittedAt,
                'updated_at' => $submittedAt,
            ]));
        }
    }
}

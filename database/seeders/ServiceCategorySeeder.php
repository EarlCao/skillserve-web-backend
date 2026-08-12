<?php

namespace Database\Seeders;

use App\Modules\ServiceCategories\Models\ServiceCategory;
use App\Modules\ServiceCategories\Models\ServiceSubcategory;
use Illuminate\Database\Seeder;

/**
 * Seeds a starter catalog of service categories and subcategories so the
 * Service Category Management screens have data to work with.
 *
 * Idempotent — re-running only tops up what is missing.
 */
class ServiceCategorySeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, description: string, status?: string, subcategories: array<int, array{name: string, description: string, status?: string}>}>
     */
    private const CATEGORIES = [
        [
            'name' => 'Home Maintenance',
            'description' => 'Plumbing, electrical and painting services for homes.',
            'subcategories' => [
                ['name' => 'Plumbing', 'description' => 'Pipe installation, repair and maintenance.'],
                ['name' => 'Electrical', 'description' => 'Wiring, outlets, lighting and panel work.'],
                ['name' => 'Painting', 'description' => 'Interior and exterior painting services.'],
            ],
        ],
        [
            'name' => 'Cleaning Services',
            'description' => 'Residential and commercial cleaning.',
            'subcategories' => [
                ['name' => 'Home Cleaning', 'description' => 'Regular and one-off home cleaning.'],
                ['name' => 'Office Cleaning', 'description' => 'Commercial and office space cleaning.'],
                ['name' => 'Deep Cleaning', 'description' => 'Thorough top-to-bottom cleaning.'],
            ],
        ],
        [
            'name' => 'Beauty & Wellness',
            'description' => 'Personal care, beauty and relaxation services.',
            'subcategories' => [
                ['name' => 'Hair Care', 'description' => 'Cutting, styling and treatments.'],
                ['name' => 'Nail Care', 'description' => 'Manicure and pedicure services.'],
                ['name' => 'Massage', 'description' => 'Relaxation and therapeutic massage.'],
                ['name' => 'Spa', 'description' => 'Full spa and wellness packages.'],
            ],
        ],
        [
            'name' => 'Automotive',
            'description' => 'Vehicle care, repair and roadside assistance.',
            'status' => 'enabled',
            'subcategories' => [
                ['name' => 'Car Wash', 'description' => 'Exterior and interior washing.'],
                ['name' => 'Car Repair', 'description' => 'Mechanical and body repair.'],
                ['name' => 'Towing', 'description' => 'Roadside towing and assistance.'],
            ],
        ],
        [
            'name' => 'IT & Tech Support',
            'description' => 'Computer, network and software help.',
            'subcategories' => [
                ['name' => 'Computer Repair', 'description' => 'Hardware and software repair.'],
                ['name' => 'Network Setup', 'description' => 'Routers, Wi-Fi and network configuration.'],
                ['name' => 'Software Installation', 'description' => 'OS and application installation.'],
            ],
        ],
        [
            'name' => 'Event Services',
            'description' => 'Planning and services for events of every size.',
            'status' => 'disabled',
            'subcategories' => [
                ['name' => 'Catering', 'description' => 'Food and beverage service.'],
                ['name' => 'Photography', 'description' => 'Event photography and video.'],
                ['name' => 'Decoration', 'description' => 'Venue decoration and styling.'],
            ],
        ],
        [
            'name' => 'Fitness & Health',
            'description' => 'Training, coaching and therapy.',
            'subcategories' => [
                ['name' => 'Personal Training', 'description' => 'One-on-one fitness coaching.'],
                ['name' => 'Yoga', 'description' => 'Group and private yoga sessions.'],
                ['name' => 'Physiotherapy', 'description' => 'Rehabilitation and therapy.'],
            ],
        ],
        [
            'name' => 'Education & Tutoring',
            'description' => 'Academic support and skill-building classes.',
            'subcategories' => [
                ['name' => 'Math Tutoring', 'description' => 'Math help for all levels.'],
                ['name' => 'Language Classes', 'description' => 'Foreign language instruction.'],
                ['name' => 'Music Lessons', 'description' => 'Instrument and vocal lessons.'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $categoryData) {
            $subcategories = $categoryData['subcategories'];

            unset($categoryData['subcategories']);

            $category = ServiceCategory::withTrashed()->firstOrCreate(
                ['name' => $categoryData['name']],
                $categoryData,
            );

            foreach ($subcategories as $subcategoryData) {
                ServiceSubcategory::withTrashed()->firstOrCreate(
                    [
                        'category_id' => $category->id,
                        'name' => $subcategoryData['name'],
                    ],
                    $subcategoryData,
                );
            }
        }
    }
}

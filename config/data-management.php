<?php

return [
    'export_types' => ['users', 'providers', 'services', 'bookings', 'reviews', 'activity'],
    'archive_types' => ['services'],
    // Only types that nothing else depends on: force-deleting users, services,
    // bookings or categories would cascade into (or be blocked by) related records.
    'permanent_delete_types' => ['messages', 'reports', 'reviews'],
    // Deleted records of the types above are purged this many days after deletion.
    'retention_days' => 30,
    'resource_types' => [
        'users', 'services', 'bookings', 'reviews', 'reports', 'messages',
        'service_categories', 'service_subcategories',
    ],
];

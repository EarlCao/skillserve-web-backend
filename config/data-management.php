<?php

return [
    'export_types' => ['users', 'providers', 'services', 'bookings', 'reviews', 'activity'],
    'archive_types' => ['services'],
    // Deleted records of these types can be permanently deleted, and are purged
    // automatically after retention_days — unless related records still reference
    // them (see DataManagementService::DEPENDENTS).
    'permanent_delete_types' => [
        'users', 'services', 'bookings', 'reviews', 'reports', 'messages',
        'service_categories', 'service_subcategories',
    ],
    'retention_days' => 30,
    'resource_types' => [
        'users', 'services', 'bookings', 'reviews', 'reports', 'messages',
        'service_categories', 'service_subcategories',
    ],
];

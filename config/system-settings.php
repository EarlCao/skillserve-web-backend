<?php

return [
    'groups' => [
        'general' => [
            'platform_name' => ['label' => 'Platform name', 'type' => 'string', 'default' => 'SkillServe', 'rules' => ['string', 'max:120']],
            'platform_description' => ['label' => 'Platform description', 'type' => 'textarea', 'default' => '', 'rules' => ['nullable', 'string', 'max:1000']],
            'support_email' => ['label' => 'Support email', 'type' => 'email', 'default' => '', 'rules' => ['nullable', 'email', 'max:255']],
            'timezone' => ['label' => 'Timezone', 'type' => 'string', 'default' => 'UTC', 'rules' => ['string', 'timezone']],
        ],
        'marketplace' => [
            'provider_registration_enabled' => ['label' => 'Allow provider registration', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'service_approval_required' => ['label' => 'Require service approval', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'featured_services_enabled' => ['label' => 'Enable featured services', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'commission_rate' => ['label' => 'Platform commission rate (%)', 'type' => 'number', 'default' => 10, 'rules' => ['numeric', 'min:0', 'max:100']],
        ],
        'booking' => [
            'booking_enabled' => ['label' => 'Enable bookings', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'cancellation_window_hours' => ['label' => 'Cancellation window (hours)', 'type' => 'number', 'default' => 24, 'rules' => ['integer', 'min:0', 'max:720']],
            'client_cancellation_fee_percent' => ['label' => 'Client cancellation fee (%)', 'type' => 'number', 'default' => 0, 'rules' => ['numeric', 'min:0', 'max:100']],
            'provider_cancellation_fee_percent' => ['label' => 'Provider cancellation fee (%)', 'type' => 'number', 'default' => 0, 'rules' => ['numeric', 'min:0', 'max:100']],
        ],
        'notifications' => [
            'email_notifications_enabled' => ['label' => 'Email notifications', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'push_notifications_enabled' => ['label' => 'Push notifications', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'announcement_notifications_enabled' => ['label' => 'Announcement notifications', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
        ],
        'policies' => [
            'terms_of_service' => ['label' => 'Terms of service', 'type' => 'textarea', 'default' => '', 'rules' => ['nullable', 'string', 'max:50000']],
            'privacy_policy' => ['label' => 'Privacy policy', 'type' => 'textarea', 'default' => '', 'rules' => ['nullable', 'string', 'max:50000']],
            'community_guidelines' => ['label' => 'Community guidelines', 'type' => 'textarea', 'default' => '', 'rules' => ['nullable', 'string', 'max:50000']],
        ],
        'system' => [
            'maintenance_mode' => ['label' => 'Maintenance mode', 'type' => 'boolean', 'default' => false, 'rules' => ['boolean']],
            'session_timeout_minutes' => ['label' => 'Session timeout (minutes)', 'type' => 'number', 'default' => 1440, 'rules' => ['integer', 'min:5', 'max:43200']],
            'default_page_size' => ['label' => 'Default page size', 'type' => 'number', 'default' => 15, 'rules' => ['integer', 'min:1', 'max:100']],
        ],
    ],
];

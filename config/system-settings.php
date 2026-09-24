<?php

return [
    'groups' => [
        'general' => [
            'platform_name' => ['label' => 'Platform name', 'type' => 'string', 'default' => 'SkillServe', 'rules' => ['string', 'max:120']],
            'platform_description' => ['label' => 'Platform description', 'type' => 'textarea', 'default' => '', 'rules' => ['nullable', 'string', 'max:1000']],
            'support_email' => ['label' => 'Support email', 'type' => 'email', 'default' => '', 'rules' => ['nullable', 'email', 'max:255']],
            // Read-only: the business timezone (config/app.php, BUSINESS_TIMEZONE)
            // that provider hours and booking times are read in, shown here
            // rather than kept as a second, conflicting copy.
            'timezone' => ['label' => 'Timezone', 'type' => 'string', 'default' => 'Asia/Manila', 'source' => 'app.business_timezone', 'rules' => ['string', 'timezone']],
        ],
        'marketplace' => [
            'provider_registration_enabled' => ['label' => 'Allow provider registration', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'service_approval_required' => ['label' => 'Require service approval', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'featured_services_enabled' => ['label' => 'Enable featured services', 'type' => 'boolean', 'default' => true, 'rules' => ['boolean']],
            'commission_rate' => ['label' => 'Platform commission rate (%)', 'type' => 'number', 'default' => 10, 'rules' => ['numeric', 'min:0', 'max:100']],
        ],
        'identity' => [
            // How long National ID images are kept after a decision. They are
            // only needed while a submission is under review or under dispute;
            // holding them longer widens the blast radius of a breach for no
            // operational gain.
            // The master switch. Ships OFF: turning it on is a deliberate
            // decision, because it stops unverified accounts transacting.
            'identity_verification_required' => ['label' => 'Require National ID verification to transact', 'type' => 'boolean', 'default' => false, 'rules' => ['boolean']],

            // Grandfathering. Accounts created BEFORE this date keep
            // transacting without a National ID; accounts created on or after
            // it must verify. Leaving it empty applies the requirement to
            // every account, including existing ones — which freezes the live
            // marketplace until the review queue is cleared, so it is not the
            // intended configuration.
            'identity_verification_enforced_from' => ['label' => 'Require it for accounts created from', 'type' => 'date', 'default' => '', 'rules' => ['nullable', 'date']],

            'identity_document_retention_days' => ['label' => 'Keep National ID images for (days)', 'type' => 'number', 'default' => 90, 'rules' => ['integer', 'min:1', 'max:3650']],
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

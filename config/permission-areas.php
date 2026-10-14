<?php

/*
 * The administrator permission matrix, one entry per area of the admin web.
 *
 * Each area offers two levels: `view` (read-only) and full control (`view`
 * plus `control`). The Permissions page renders exactly these areas; a
 * permission listed in no area appears under "Other" so it is never hidden.
 * Keep every seeded permission in exactly one area.
 */
return [
    ['key' => 'dashboard', 'label' => 'Dashboard & analytics', 'description' => 'Overview figures and analytics reports.',
        'view' => ['view dashboard', 'view analytics'], 'control' => ['export analytics']],
    ['key' => 'administrators', 'label' => 'Administrators & roles', 'description' => 'Staff accounts, roles and these permissions.',
        'view' => ['view administrators'], 'control' => ['manage administrators', 'create administrators', 'edit administrators']],
    ['key' => 'users', 'label' => 'Users', 'description' => 'Customer and provider accounts: suspend, ban, edit, delete.',
        'view' => ['view users'], 'control' => ['manage users', 'edit users', 'delete users', 'suspend users', 'activate users', 'ban users']],
    ['key' => 'providers', 'label' => 'Providers & verification', 'description' => 'Provider profiles and their verification documents.',
        'view' => ['view providers'], 'control' => ['manage providers', 'edit providers', 'delete providers', 'suspend providers', 'activate providers', 'verify providers', 'reject providers']],
    ['key' => 'identity', 'label' => 'National ID verification', 'description' => 'Review submitted National IDs.',
        'view' => ['view identity verifications'], 'control' => ['verify identities', 'reject identities']],
    ['key' => 'services', 'label' => 'Services', 'description' => 'Listings: approve, reject, feature, edit, delete.',
        'view' => ['view services'], 'control' => ['manage services', 'create services', 'edit services', 'delete services', 'approve services', 'reject services', 'feature services']],
    ['key' => 'categories', 'label' => 'Service categories', 'description' => 'Categories and subcategories.',
        'view' => ['view service categories'], 'control' => ['manage service categories', 'create service categories', 'edit service categories', 'delete service categories']],
    ['key' => 'bookings', 'label' => 'Bookings & disputes', 'description' => 'Bookings, payments, cancellations and disputes.',
        'view' => ['view bookings'], 'control' => ['manage bookings', 'cancel bookings', 'manage booking payments', 'manage booking disputes']],
    ['key' => 'commissions', 'label' => 'Commissions', 'description' => 'Commission tiers, balances and payments.',
        'view' => ['view commissions'], 'control' => ['manage commissions', 'settle commissions']],
    ['key' => 'reviews', 'label' => 'Reviews', 'description' => 'Hide, edit or remove reviews.',
        'view' => ['view reviews'], 'control' => ['manage reviews', 'edit reviews', 'delete reviews']],
    ['key' => 'reports', 'label' => 'Reports & moderation', 'description' => 'Investigate reports and take action on accounts and content.',
        'view' => ['view reports'], 'control' => ['manage reports', 'investigate reports', 'resolve reports', 'manage moderation']],
    ['key' => 'notifications', 'label' => 'Notifications & announcements', 'description' => 'Send, target and schedule announcements.',
        'view' => ['view notifications'], 'control' => ['send announcements', 'target notifications', 'schedule announcements']],
    ['key' => 'recognition', 'label' => 'Provider recognition', 'description' => 'Badges, featured and top-rated providers.',
        'view' => ['view provider recognition', 'view top rated providers'], 'control' => ['manage provider badges', 'assign provider badges', 'manage featured providers']],
    ['key' => 'support', 'label' => 'Customer Support', 'description' => 'Chat with users who asked for help, assign and resolve their requests.',
        'view' => ['view support'], 'control' => ['manage support', 'assign support tickets', 'respond to support tickets', 'resolve support tickets']],
    ['key' => 'audit', 'label' => 'Audit & security', 'description' => 'Audit logs, sign-in activity and security events.',
        'view' => ['view audit logs', 'view login activity'], 'control' => ['monitor security events']],
    ['key' => 'settings', 'label' => 'System settings', 'description' => 'Platform rules, policies and appearance.',
        'view' => ['view settings'], 'control' => ['manage settings']],
    ['key' => 'data', 'label' => 'Data management', 'description' => 'Exports, archives and deleted records.',
        'view' => ['view data'], 'control' => ['manage data', 'export system data', 'archive records', 'restore archived records', 'restore deleted records', 'manage deleted records']],
];

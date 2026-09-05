<?php

namespace App\Modules\Authentication\Events;

class AdministratorLoginFailed
{
    public function __construct(
        public readonly string $email,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}

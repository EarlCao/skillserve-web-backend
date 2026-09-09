<?php

namespace App\Modules\ProviderRecognition\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProviderRecognitionChanged
{
    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public readonly Model $subject,
        public readonly User $actor,
        public readonly string $action,
        public readonly array $properties = [],
    ) {}
}

<?php

namespace App\Modules\Audit\Resources;

use App\Shared\Resources\BaseResource;

class AuditLogResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->description,
            'module' => $this->log_name,
            'administrator' => $this->whenLoaded('causer', fn () => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
                'email' => $this->causer->email,
            ] : null),
            'subject_type' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'properties' => $this->safeProperties(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function safeProperties(): array
    {
        $properties = $this->properties?->toArray() ?? [];

        return $this->redact($properties);
    }

    private function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), ['password', 'password_confirmation', 'token', 'plain_text_token', 'access_token'], true)) {
                    continue;
                }
                $result[$key] = $this->redact($item);
            }

            return $result;
        }

        return $value;
    }
}

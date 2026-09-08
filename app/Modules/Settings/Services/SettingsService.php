<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    public function value(string $group, string $name): mixed
    {
        $definition = config("system-settings.groups.{$group}.{$name}");

        return Setting::query()->where('group', $group)->where('name', $name)->value('payload')
            ?? ($definition['default'] ?? null);
    }

    public function all(): array
    {
        return collect(config('system-settings.groups'))->mapWithKeys(function (array $fields, string $group): array {
            $stored = Setting::query()->where('group', $group)->pluck('payload', 'name');

            return [$group => collect($fields)->mapWithKeys(fn (array $definition, string $name): array => [
                $name => $stored->get($name, $definition['default']),
            ])->all()];
        })->all();
    }

    public function update(array $values, User $actor): array
    {
        DB::transaction(function () use ($values, $actor): void {
            foreach ($values as $group => $fields) {
                if (! array_key_exists($group, config('system-settings.groups'))) {
                    continue;
                }

                foreach ($fields as $name => $value) {
                    if (! array_key_exists($name, config("system-settings.groups.{$group}"))) {
                        throw ValidationException::withMessages([
                            "{$group}.{$name}" => ['This setting is not supported.'],
                        ]);
                    }

                    $setting = Setting::query()
                        ->where('group', $group)
                        ->where('name', $name)
                        ->lockForUpdate()
                        ->first();

                    if ($setting?->locked) {
                        throw ValidationException::withMessages([
                            "{$group}.{$name}" => ['This setting is locked and cannot be changed.'],
                        ]);
                    }

                    Setting::query()->updateOrCreate(
                        ['group' => $group, 'name' => $name],
                        ['payload' => $value],
                    );
                }
            }

            activity('system_settings')
                ->causedBy($actor)
                ->withProperties(['groups' => array_keys($values)])
                ->log('System settings updated');
        });

        return $this->all();
    }
}

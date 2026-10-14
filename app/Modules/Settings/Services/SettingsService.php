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

        if (isset($definition['source'])) {
            return config($definition['source']);
        }

        return Setting::query()->where('group', $group)->where('name', $name)->value('payload')
            ?? ($definition['default'] ?? null);
    }

    public function all(): array
    {
        return collect(config('system-settings.groups'))->mapWithKeys(function (array $fields, string $group): array {
            $stored = Setting::query()->where('group', $group)->pluck('payload', 'name');

            return [$group => collect($fields)->mapWithKeys(fn (array $definition, string $name): array => [
                $name => isset($definition['source'])
                    ? config($definition['source'])
                    : $stored->get($name, $definition['default']),
            ])->all()];
        })->all();
    }

    /**
     * What System Settings shows: every setting except those another admin
     * page owns (`managed_by`, e.g. the commission rules on the Commission
     * page).
     *
     * @return array<string, array<string, mixed>>
     */
    public function forSystemSettings(): array
    {
        return collect($this->all())
            ->map(fn (array $fields, string $group): array => array_filter(
                $fields,
                fn (string $name): bool => ! isset(config("system-settings.groups.{$group}.{$name}")['managed_by']),
                ARRAY_FILTER_USE_KEY,
            ))
            ->all();
    }

    /**
     * The settings an admin page owns, as "name" => value within [group].
     *
     * @return array<string, mixed>
     */
    public function managedBy(string $owner, string $group): array
    {
        return collect(config("system-settings.groups.{$group}"))
            ->filter(fn (array $definition): bool => ($definition['managed_by'] ?? null) === $owner)
            ->map(fn (array $definition, string $name): mixed => $this->value($group, $name))
            ->all();
    }

    /**
     * Settings the admin can see but not change, as "group.name" keys —
     * values that come from server configuration.
     *
     * @return array<int, string>
     */
    public function readOnly(): array
    {
        return collect(config('system-settings.groups'))
            ->flatMap(fn (array $fields, string $group) => collect($fields)
                ->filter(fn (array $definition): bool => isset($definition['source']))
                ->keys()
                ->map(fn (string $name): string => "{$group}.{$name}"))
            ->values()
            ->all();
    }

    /**
     * Saves [values]. System Settings ([owner] null) may not change a setting
     * another page owns; that page passes its own name as [owner] and may
     * change only its own.
     */
    public function update(array $values, User $actor, ?string $owner = null): array
    {
        DB::transaction(function () use ($values, $actor, $owner): void {
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

                    $managedBy = config("system-settings.groups.{$group}.{$name}")['managed_by'] ?? null;
                    if ($managedBy !== $owner) {
                        throw ValidationException::withMessages([
                            "{$group}.{$name}" => [$managedBy === 'commissions'
                                ? 'Change this on the Commission page.'
                                : 'This setting cannot be changed here.'],
                        ]);
                    }

                    if (isset(config("system-settings.groups.{$group}.{$name}")['source'])) {
                        throw ValidationException::withMessages([
                            "{$group}.{$name}" => ['This value comes from the server configuration and cannot be changed here.'],
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
                        // A cleared optional field arrives as null (the API
                        // turns empty strings into null), but `payload` is
                        // NOT NULL: store it as the empty value it means —
                        // e.g. no support email, or the default theme colour.
                        ['payload' => $value ?? ''],
                    );
                }
            }

            activity('system_settings')
                ->causedBy($actor)
                ->withProperties(['groups' => array_keys($values), 'page' => $owner ?? 'system_settings'])
                ->log($owner === 'commissions' ? 'Commission rules updated' : 'System settings updated');
        });

        return $this->all();
    }
}

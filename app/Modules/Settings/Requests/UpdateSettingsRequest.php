<?php

namespace App\Modules\Settings\Requests;

use App\Shared\Requests\BaseFormRequest;

class UpdateSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $rules = [];

        foreach (config('system-settings.groups') as $group => $fields) {
            $rules[$group] = ['sometimes', 'array:'.implode(',', array_keys($fields))];
            foreach ($fields as $field => $definition) {
                $rules["{$group}.{$field}"] = array_merge(['sometimes'], $definition['rules']);
            }
        }

        return $rules;
    }
}

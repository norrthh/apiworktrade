<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * @return array<string, string>
     */
    protected function formValues(Request $request): array
    {
        $data = $request->validate([
            'values' => ['required', 'array', 'min:1'],
        ]);

        $values = [];
        foreach ($data['values'] as $key => $value) {
            if (! is_string($key) || $key === '' || is_array($value) || is_object($value)) {
                throw ValidationException::withMessages([
                    'values' => 'Каждое поле — строка или число.',
                ]);
            }
            if (str_contains(strtolower($key), 'password')) {
                throw ValidationException::withMessages([
                    'values' => 'Пароль не передаётся.',
                ]);
            }
            $values[$key] = $value === null ? '' : (string) $value;
        }

        return $values;
    }
}

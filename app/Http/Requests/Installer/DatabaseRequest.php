<?php

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;

class DatabaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'host' => trim((string) $this->input('host')),
            'database' => trim((string) $this->input('database')),
            'username' => trim((string) $this->input('username')),
            'prefix' => strtolower(trim((string) $this->input('prefix'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-_:\[\]]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-$]+$/'],
            'username' => ['required', 'string', 'max:128'],
            'password' => ['nullable', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:20', 'regex:/^[a-z0-9_]*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'host.regex' => __('The hostname contains invalid characters.'),
            'database.regex' => __('Database names may contain letters, numbers, underscores, dashes and $.'),
            'prefix.regex' => __('The table prefix may only contain lowercase letters, numbers and underscores.'),
        ];
    }
}

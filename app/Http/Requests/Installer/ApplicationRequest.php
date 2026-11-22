<?php

namespace App\Http\Requests\Installer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'url' => rtrim(trim((string) $this->input('url')), '/'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80', 'not_regex:/[<>"\r\n]/'],
            'url' => ['required', 'string', 'max:255', 'url:http,https'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', Rule::in((array) config('labeldesk.installer.currencies'))],
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('labeldesk.installer.locales')))],
        ];
    }
}

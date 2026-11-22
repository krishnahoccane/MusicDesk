<?php

namespace App\Http\Requests\Installer;

use App\Services\License\LicenseActivationService;
use Illuminate\Foundation\Http\FormRequest;

class LicenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'license_key' => LicenseActivationService::normalizeKey((string) $this->input('license_key')),
        ]);
    }

    public function rules(): array
    {
        return [
            'license_key' => ['required', 'string', 'max:64', 'regex:'.LicenseActivationService::KEY_PATTERN],
            'accept' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'license_key.regex' => __('Enter the license key exactly as shown in your MusicDesk account (e.g. LDSK-XXXX-XXXX-XXXX-XXXX).'),
            'accept.accepted' => __('Please confirm that this installation may contact MusicDesk.tech to verify the license.'),
        ];
    }
}

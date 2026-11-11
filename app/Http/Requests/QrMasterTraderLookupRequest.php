<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class QrMasterTraderLookupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $token = (string) config('work1.qrmaster_test_token');

        return config('work1.qrmaster_test_enabled') && $token !== '' && hash_equals($token, (string) $this->bearerToken());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['id' => ['required', 'integer', 'min:1', 'max:2147483647']];
    }
}

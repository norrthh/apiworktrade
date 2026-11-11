<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class HighHelpPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $token = (string) config('work1.highhelp_token');

        return config('work1.highhelp_enabled') && $token !== '' && hash_equals($token, (string) $this->bearerToken());
    }

    public function rules(): array
    {
        return [
            'qr_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'payment_id' => ['required', 'string', 'regex:/^p[a-zA-Z0-9-]{8,78}$/D'],
            'provider_id' => ['required', 'uuid'],
            'amount_rub' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'trader_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:claim,complete'],
        ];
    }
}

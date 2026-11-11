<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class QrMasterTestPayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mode = (string) $this->route('mode');
        $token = (string) config('work1.qrmaster_'.$mode.'_token');

        return in_array($mode, ['test', 'live'], true) && config('work1.qrmaster_'.$mode.'_enabled') && $token !== '' && hash_equals($token, (string) $this->bearerToken());
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:'.$this->route('mode')],
            'qr_sha256' => [$this->route('mode') === 'live' ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'payment_id' => ['required', 'string', 'regex:/^p[a-zA-Z0-9-]{8,78}$/D'],
            'provider_id' => ['required', 'uuid'],
            'amount_rub' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'trader_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:claim,complete'],
            'reconcile_only' => ['required', 'boolean'],
        ];
    }
}

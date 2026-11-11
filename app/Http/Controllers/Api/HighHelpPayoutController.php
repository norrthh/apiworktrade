<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\HighHelpPayoutRequest;
use App\Services\Work1\HighHelpPayouts;
use Illuminate\Http\JsonResponse;

final class HighHelpPayoutController extends Controller
{
    public function __invoke(HighHelpPayoutRequest $request, HighHelpPayouts $payouts): JsonResponse
    {
        $data = $request->validated();
        $result = $payouts->sync($data['payment_id'], $data['provider_id'], $data['amount_rub'], (int) $data['trader_id'], $data['action'], $data['qr_sha256']);

        return response()->json(['payment_id' => $data['payment_id'], 'trader_id' => (int) $data['trader_id'], 'result' => $result]);
    }
}

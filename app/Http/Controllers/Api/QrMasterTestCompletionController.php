<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QrMasterTestPayoutRequest;
use App\Services\Work1\QrMasterTestPayouts;
use Illuminate\Http\JsonResponse;

final class QrMasterTestCompletionController extends Controller
{
    public function __invoke(QrMasterTestPayoutRequest $request, QrMasterTestPayouts $payouts): JsonResponse
    {
        $data = $request->validated();
        $result = $payouts->sync($data['payment_id'], $data['provider_id'], $data['amount_rub'], (int) $data['trader_id'], $data['action'], $data['reconcile_only'], (string) $request->route('mode'), $data['qr_sha256'] ?? null);

        return response()->json(['payment_id' => $data['payment_id'], 'trader_id' => (int) $data['trader_id'], 'result' => $result]);
    }
}

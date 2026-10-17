<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\Work1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfferController extends Controller
{
    public function store(Request $request, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'merchant_code' => ['required', 'string', 'max:64'],
            'merchant_name' => ['required', 'string', 'max:255'],
            'method' => ['required', 'in:humo,uzcard'],
        ]);

        return response()->json($work1->createOffer(
            $data['merchant_code'],
            $data['merchant_name'],
            $data['method'],
        ));
    }

    public function show(int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->getOffer($id));
    }

    public function agent(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'admin_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($work1->attachAgent(
            $id,
            (int) ($data['admin_id'] ?? config('work1.admin_id')),
        ));
    }

    public function commission(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'max_payin_com' => ['required', 'numeric', 'between:0,100'],
            'max_payout_com' => ['required', 'numeric', 'between:0,100'],
            'payin_com' => ['required', 'numeric', 'between:0,100'],
            'payout_com' => ['required', 'numeric', 'between:0,100'],
        ]);

        return response()->json($work1->setCommission(
            $id,
            (int) ($data['user_id'] ?? config('work1.trader_user_id')),
            (float) $data['max_payin_com'],
            (float) $data['max_payout_com'],
            (float) $data['payin_com'],
            (float) $data['payout_com'],
        ));
    }

    public function trader(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($work1->enableTrader(
            $id,
            (int) ($data['user_id'] ?? config('work1.trader_user_id')),
        ));
    }

    public function rate(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'preset' => ['required', 'string', 'max:64'],
            'market' => ['nullable', 'string', 'max:32'],
            'side' => ['nullable', 'in:buy,sell'],
        ]);

        return response()->json($work1->setRate(
            $id,
            $data['preset'],
            $data['market'] ?? 'p2p',
            $data['side'] ?? 'buy',
        ));
    }
}

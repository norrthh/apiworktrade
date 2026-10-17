<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\Work1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function show(Work1Client $work1): JsonResponse
    {
        $work1->ensure();

        return response()->json(['authenticated' => true]);
    }

    public function mfa(Request $request, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'regex:/^\d{6,8}$/'],
        ]);
        $work1->submitOtp($data['otp']);

        return response()->json(['authenticated' => true]);
    }
}

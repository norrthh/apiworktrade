<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\QrMasterTraderLookupRequest;
use App\Services\Work1\QrMasterTraders;
use Illuminate\Http\JsonResponse;

class QrMasterTraderLookupController extends Controller
{
    public function __invoke(QrMasterTraderLookupRequest $request, QrMasterTraders $traders): JsonResponse
    {
        return response()->json($traders->find((int) $request->validated('id')));
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\Work1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankController extends Controller
{
    public function index(Request $request, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($work1->listBanks((int) ($data['page'] ?? 1)));
    }

    public function show(int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->getBank($id));
    }

    public function store(Request $request, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->createBank($this->formValues($request)));
    }

    public function update(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->updateBank($id, $this->formValues($request)));
    }
}
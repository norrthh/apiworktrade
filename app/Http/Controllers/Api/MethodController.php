<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\Work1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MethodController extends Controller
{
    public function index(Request $request, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($work1->listMethods($data['name'] ?? null, (int) ($data['page'] ?? 1)));
    }

    public function show(int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->getMethod($id));
    }

    public function store(Request $request, Work1Client $work1): JsonResponse
    {
        $request->validate([
            'values.name' => ['required', 'string', 'max:64'],
            'values.bank_ids' => ['required', 'string'],
            'values.method_type' => ['required_without:values.method_type_id', 'string'],
            'values.method_type_id' => ['required_without:values.method_type', 'string'],
        ]);

        $method = $work1->createMethod($this->formValues($request));

        return response()->json($method, ($method['created'] ?? false) ? 201 : 200);
    }

    public function update(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->updateMethod($id, $this->formValues($request)));
    }
}

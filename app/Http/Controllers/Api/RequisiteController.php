<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\Work1Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequisiteController extends Controller
{
    public function index(Request $request, Work1Client $work1): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($work1->listRequisites(
            isset($data['user_id']) ? (int) $data['user_id'] : null,
            (int) ($data['page'] ?? 1),
        ));
    }

    public function show(int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->getRequisite($id));
    }

    public function store(Request $request, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->createRequisite($this->formValues($request)));
    }

    public function update(Request $request, int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->updateRequisite($id, $this->formValues($request)));
    }

    public function unarchive(int $id, Work1Client $work1): JsonResponse
    {
        return response()->json($work1->unarchiveRequisite($id));
    }
}
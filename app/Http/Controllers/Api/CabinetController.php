<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Work1\CabinetClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    public function index(Request $request, CabinetClient $cabinet): JsonResponse
    {
        $filters = $request->validate([
            'deleted' => ['nullable', 'boolean'],
            'archive_status' => ['nullable', 'in:active,archived'],
            'bank_id' => ['nullable', 'integer', 'min:1'],
            'card_number' => ['nullable', 'string', 'max:64'],
            'recipient' => ['nullable', 'string', 'max:120'],
            'sort_by' => ['nullable', 'in:created_at,total_turnover,last_run'],
        ]);

        return response()->json($cabinet->listCards($filters));
    }

    public function options(CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->options());
    }

    public function banks(Request $request, CabinetClient $cabinet): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($cabinet->banks($data['method']));
    }

    public function senders(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->senderNames($id));
    }

    public function show(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->getCard($id));
    }

    public function store(Request $request, CabinetClient $cabinet): JsonResponse
    {
        $request->validate([
            'values' => ['required', 'array'],
            'values.number' => ['required', 'string', 'max:120'],
            'values.recipient' => ['required', 'string', 'max:120'],
            'values.method' => ['required', 'string', 'max:64'],
            'values.bank_id' => ['required'],
        ]);

        return response()->json($cabinet->createCard($this->formValues($request)), 201);
    }

    public function update(Request $request, int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->updateCard($id, $this->formValues($request)));
    }

    public function enable(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->enableCard($id));
    }

    public function disable(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->disableCard($id));
    }

    public function destroy(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->deleteCard($id));
    }

    public function bulk(Request $request, CabinetClient $cabinet): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'active' => ['required', 'boolean'],
        ]);

        return response()->json($cabinet->setCardsActive($data['ids'], (bool) $data['active']));
    }

    public function resetLimit(int $id, CabinetClient $cabinet): JsonResponse
    {
        return response()->json($cabinet->resetDailyLimit($id));
    }
}

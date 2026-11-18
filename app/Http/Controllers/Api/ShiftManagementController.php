<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Support\PosContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShiftManagementController extends Controller
{
    public function __construct(
        private readonly PosContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $shifts = Shift::query()
            ->where('organization_id', $user->organization_id)
            ->where(static function ($q) use ($outlet): void {
                $q->whereNull('outlet_id');
                if ($outlet !== null) {
                    $q->orWhere('outlet_id', $outlet->id);
                }
            })
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'shifts' => $shifts,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->context->requireUser();
        $outlet = $this->context->outlet();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:32'],
            'start_time' => ['required', 'string', 'max:8'],
            'end_time' => ['required', 'string', 'max:8'],
            'is_overnight' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'outlet_specific' => ['nullable', 'boolean'],
        ]);

        $shift = Shift::query()->create([
            'organization_id' => $user->organization_id,
            'outlet_id' => (! empty($validated['outlet_specific']) && $outlet !== null) ? $outlet->id : null,
            'name' => $validated['name'],
            'code' => strtoupper($validated['code']),
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'is_overnight' => (bool) ($validated['is_overnight'] ?? false),
            'display_order' => (int) ($validated['display_order'] ?? 1),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Definisi shift berhasil dibuat.',
            'shift' => $shift,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $shift = Shift::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'code' => ['sometimes', 'string', 'max:32'],
            'start_time' => ['sometimes', 'string', 'max:8'],
            'end_time' => ['sometimes', 'string', 'max:8'],
            'is_overnight' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper($validated['code']);
        }

        $shift->update($validated);

        return response()->json([
            'message' => 'Definisi shift berhasil diperbarui.',
            'shift' => $shift,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = $this->context->requireUser();

        $shift = Shift::query()
            ->where('organization_id', $user->organization_id)
            ->findOrFail($id);

        $shift->update(['is_active' => false]);

        return response()->json([
            'message' => 'Definisi shift dinonaktifkan.',
        ]);
    }
}


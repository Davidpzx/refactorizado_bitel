<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComisionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComisionPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $planes = ComisionPlan::query()
            ->when($request->tipo_servicio, fn($q, $t) => $q->where('tipo_servicio', $t))
            ->orderBy('tipo_servicio')
            ->orderBy('nombre_plan')
            ->get();

        return response()->json($planes);
    }
}

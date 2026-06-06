<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $clientes = Cliente::query()
            ->when($request->q, fn($q, $term) => $q->buscar($term))
            ->when($request->tipo, fn($q, $tipo) => $q->where('tipo_documento', $tipo))
            ->orderBy('nombre')
            ->paginate($request->integer('per_page', 20));

        return response()->json($clientes);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        $cliente->load(['ventas' => fn($q) => $q->latest('creado_en')->limit(10)]);
        return response()->json($cliente);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dni_ruc'        => ['required', 'string', 'regex:/^\d{8}(\d{3})?$/', 'unique:clientes,dni_ruc'],
            'nombre'         => ['required', 'string', 'max:200'],
            'telefono'       => ['nullable', 'string', 'max:15'],
            'correo'         => ['nullable', 'email', 'max:120'],
            'tipo_documento' => ['sometimes', Rule::in(['DNI', 'RUC', 'CE', 'PAS'])],
        ], [
            'dni_ruc.regex'  => 'El DNI debe tener 8 dígitos o el RUC 11 dígitos.',
            'dni_ruc.unique' => 'Ya existe un cliente con ese DNI/RUC.',
        ]);

        $cliente = Cliente::create($data);
        return response()->json($cliente, 201);
    }

    public function update(Request $request, Cliente $cliente): JsonResponse
    {
        $data = $request->validate([
            'nombre'         => ['sometimes', 'string', 'max:200'],
            'telefono'       => ['nullable', 'string', 'max:15'],
            'correo'         => ['nullable', 'email', 'max:120'],
            'tipo_documento' => ['sometimes', Rule::in(['DNI', 'RUC', 'CE', 'PAS'])],
        ]);

        $cliente->update($data);
        return response()->json($cliente->fresh());
    }

    public function destroy(Cliente $cliente): JsonResponse
    {
        if ($cliente->ventas()->exists()) {
            return response()->json(['error' => 'No se puede eliminar: tiene ventas asociadas.'], 422);
        }
        $cliente->delete();
        return response()->json(null, 204);
    }
}

<?php

use App\Http\Controllers\Api\AgenteController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\ComprobanteController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\VentaController;
use Illuminate\Support\Facades\Route;

// ── Health (público) ─────────────────────────────────────────────────────────
Route::get('/v1/health', fn() => response()->json([
    'status' => 'ok',
    'app'    => config('app.name'),
    'env'    => config('app.env'),
]));

// ── Auth (público) ───────────────────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// ── Recursos protegidos ──────────────────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    Route::get('auth/me',     [AuthController::class, 'me']);
    Route::post('auth/logout',[AuthController::class, 'logout']);

    Route::apiResource('agentes',     AgenteController::class);
    Route::apiResource('clientes',    ClienteController::class);
    Route::apiResource('inventario',  InventarioController::class);
    Route::apiResource('ventas',      VentaController::class);
    Route::apiResource('comprobantes',ComprobanteController::class);

    Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar']);
});

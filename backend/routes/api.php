<?php

use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\VentaController;
use App\Http\Controllers\Api\ComprobanteController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn() => response()->json(['status' => 'ok', 'app' => config('app.name')]));

Route::apiResource('clientes', ClienteController::class);
Route::apiResource('ventas', VentaController::class);
Route::apiResource('comprobantes', ComprobanteController::class);

Route::post('comprobantes/{comprobante}/reenviar', [ComprobanteController::class, 'reenviar']);

<?php

use App\Console\Commands\AuditoriaNocturnaBipay;
use App\Console\Commands\AutoRetornoAgentes;
use App\Console\Commands\LimpiarFotosAsistencia;
use App\Console\Commands\ProcesarColaComprobantes;
use App\Console\Commands\SalidaAutomaticaAsistencias;
// bitel:reparar-excepciones-pisadas (RepararExcepcionesPisadas) NO se programa aquí:
// es una herramienta de reparación puntual (paridad con cron/reparar_excepciones_pisadas.php
// del legacy, que también era manual), no un cron recurrente. Ver docs/runbook-operacion.md.
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Scheduler ─────────────────────────────────────────────────────────────────
// Zona horaria: America/Lima (UTC-5) — definida en config/app.php

// Reactivar agentes con permiso largo que llegaron a su fecha de retorno
Schedule::command(AutoRetornoAgentes::class)
    ->dailyAt('00:05')
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Cerrar automáticamente turnos sin salida registrada.
// Cada 30 min, igual que cron/cron_salida_automatica.php del legacy: el comando
// espera 90 min tras la hora de salida programada antes de cerrar, así que
// correrlo solo 1x/día (como estaba antes) puede dejar turnos abiertos hasta
// 24h de más y retrasar la alerta a gerencia (gap documentado en
// docs/comparacion/gap_api_cron_auth_2026-07-02.md, "5 gaps más importantes" #1).
Schedule::command(SalidaAutomaticaAsistencias::class)
    ->everyThirtyMinutes()
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Cruce nocturno Bipay: declarado (ERP) vs scrapeado (agente) de todas las tiendas
Schedule::command(AuditoriaNocturnaBipay::class)
    ->dailyAt('23:30')
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Drenar la cola de comprobantes electrónicos contra la API externa de facturación.
// Cada minuto, sin solaparse: dos drenadores a la vez competirían por las mismas
// filas (el lock optimista lo aguanta, pero el trabajo se desperdicia).
Schedule::command(ProcesarColaComprobantes::class)
    ->everyMinute()
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Limpiar fotos de asistencia antiguas (> 7 días) + auto-aprobar fotos pendientes
// de revisión de días anteriores (Sección A). La Sección A debe correr a diario
// (paridad con cron/limpiar_fotos_asistencia.php, legacy cada 30 min): dejarla en
// semanal represaba fotos sin revisar hasta 6 días de más.
Schedule::command(LimpiarFotosAsistencia::class)
    ->dailyAt('02:15')
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

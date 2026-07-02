<?php

use App\Console\Commands\AuditoriaNocturnaBipay;
use App\Console\Commands\AutoRetornoAgentes;
use App\Console\Commands\LimpiarFotosAsistencia;
use App\Console\Commands\SalidaAutomaticaAsistencias;
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

// Cerrar automáticamente turnos sin salida registrada
Schedule::command(SalidaAutomaticaAsistencias::class)
    ->dailyAt('23:00')
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Cruce nocturno Bipay: declarado (ERP) vs scrapeado (agente) de todas las tiendas
Schedule::command(AuditoriaNocturnaBipay::class)
    ->dailyAt('23:30')
    ->timezone('America/Lima')
    ->withoutOverlapping()
    ->runInBackground();

// Limpiar fotos de asistencia antiguas (> 7 días)
Schedule::command(LimpiarFotosAsistencia::class)
    ->weekly()
    ->sundays()
    ->at('02:15')
    ->timezone('America/Lima')
    ->withoutOverlapping();

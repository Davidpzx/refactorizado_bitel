# TICKET-001 — Migración + modelo `FacturacionConfig` (multi-emisor por tienda)

- **Modelo asignado:** Opus 4.8
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si estimas que el presupuesto de contexto no alcanza, pide subdivisión ANTES de empezar. No dejar a medias.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` (Laravel 12) · legacy `E:\laragon\www\sistema-rolando-salas` (PHP puro)
- **Bloqueado por:** DECISIÓN-001 confirmada (portar cliente de API externa de facturación).

## Contexto
El legacy factura contra una API Laravel externa con configuración **por tienda con fallback global** en la tabla `facturacion_config`: `base_url`, `api_token`, RUC emisor, series (B001/F001), IGV, modo beta/producción, `company_id`, `branch_id` (multi-emisor real desde commit `dcf7e3e`). Leer `config/facturacion_config.php` del legacy como referencia de campos y resolución de config. El refactor hoy solo tiene config global por `.env` (`config/sunat.php`) — no alcanza paridad multi-emisor.

## Alcance
1. Migración Laravel idempotente (guards `hasTable`/`hasColumn`, como las demás en `database/migrations`) para la tabla de configuración de facturación: una fila global (tienda_id NULL) + filas por tienda. Incluir todos los campos del legacy.
2. Modelo Eloquent `FacturacionConfig` con scope/helper `paraTienda(int $tiendaId)` que resuelve config de tienda con fallback a la global (misma semántica que el legacy).
3. Cifrar en reposo `api_token` y cualquier secreto (cast `encrypted` de Laravel). NO guardar secretos en texto plano.
4. Seeder/factory mínimos para tests.
5. Tests Feature: resolución por tienda, fallback global, tienda sin config.

## Qué NO hacer
No meter emisores en `.env`; no duplicar datos fiscales en `configuracion_empresa`; no hardcodear series; no `CREATE TABLE` fuera de migraciones.

## Criterio de aceptación
`php artisan migrate` limpio en local; tests verdes; `FacturacionConfig::paraTienda()` devuelve la config correcta en los 3 casos; secretos cifrados verificable en BD.

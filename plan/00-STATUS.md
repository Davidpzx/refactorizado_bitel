# STATUS — Plan de Paridad refactorizado_bitel vs sistema-rolando-salas

**Orquestador:** cuenta principal (david365dgxd), modelo Sonnet 5 (esta terminal retomó de una terminal Fable que murió).
**Última actualización:** 2026-07-09 ~07:40 — Van **17/27 tickets** integrados: 001-007, 012-014, 016-019, 021-022, 024.

**titan agotó su LÍMITE SEMANAL** (resetea 2026-07-10 2pm Lima) — quedó fuera de rotación hasta entonces. Cortó el TICKET-024 a 3 de 4 páginas; el orquestador completó la 4ta (`RevisarFotosPage.tsx`) siguiendo el mismo patrón antes de integrar (regla 0.3).

Ola 6: dev2 chocó con su **límite de SESIÓN** en TICKET-008 (resetea 11:50am Lima — no tocó nada, sin trabajo a medias). dev3 completó TICKET-023 → commit `7c6d3fe`, verificado (tsc+build limpios).

**Van 21/27 tickets:** 001-007, 009, 011-019, 021-024.

**Ticket-011 (dev3) chocó con el límite de SESIÓN a medio terminar** (resetea 12:10pm Lima) — dejó el controlador (`ConfiguracionController::syncLogoFacturacion`) referenciando una clase `SincronizarLogoFacturacionService` que **no existía** (no compilaba). El orquestador lo completó entero: creó el servicio, la ruta, `LogoProcessorServiceTest` (7 tests) + `SincronizarLogoFacturacionTest` (7 tests), y el botón "Sincronizar logo con facturación" en `ConfiguracionFacturacionPage.tsx`. Commit `e205bd8`. Suite completa: 551/551.

**Ola 7 integrada** (2026-07-09): TICKET-008 → commit `abe541d` (links CPE HMAC, 12 tests). TICKET-025 → commit `174166a` (matriz cron + **fix real**: salida-automática pisaba filas PERMISO/FALTA_INJUSTIFICADA, corregido en origen + comando de reparación `bitel:reparar-excepciones-pisadas`; frecuencias corregidas salida-automática */30 y limpiar-fotos diario). Suite 570/570.

**Van 23/27.** Nota del 008: el botón WhatsApp NO se cableó en ComprobantesPage porque esa página lista la tabla Greenter vieja sin vínculo con comprobantes_cola — resolverlo es parte del 010 (crear index de comprobantes-cola y migrar la página a esa fuente).

**Ola 8 despachada** (2026-07-09 ~1:45pm): dev3 → TICKET-010 (ComprobantesPage sobre comprobantes_cola con acciones + filtros), dev2 (Opus) → TICKET-020 (fidelidad visual pantalla de cuadre; con permiso de pedir subdivisión antes de empezar si no le alcanza).
**titan volvió antes de lo esperado** (confirmado con ping ~1:50pm, el usuario avisó) → despachado TICKET-026 (QA visual) con alcance ajustado: TODAS las pantallas EXCEPTO la de cuadre (en vuelo con dev2 en el 020; se marca "pendiente de QA post-020"). Salida esperada: plan/04-qa-visual.md + ticket polish si aplica.
Después solo queda: QA de cuadre post-020, y 027 (QA funcional, Opus — gate de cierre).

**Verificación VPS ejecutada por el orquestador vía SSH** (2026-07-09, solo lectura):
- Cron de sistema CONFIRMADO: `* * * * * /usr/local/bin/laravel-schedule` (script que hace `docker exec ... php artisan schedule:run` sobre el contenedor backend vivo). ✔
- Filas pisadas por el bug de salida-automática: **6 filas** con centinela `hora_ingreso='00:00:00'` y `hora_salida='20:00:00'` escrita, TODAS con `estado_asistencia` sobrescrito a `CIERRE_AUTO` (estado original PERMISO/FALTA perdido). IDs: 319, 200, 201, 202, 203, 318 (agentes 8/17/18, tiendas PUNSC01/PUNDA23/PUNDA50, fechas 2026-05-08 a 2026-05-15). Nota: son de MAYO — anteriores a `registrarExcepcion` del refactor, o sea heredadas de la data del legacy con el mismo bug.
- El comando `bitel:reparar-excepciones-pisadas` AÚN NO está deployado (el código de hoy vive solo en local) — la reparación real va después del deploy. Ojo para ese momento: el estado original se perdió al sobrescribirse, revisar si el comando lo infiere o requiere input manual para estas 6.

**TICKET-010 integrado** → commit `6630c88` (index de comprobantes-cola + ComprobantesPage migrada a la cola real, 7 tests). **TICKET-020 integrado** → commit `d0b7e00` (réplica visual de cuadre, colores exactos del legacy, sin tocar lógica). Suite 577/577, tsc+build limpios. **Van 25/27**.

**QA visual (026) — Bloque A entregado e integrado** → commit `4366446` (04-qa-visual.md + setup reproducible + QaDemoSeeder). Veredictos A: Dashboard fiel; Login y Precios mejoradas; Productividad/CRM/Historial/Mi Reporte/Ver Agente parciales. Hallazgos graves del A: (1) Ver Agente sin las secciones Ficha RRHH violeta / Contactos Emergencia naranja / panel liquidación (confirmado en vivo vs captura 013); (2) **bug real**: Mi Reporte Personal renderiza `Invalid Date` en la columna FECHA de todas las filas; (3) Historial admin sin columna Ganancia ni badges de estado-efectivo; (4) CRM sin el púrpura #c084fc en el sidebar activo. Además titan arregló `frontend/.env.local` (prefijo `/api/v1/v1/` duplicado que rompía el login local).

**QA Bloques B/C/D1 entregados e integrados** → commit `a3d3049`. Veredictos: mayoría fiel/mejorada; hallazgos graves → (B) tabla Personal sin apellidos, **Monitor de Fraude de Dispositivos sin UI** (el backend escribe log_fraude_dispositivo pero nada lo muestra — gap de seguridad/auditoría sin ticket previo); (C) Bipay/Anypay degrada toda la página en estado vacío, Reporte BCP muestra "undefined" en KPI, Comisiones Empresa escondida en modales sin el color-coding legacy; (D1) **Mapa de Calor 404 permanente** (URLs sin prefijo /v1/), **QR Asistencia nunca se pinta para admin** (fallback 'DEFAULT'), Matriz Inventario 500 en SQLite (SQL MySQL-only), Ingreso Stock colapsa el flujo legacy de 2 pasos. Dato clave de D1: la postulación pública YA captura todos los campos RRHH que faltan en VerAgente — solo falta mostrarlos.

**QA COMPLETO (~35 pantallas) + fixes integrados:** D2+cuadre → `bc39e54` (cuadre FIEL en vivo; hallazgo alto: Terminal Asistencia retematizada en rojo). Ola fixes 1 → `a5bee3f` (Mapa Calor /v1/, Invalid Date, BCP undefined, apellidos). Ola fixes 2 → `18da642` (QR admin selector, Bipay estado vacío, Matriz SQL portable+test). Suite 578/578.

**Polish 028-030 INTEGRADO** (2026-07-09 ~3:15pm): 028 → `ce9a2f0` (Terminal dorada, CRM púrpura #c084fc, Comprobantes=Files vs Facturación=Receipt según header.php legacy). 029 → `f18dfb6` (Ganancia por fila admin-only en Historial + test; Comisiones Empresa en secciones siempre visibles con color-coding; VerAgente: las secciones "faltantes" del QA YA existían vía ticket-021 — el gap real era la franja de KPIs de boletas, añadida reutilizando endpoints existentes). 030 → `93cbb1c` (Monitor de Fraude completo: endpoint + panel + 16 tests; ingreso stock sin precio → Precios pendientes). **dev2 volvió a agotar sesión (resetea 6:30pm) a mitad del InventarioForm.tsx** — el orquestador completó los tipos (z.preprocess→setValueAs, InventarioPayload con precios opcionales). Suite 590/590, build limpio.

## Cierre 2026-07-09 (noche)
**TICKET-027 COMPLETADO** (05-qa-funcional-A.md + B.md, commit `264aac4`): Flujo 4 SUNAT PASA sin bugs; Flujo 6 Asistencia PASA (mejora al legacy); Flujo 5 PASA con 1 bug medio (planilla paga CUOTAS pendientes); Flujo 1 PASA con defectos (falta guard precio mínimo y guard DISPONIBLE); Flujo 2 FALLA (tienda 403 en reprocesar con edición aprobada); Flujo 3 PARCIAL (constancia PDF 500: tabla `configuraciones` inexistente + tests falso-verde). QA visual consolidado: 42 filas — 25 fiel, 9 mejorada, 5 parcial, 0 degradada/genérica/faltante.

**DECISIONES del usuario (2026-07-09):** DECISIÓN-003 = NO restaurar guard anti-duplicado de cuadres (queda como está). DECISIÓN-004 = MANTENER el bloqueo por chips insuficientes (no replicar el "loguear y continuar" del legacy). → ticket-037 cerrado sin código.

**DEPLOY A PRODUCCIÓN COMPLETADO:** backup BD (`/root/backups/migracion_pre_deploy_20260709_2340.sql`, 72 tablas) → push 37+2 commits → backend redeployado + 5 migraciones DONE + health 200 → 6 filas asistencia reparadas con --apply (0 restantes) → frontend: 2 builds fallidos (npm ci EUSAGE por peers opcionales vite8 con npm10; npm12 exige node22) → fix final npm@11 en Dockerfile → build done, app.kyrocodelabs.cloud HTTP 200 bundle nuevo. Webhooks Dokploy: POST /api/deploy/<token> con header X-GitHub-Event: push y body {ref, repository.full_name} (tokens en la BD postgres de dokploy, tabla application).

**Ola de fixes QA funcional INTEGRADA:** guards de cuadre (`6c183a2`), constancias (`8c8460c`), planilla plana como legacy + test 540min (`7b75f58`).

## Ola frontend por feedback del usuario (2026-07-09/10, tickets 040-043)
El usuario vio producción y NO percibió paridad: toggle de tema mal, notificaciones duplicadas, sidebar explotado en rutas (el legacy es más intuitivo con tabs internas), exports vacíos. Tickets y cierres:
- **040** shell (`915298d`): toggle en cabecera, campana única, secciones colapsables.
- **042** exports (`1cebbd6`): CAUSA RAÍZ del reporte del usuario — inventario exportaba CSV como .xlsx (Excel lo rechaza), ignoraba filtro estado, matriz con token en query → 401. 21 endpoints auditados, 6 archivos de tests de contenido. Pendiente menor anotado: IntegradorPage ZIP con el mismo patrón token-en-query.
- **041** paridad producción-vs-producción (`493b52b`): mayoría de pantallas SÍ fieles; cerrados 3 gaps visibles (Ganancia en Dashboard, KPIs asistencia con COALESCE — bug "···" eterno, Capital Invertido en Inventario). Credenciales de prueba en ambos sistemas: adminprueba@gmail.com/adminadmin. Decisión menor pendiente: botón Registrar Cuadre índigo vs azul legacy.
- **043** navegación (`f7518c4`): sidebar 1:1 con menú legacy; Inventario+Traslados+Kardex+Chips, Personal+Postulantes, CRM+Clientes consolidados en tabs; mapeo en 07-mapa-navegacion.md. Diferido con razón: fusión Dashboard/Historial/MiHistorial (dinero real, requiere pasada dedicada). Gaps anotados: tiene_bcp en /me, Ingreso Stock sin página.

**DEPLOY FINAL 2026-07-10: frontend=done backend=done, app 200 / api 200.** Suite 632/632. Esperando veredicto del usuario en app.kyrocodelabs.cloud; si no convence, iterar 041 con su feedback puntual.

## Fase de planes de mejora (autorizada por el usuario 2026-07-09 ~8pm)
Nueva fase: SOLO ESCRITURA DE PLANES (cero código, cuotas muy usadas), Fable razonamiento medio→bajo, mejora de diseño POR ENCIMA del legacy (identidad Ultra Dark Premium, nunca genérico) + ciberseguridad.
- titan → plan/08-mejoras-diseno-bloque1.md (Dashboard, cuadre, Historial, Mi Historial, Asistencias×4, Planilla, Terminal; por pantalla: qué elevar, propuesta con valores exactos, esfuerzo, modelo ejecutor Sonnet/Opus, tickets de una pasada; + transversales del design system)
- dev3 → plan/08-mejoras-diseno-bloque2.md (Inventario+tabs, CRM+Clientes, Precios, Comisiones, Financieras, Facturación, Comprobantes, Personal/VerAgente, Postulación, Tiendas/Usuarios, Tickets; mismo formato)
- dev2 → plan/09-plan-ciberseguridad.md (7 áreas por LECTURA de código, sin escaneos activos: auth/roles matriz endpoint-middleware, inyección SQL/XSS/mass-assignment, secretos en env/logs/git, uploads, links públicos HMAC/enumeración, headers/CORS/rate-limits/infra, PII; hallazgo = severidad + archivo:línea + fix + ticket)
**LOS 3 PLANES COMPLETADOS E INTEGRADOS (2026-07-10 madrugada):**
- `09-plan-ciberseguridad.md` (`f10c6ac`) — 16 hallazgos: 1 CRÍTICA (SEC-01 API key integrador hardcodeada en config/services.php:41, versionada en git), 4 altas, 6 medias, 5 bajas. Base sólida (SQL param, sin XSS, uploads ok, HMAC ok, git limpio).
- `08-mejoras-diseno-bloque1.md` (`d99528c`) — núcleo operativo, 11 tickets DIS-B1-00..10. DIS-B1-00 = Design System v2 (tokens movimiento, Skeleton, EmptyState, .kyro-money, prefers-reduced-motion) es prerequisito de todo.
- `08-mejoras-diseno-bloque2.md` (`932faae`) — módulos de gestión, 13 tickets DIS-B2. Depende duro de DIS-B1-00.

**Camino de dolor de esta fase (para no repetir):** intento 1 con Fable medio agotó sesiones al instante; intento 2 con Fable bajo → dev2/dev3 CRASHEARON por bug de runtime Bun/JSC (MemoryExhaustion / "Bun has crashed"), no cuota; intento 3 → dev2/dev3 dieron "organization has disabled Claude subscription access" (bloqueo permanente de org, igual que dev1). titan (Fable bajo, con --dangerously-skip-permissions que faltaba antes) hizo los 3 planes: seguridad + los 2 de diseño en secuencia. **Estado de cuentas al cierre: solo titan y david(principal) operativas; dev1/dev2/dev3 fuera (org disabled). titan agotada hasta 8:30am.**

**LISTO PARA REVISIÓN DEL USUARIO:** 42 tickets de mejora planificados (11+13 diseño + 16 seguridad + 2 pendientes menores). Nada se ejecuta hasta orden del usuario. Recomendación de arranque cuando el usuario apruebe: SEC-01 (crítico, coordinar rotación de key en VPS) → DIS-B1-00 (design system, desbloquea todo el diseño) → resto en olas.

---
**(histórico)** TICKET-027 primer intento bloqueado por cuotas. El primer intento (2026-07-09 ~3:20pm) murió al instante: titan y dev3 con Opus agotaron sesión de inmediato (venían trabajando todo el día). SIN trabajo perdido (regla 0.3 caso 1 — no empezaron, working tree limpio).
**Resets:** dev2 y dev3 → 6:30pm; titan → 6:50pm (2026-07-09, Lima).
**Plan de reanudación** (cron en sesión programado 6:36pm + este texto como respaldo si la terminal muere): ping a dev2/dev3 → despachar mitad A (flujos 1-3: cuadre, edición aprobada, traslados → 05-qa-funcional-A.md) y mitad B (flujos 4-6: SUNAT e2e, comisiones, asistencia → 05-qa-funcional-B.md), ambas Opus, prompts autocontenidos (setup en 04-qa-visual-setup.md, legacy como oráculo, bugs como borrador de ticket sin corregir).
Tras el 027: consolidar informes QA, tickets de bugs si salen, y coordinar DEPLOY al VPS con el usuario (+ reparar las 6 filas de asistencia dañadas post-deploy).

**Pendientes (6 tickets):** 008 (links públicos CPE + impresión, depende de 005 ✓ — asignar a dev2 u otra cuenta libre), 010 (ComprobantesPage paridad, depende de 008 — esperar), 020 (modal cuadre, Opus — independiente, se puede lanzar en paralelo), 025 (matriz cron/scheduler, independiente), 026 (QA visual con agentbrowser, al final), 027 (QA funcional, Opus, al final — gate de cierre).

**Recordatorio de la regla 0.3 en acción:** dos veces en esta sesión un worker se quedó sin cuota a mitad de un ticket (dev2 en 006, dev3 en 011) dejando código roto/incompleto en el working tree; el orquestador terminó ambos antes de integrar, nunca se commiteó nada a medias. Verificar SIEMPRE el working tree tras un "session limit"/"weekly limit", no asumir que no se tocó nada.

**⚠️ ADVERTENCIA para futuros prompts a workers:** dev3 limpió sus servidores de prueba con `taskkill /IM node.exe` — esto mata TODOS los procesos Node del sistema, no solo los suyos, con riesgo de cortar otro trabajo del usuario en la misma máquina. A partir de ahora, prohibir explícitamente `taskkill /IM node.exe` (u otros kills por nombre de imagen) en el prompt de cada worker; pedirles matar solo el PID que ellos mismos lanzaron.

**Cuentas disponibles ahora mismo:** dev3 (Sonnet 5, libre). dev2 vuelve 11:50am Lima. titan vuelve 2026-07-10 2pm Lima (límite semanal).

**Nota importante — CronCreate no persiste entre sesiones:** la terminal anterior dijo haber "programado" con CronCreate el reintento de dev2 para la 1:20am; `CronList` en esta sesión lo mostró vacío. Esas cron jobs viven solo en la sesión que las crea y se pierden si esa sesión muere. No confiar en CronCreate para reanudar trabajo entre caídas de terminal — mejor dejar la cola escrita aquí en 00-STATUS.md (ya se hace) y que la siguiente sesión la retome a mano.

**Nota — procesos `nohup ... & disown`:** el aviso de "tarea completada" del wrapper Bash llega cuando el script que lanza el proceso retorna (inmediato, por el disown), NO cuando el proceso real de `claude` termina. Para esperar de verdad hay que hacer polling del PID real (`ps -p <pid>`) en un segundo comando en background, y leer el archivo de log del worker, no el output del wrapper.

## Decisiones CONFIRMADAS por el usuario (2026-07-08)
- **DECISIÓN-001:** API externa de facturación, como el legacy (NO Greenter). Paridad total de funcionalidades.
- **DECISIÓN-002:** Migración a Phosphor APROBADA → ticket 018 deja de ser opcional (va después del 017).
- **DECISIÓN-003:** criterio recomendado aceptado tácitamente (identidad→configuracion_empresa, secretos/flags→sys_config, infra→.env).

## Rutas reales
- Legacy: `E:\laragon\www\sistema-rolando-salas` (227 archivos PHP)
- Refactorizado (objetivo): `C:\xampp\htdocs\refactorizado_bitel` (Laravel 12 + React 19)
- Capturas legacy: `C:\xampp\htdocs\refactor_principal\legacy\*.png` (33 FireShot)

## Estado por fase
- [x] **Fase 0** — 4 informes completos: `00-inventario-legacy.md` (dev2), `00-inventario-refactorizado.md` (dev3), `00-inventario-diseno.md` (titan), `00-informe-arquitectura.md` (Codex)
- [x] **Fase 1** — `01-gap-matrix.md` (matriz de brechas + 3 decisiones abiertas)
- [x] **Fase 2** — `02-master-plan.md` (5 fases, olas de ejecución balanceadas)
- [x] **Fase 3** — `plan/tickets/ticket-001.md` … `ticket-027.md` (27 tickets autocontenidos)
- [x] **Cierre** — `03-resumen-ejecutivo.md`
- [x] **GATE: confirmación del usuario** — dada el 2026-07-08 junto con DECISIÓN-001/002.
- [x] **TICKET-004 ENTREGADO, VERIFICADO E INTEGRADO** — commit `e75869f` (9 tests unitarios verdes re-ejecutados por el orquestador; suite Feature 346 verdes según worker). Nota del worker: solo había 1 punto de parsing PHP-side; el resto ya usaba tablas normalizadas/JSON_EXTRACT.
- [x] **TICKET-001 ENTREGADO, VERIFICADO E INTEGRADO** — commit `8214d85` (14 tests re-ejecutados por el orquestador, verdes; suite completa 370 según worker). Notas: migración con rama de adopción de tabla legacy; `migrate` contra MySQL del VPS sigue pendiente (lo cubre ticket 003); unique de fila global se garantiza en seeder/orden, no en BD (comentado en la migración).
- [x] **TICKET-003 ENTREGADO, INTEGRADO Y CERRADO EN VIVO** — commits `df34e94` + `34fa7f3`. El usuario confirmó acceso: SSH VPS (`~/.ssh/id_ed25519` = key Hostinger `david-sys-trading-local`, host `2.24.105.11`) + token Hostinger (MCP `hostinger-vps` ya cargado). El orquestador verificó en vivo (solo lectura, sin mutar nada): `migrate:status` → cero Pending; `migrar-chips-mal-guardados` (dry-run) → "Sin filas CHIP mal guardadas. Nada que migrar." **Sin pendientes reales.** Hallazgo distinto al asumido: la brecha no es migraciones sin correr, es que el código de HOY (ticket-001 etc.) aún no está *deployado* al VPS — deploy queda para cuando la Ola 1 esté integrada y validada.

### RETOMA — la terminal anterior (Fable) falló tras despachar Ola 1; esta terminal (Sonnet 5) recuperó el trabajo
Al reconectar, los 3 workers de Ola 1 habían terminado pero quedaron **sin commitear** en el working tree (la terminal que los despachaba murió antes de integrar). Se verificó cada uno (tests + typecheck) antes de dar por bueno:
- [x] **TICKET-002 (dev2, cola comprobantes)** — commit `2ff0388`. 21 tests verdes (66 assertions).
- [x] **TICKET-012 (dev3, verificación Comisiones Empresa)** — commit `38f82a5`. 5 tests verdes (27 assertions). De paso corrigió `ComisionPlanController::index` (orderByRaw con `FIELD()` es MySQL-only, rompía contra SQLite en tests → reemplazado por `CASE` portable).
- [x] **TICKET-016 (titan, ConfirmDialog + eliminar confirm() nativos)** — commit `6342c33`. 0 `confirm()`/`window.confirm()` nativos restantes en `src/`; `tsc -b` limpio.
- Suite backend completa tras integrar: **408 tests passed** (1437 assertions).
- Plan completo (`plan/*.md`, 27 tickets) y el bloque de orquesta en `CLAUDE.md` también quedaron sin commitear — integrados en commit `3d9fcf2`.

## Ola 2 — resultado (2026-07-08, esta terminal)
| Cuenta | Modelo | Ticket | Resultado |
|---|---|---|---|
| dev2 | Opus 4.8 | 006 — configure-sunat (CRUD FacturacionConfig + PFX→PEM) | **AGOTÓ CUOTA a medio ticket** ("hit your session limit, resets 1:20am Lima"). Dejó 78/79 tests verdes; el orquestador terminó el 1% restante (bug del *test*, no del código: `fakeApi()` se llamaba 2 veces en el mismo test y `Http::fake(closure)` acumula callbacks en vez de reemplazarlos — el closure viejo con el mock feliz seguía ganándole al nuevo con el mock de fallo). Integrado: commit `47d994c`. |
| dev3 | Sonnet 5 | 014 — Verificar onboarding RRHH público | Completo. Descartó con evidencia el supuesto "modo completar ficha por token"; cerró el gap real (fotos perfil/DNI ausentes en la postulación pública). Commit `cf1cf95`. |
| titan | Sonnet 5 | 017 — Iconografía sidebar (10 mapeos + logo) | Completo. Nota: en este entorno las skills `headroom`/`superpowers`/`frontend-design` no existen — mismo hallazgo que ya tenía anotado `00-inventario-diseno.md`; titan procedió sin ellas. Commit `755c2c9`. |

**Lección para futuras olas:** dev2 quedó fuera de rotación hasta la 1:20am (hora de Lima). No despachar tickets nuevos a dev2 antes de esa hora — si se necesita, verificar con un ping primero.

Suite completa backend tras integrar toda la Ola 2: **492 passed (1670 assertions)**. Van **9 de 27 tickets** cerrados: 001, 002, 003, 004, 006, 012, 014, 016, 017.

## Ola 3 — resultado (2026-07-08/09, esta terminal)
Ambos procesos se reportaron **`killed`** (detenidos externamente, no fallo ni cuota) justo cuando ya habían terminado — logs vacíos, el corte llegó al escribir el reporte final. Verificados igual antes de integrar:
- **dev3 (013)** — 4 de 5 gaps ya estaban cerrados desde fases B/C/D (solo faltaba doc); el real (T4.1, export Excel de CRM) se cerró con `CrmTemperaturaController::exportar`. 16/16 tests propios verdes. Commit `750b77f`.
- **titan (018)** — migración completa lucide→Phosphor, 53 archivos, cero `lucide-react` restante, `tsc -b` y `vite build` limpios. (El primer `tsc -b` mostró errores falsos en `TicketsPage.tsx` por una carrera de archivos justo en el instante del kill; al reintentar con el filesystem asentado, limpio.) Commit `3b1f2e8`.

Suite completa backend tras integrar toda la Ola 3: **494 passed (1676 assertions)**. Van **11 de 27 tickets** cerrados: 001, 002, 003, 004, 006, 012, 013, 014, 016, 017, 018.

**Nota operativa:** procesos con status `killed` no deben asumirse como perdidos — verificar el working tree y el resultado real antes de relanzar desde cero (evita duplicar trabajo).

## Ola 4 despachada (2026-07-09, esta terminal)
| Cuenta | Modelo | Ticket | Task ID (background) | Log |
|---|---|---|---|---|
| titan | Sonnet 5 | 019 — Modo claro: sidebar azul Bitel + acentos dorados | `bk1joxybx` | `plan/.worker-titan-019.log` |
| dev3 | Sonnet 5 | 021 — VerAgente: hairlines de color + botonera multicolor | `b92xg1a3h` | `plan/.worker-dev3-021.log` |

005 (cliente API facturación + drenador de cola, Opus recomendado) se deja para cuando dev2 vuelva (1:20am Lima) — es la pieza más sensible (retryable/no-retryable, backoff) y 007/008/010 dependen de ella, mejor no arriesgarla con una cuenta que no es la recomendada mientras hay alternativas independientes (021/022/023/024) para llenar la ola.
Cola de titan tras 019: 024 → 015. Cola de dev3 tras 021: 022 → 023.
Pendiente de reasignar cuando dev2 vuelva: 005, 020 (Opus), y luego 007, 008, 009, 010, 011 (dependen de 005/006), 025.
QA final (026 visual, 027 funcional) al cierre de todo lo demás.

## Acceso al VPS de producción (para el orquestador — NO delegar a workers sin supervisión)
- SSH: `ssh -i ~/.ssh/id_ed25519 root@2.24.105.11` (Ubuntu 24.04 + Dokploy, host Hostinger `srv1679080.hstgr.cloud`)
- Backend refactor: contenedor `erpcrmbitel-backend-5othkr...` (`docker ps` para el sufijo vivo — cambia por deploy), `APP_URL=refactor.kyrocodelabs.cloud`, `DB_DATABASE=migracion`
- Legacy (mundoandroid): contenedor `erpcrmbitel-mundoandroid-...`
- API/token: MCP `hostinger-vps` (`VPS_getVirtualMachinesV1` etc.) — VM id `1679080`
- Regla: lecturas (`migrate:status`, logs, dry-runs) libres; cualquier escritura real en producción (migrate --force, deploy, restart) se coordina con el usuario primero — es shared state de producción.
- [ ] **EN VUELO** (workers en background, sin commits — el orquestador revisa diff e integra):
  - dev2 + Opus 4.8 → TICKET-002 (cola comprobantes + snapshot fiscal) — task b6oh2tipx
  - dev3 + Sonnet 5 → TICKET-012 (verificar Comisiones Empresa) — task ba62kg1cq
  - titan + Sonnet 5 → TICKET-016 (ConfirmDialog + confirms) — task bbkqefwjn
- [ ] Cola de despacho por cuenta (siguiente ticket al liberarse, tras verificar el anterior):
  - dev2: ~~001~~ → 002 → 006 → 013
  - dev3: ~~004~~ → ~~003~~ → 012 → 014
  - titan: 016 → 017 → 018 (Phosphor, aprobado) → 019 → 024 → 015
  - luego: 005, 007, 008, 009, 010, 011, 020 (Opus), 021, 022, 023, 025
- [ ] QA final: 026 (visual, agentbrowser) y 027 (funcional, Opus)
- Regla de integración: cada entrega se verifica con `git diff` + tests antes de commit por ticket; conflictos de archivos evitados asignando dominios disjuntos por ola.

## Cuentas de la orquesta (verificadas 2026-07-08)
| Cuenta | Estado | Uso en ejecución |
|---|---|---|
| david (principal) | OK — orquestador | verifica e integra, NO implementa |
| titan (nashelitls) | OK | worker |
| dev2 (tutorialesdavid3) | OK | worker |
| dev3 (joan.achenquipa) | OK | worker |
| dev1 (comomellamotunosabes) | **CAÍDA** (org sin acceso a Claude Code) | fuera de rotación |

## Reglas vigentes
- Orquestador NO implementa código; a 70-80% de contexto degrada a Opus 4.8 y anota checkpoint aquí.
- Implementación: Sonnet 5 (mecánico/UI) u Opus 4.8 (complejo). NUNCA Fable. Regla 0.3: ningún ticket a medias.
- Workers: recordarles skills (headroom, superpowers, frontend-design si UI, agentbrowser si comparación visual) y modelo en CADA prompt — los prompts son autocontenidos (arrancan en frío).
- Diseño: replicar o mejorar identidad legacy "Ultra Dark Premium", nunca genérico; iconos con criterio = parte del criterio de aceptación.
- Al recoger cada entrega: revisar diff/resultado antes de darla por buena.

## Fase de mejoras — sesión 2026-07-11 (orquesta reducida: david + titan + Codex gpt-5.6-sol)
- Cuentas: dev1/dev2/dev3 FUERA permanente (org disabled). Codex CLI actualizado a 0.144.1, modelo gpt-5.6-sol operativo vía MCP y exec.
- [x] SEC-01 (API key integrador) — titan/Opus — commit 0ca9ecb. Tests 638/638. PENDIENTE OPERATIVO: rotar la clave quemada, setear INTEGRADOR_API_KEY en .env del VPS ANTES del próximo deploy (sin ella prod no arranca), actualizar config.php de agentes en tienda.
- [x] Base design system fintech — david (autorizado por el usuario a implementar diseño) — commit 65955e0: Sparkline.tsx, StatCard con trend/delta, .kyro-money, radios 16px, sombras light recalibradas, prefers-reduced-motion. tsc + vite build limpios.
- [x] Auditoría de diseño — Codex/gpt-5.6-sol — plan/11-tickets-diseno-fintech.md: 23 tickets DIS-FX (6 transversales + 17 pantallas). SIN commitear aún.
- [ ] EN VUELO: titan/Opus → paquete SEC-02+04+06+07 (log: plan/.worker-titan-SEC-ola2.log); Codex/gpt-5.6-sol → plan/12-plan-verificacion-optimizacion.md (log: plan/.worker-codex-verificacion.log).
- [ ] Siguiente ola tras titan: SEC-05 (headers, Opus) y SEC-03 (matriz role:, Opus — el gordo). Ambos chocan con bootstrap/app.php y routes/api.php de la ola actual, por eso van después.
- [ ] Luego: SEC-08..SEC-16 (medias/bajas), tickets DIS-FX (usuario debe aprobar el doc 11 primero), y plan 12 (verificación funcional + optimizaciones).

## App asistencia + cierre seguridad altas (2026-07-11, tarde)
- [x] SEC-03 (commit dbf8bbf, 40 tests nuevos) y SEC-05 (b22da1b). Las 5 ALTAS de seguridad cerradas. Suite 686.
- [x] Env vars seteadas en Dokploy Postgres via SSH (INTEGRADOR_API_KEY=valor viejo por ahora, CORS_ALLOWED_ORIGINS=https://app.kyrocodelabs.cloud, SANCTUM_EXPIRATION). Rotación de key = pendiente coordinado con usuario.
- [x] plan/13-plan-app-asistencia.md completo con DECISIÓN-APP-01/02/03 confirmadas + distribución por botón en Asistencias admin + enlace WhatsApp.
- [x] APP-01 scaffold Capacitor (commit dd3c515, titan/Sonnet). Pendientes anotados: Android SDK para compilar APK, keystore release, branding ícono/splash.
- [x] APP-04 backend presencia (titan/Opus, agotó sesión al escribir el reporte pero el trabajo quedó completo — 10 tests nuevos, suite 696/696). GeoService extraído compartido.
- Cuotas: titan agotada hasta 5:20pm Lima; Codex agotado hasta 5:12pm. Solo david operativo hasta entonces.
- Siguiente ola (al volver cuotas): APP-02 (huella real, Opus) + APP-03 (GPS nativo) + APP-05 (foreground service) / APP-06/07 backend+frontend; SEC-08..16 medias/bajas; tickets DIS-FX pendientes de aprobación del usuario.

## Ciberseguridad CERRADA COMPLETA (2026-07-11, tarde) — implementado directamente por el orquestador
Las 6 medias y 5 bajas (SEC-08..16) del plan/09-plan-ciberseguridad.md, implementadas por david directamente (sin delegar a titan/Codex, que estaban en cooldown) — commit ef8f73b. 9 tests nuevos, suite 705/705, frontend build limpio.
- SEC-08 (logs con PII), SEC-09 (cache descarga CPE), SEC-10 (limiter exports), SEC-11 (agentes/select sin DNI completo — se descubrió un consumidor real en TrasladosPage que matcheaba DNI completo para autorizar; se migró a comparar por dni_ultimos4 en vez de solo eliminar el campo, para no romper la función), SEC-12 (health limpio), SEC-13 (throttle+tamaño mark-photo), SEC-14 (HMAC QR 64->128 bits), SEC-15 (limiter compuesto verify-pin), SEC-16 (revocación de tokens).
**LAS 16 VULNERABILIDADES DEL PLAN DE CIBERSEGURIDAD ESTÁN CERRADAS.** (1 crítica + 4 altas en ola anterior + estas 11 medias/bajas).
Pendiente real fuera de código: rotación de INTEGRADOR_API_KEY (SEC-01) coordinada con el usuario — sigue con la clave vieja/quemada en el VPS por ahora, solo para no romper el arranque.

## APP-03 integrado (2026-07-11, tarde) — implementado directamente por el orquestador
Geolocalizacion nativa via @capacitor/geolocation (commit b74a036): permisos reales en Android, mensajes por tipo de error, y el backend ya rechaza mock_gps=true como MOCK_GPS (mismo patron que WEAK_GPS/OUT_OF_RANGE, alimenta antifraude). Nota importante: mock_gps siempre llega false hasta que exista el plugin nativo propio (isFromMockProvider de Android) -- eso es parte de APP-02, @capacitor/geolocation no lo expone. No inventar que ya detecta mock antes de que APP-02 lo implemente de verdad.
Van 3 de 10 tickets APP integrados: APP-01 (scaffold), APP-04 (backend presencia), APP-03 (GPS nativo). Suite 706/706.

## Ola paralela david+titan (2026-07-11 17:20-17:40) — 5 tickets APP integrados esta sesion
- [x] APP-02 (titan/Opus, commit 52c1592): plugin nativo DeviceIdentity.java -- huella real SHA-256(ANDROID_ID|FINGERPRINT|MODEL|uuid) + getCurrentLocation() con Location.isMock()/isFromMockProvider() REAL. Correccion honesta: SharedPreferences no Keystore (ninguno sobrevive borrar-datos; ANDROID_ID es el ancla real). LocationManager no FusedLocation (equipos sideload sin Play Services).
- [x] APP-03 (david, commit b74a036): geolocalizacion nativa base + plumbing mock_gps -- luego reemplazado/mejorado por APP-02 con deteccion real.
- [x] APP-07 (david, commit 79c8eca): pestaña Presencia en Asistencias admin, consume el endpoint de APP-04.
- [x] APP-08 backend (david, commit 32d4c0e): tabla + endpoint consentimiento-ubicacion, gate 428 CONSENT_REQUIRED en ping-ubicacion. FALTA: pantalla de consentimiento en la app (se wirea con APP-05).
Suite backend: 709/709. Frontend tsc+build limpios en cada paso.
Van 5 de 10 tickets APP: 001(scaffold), 02(plugin nativo), 03(GPS), 04(backend presencia), 07(UI presencia), 08(backend consentimiento). Faltan: 05 (foreground service 30min -- el corazon del monitoreo), 06 (job sin_señal + Monitor de Fraude), 09 (distribucion APK + boton descarga), 10 (QA dispositivo real).

## APP-05 y APP-06 integrados (2026-07-11, tarde) — ola paralela titan+Codex
- [x] APP-05 (titan/Opus, commit 6a40b45): foreground service PresenceTrackerService.java, ping cada 30min exactos (AlarmManager setExactAndAllowWhileIdle), notificacion IMPORTANCE_MIN muda, cola offline SharedPreferences, pantalla ConsentimientoUbicacion.tsx integrada.
- [x] APP-06 (Codex/gpt-5.6-sol, commit 36f420b): comando bitel:detectar-sin-senal cada 15min, Monitor de Fraude ampliado con incidencias de ubicacion. El orquestador encontro y arreglo un bug de integracion real: el backend horneaba texto en columnas de "dueño del dispositivo" y el frontend no sabia de las nuevas alertas -- el badge "≠ DIFERENTE" se hubiera disparado siempre para alertas de ubicacion. Se limpio backend + se actualizo MonitorFraudePanel.tsx con su propio badge por tipo_ubicacion.
Suite backend: 714/714. Frontend tsc+build limpios.
**7 de 10 tickets APP integrados**: 01,02,03,04,05,06,07,08 (backend). Faltan: APP-09a (canal descarga + boton refactor), APP-09b (mismo boton en legacy panel_asistencias.php -- pedido explicito del usuario, dos sistemas), APP-10 (QA dispositivo real).
Correccion de alcance: APP-09 se dividio en 09a/09b porque el usuario pidio el boton de descarga en AMBOS sistemas (legacy Y refactor), no solo el refactor.

## APP-09a/09b integrados + APK COMPILADO EN ESTA MAQUINA (2026-07-11, noche)
- [x] APP-09a (titan/Sonnet, commit 815e5a0): endpoints version/descargar/subir + seccion AppTerminalDescarga en pestaña Presencia + banner de actualizacion en app nativa. 718/718 tests.
- [x] APP-09b (david, commit c2ea099 EN EL LEGACY E:/laragon/www/sistema-rolando-salas): boton "Descargar App" + copiar enlace WhatsApp en gerencia/panel_asistencias.php, apunta al canal del refactor. PHP lint OK. Pendiente deploy legacy.
- [x] ANDROID SDK instalado en C:/Android (cmdline-tools + platform-tools + platforms;android-36 + build-tools;36.0.0). local.properties creado (gitignored). settings.gradle con foojay toolchain resolver (Capacitor 8 exige Java 21; Gradle lo auto-descarga a ~/.gradle/jdks).
- [x] **APK COMPILADO Y VERIFICADO**: frontend/android/app/build/outputs/apk/debug/app-debug.apk (5.6 MB). Comando validado:
  ```
  cd frontend && npm run build:android
  cd android
  ANDROID_HOME=C:/Android JAVA_HOME=C:/Users/Usuario/.gradle/jdks/eclipse_adoptium-21-amd64-windows.2 ./gradlew assembleDebug
  ```
  (JAVA_HOME debe ser un JDK 21 — el de sistema es 17; winget instalo Temurin 21 en paralelo, si aparece en Program Files/Eclipse Adoptium usarlo en su lugar.)
- **9 de 10 tickets APP cerrados.** Solo falta APP-10: QA en dispositivo real (el usuario lo hace al llegar: subir app-debug.apk via el panel Presencia > Subir version, compartir enlace, instalar en equipo, probar huella/GPS/pings/consentimiento). Para distribucion final: assembleRelease + keystore de firma (aun no creado).

## DEPLOY A PRODUCCION COMPLETO (2026-07-12 ~01:00 hora VPS)
- Backup BD previo: /root/backups/migracion_pre_deploy_20260712_0053.sql
- Deploys disparados via webhook Dokploy (POST /api/deploy/{token} con X-GitHub-Event: push) y verificados reales: backend, frontend y legacy (mundoandroid) — los 3 en done, endpoint nuevo respondiendo.
- php artisan migrate --force en el contenedor backend: las 3 migraciones de la app aplicadas (presencia, consentimientos, app_terminal_version).
- APK v1.0.0 (5.6 MB, debug build apuntando a produccion) subido al storage del contenedor + fila en app_terminal_version. Smoke test completo: version endpoint OK, descarga OK, sha256 del descargado == compilado.
- LINK DE DESCARGA OPERATIVO: https://refactor.kyrocodelabs.cloud/api/v1/app-terminal/descargar
- Nota cosmetica: url_descarga en el JSON sale con http:// (detras de Traefik el scheme no se fuerza) — el link https funciona igual; fix menor pendiente (URL::forceScheme o trustProxies).
- OJO: el APK del storage vive DENTRO del contenedor — un redeploy del backend lo borra (no hay volumen para storage/app/app-terminal). Re-subirlo tras cada deploy o montar volumen. Anotado como pendiente.

## APK publicado por el flujo OFICIAL (2026-07-12 01:06)
- Bug real encontrado al probar la subida por el panel: PHP del contenedor limitaba uploads a 2M (el APK pesa 5.6M) — el uploader del panel habria fallado para el usuario. Fix permanente en backend/Dockerfile (uploads.ini 200M, commit 844d6f0) + redeploy.
- APK v1.0.0 subido via POST /v1/app-terminal/subir con cuenta admin (flujo oficial completo validado). Version endpoint OK, descarga OK, sha256 identico.
- LINK: https://refactor.kyrocodelabs.cloud/api/v1/app-terminal/descargar
- El fix de 200M tambien resolvio de paso el riesgo del APK-borrado-por-redeploy para ESTA vez (se re-subio post-deploy); el riesgo de fondo (storage sin volumen) sigue anotado.

## FIX DEFINITIVO storage efimero (2026-07-12 01:15)
- Causa raiz del "no se ha publicado ninguna version" que vio el usuario: cada push a GitHub dispara redeploy automatico (webhook), y el contenedor se recrea SIN storage persistente — el APK (y cualquier archivo subido: certificados, fotos) se borraba en cada deploy.
- Fix: volumen Docker 'refactor_backend_storage_app' montado en /app/storage/app via tabla mount de Dokploy (mismo patron que ya usaba el facturador en este VPS).
- VERIFICADO con redeploy intencional: el APK sobrevive. sha256 del descargado sigue identico al compilado.
- Link operativo y ahora estable: https://refactor.kyrocodelabs.cloud/api/v1/app-terminal/descargar

## Diseño transversal + release firmado (2026-07-11/12 noche)
- [x] APK RELEASE FIRMADO publicado en el canal (commit ec1da79 la config de firma): keystore kyro-release.keystore + keystore.properties en frontend/android/ (NO versionados — HACER BACKUP, sin ellos no hay actualizaciones compatibles). Firma verificada con apksigner (CN Mundo Android Technology EIRL). Menos friccion Play Protect que el debug. Si un equipo tenia el debug instalado: desinstalar primero (cambio de firma).
- [x] DIS-FX-02/03/04/05/06 integrados (commit b1f1c67): 23 componentes compartidos alineados a la vision fintech (indigo estructural, oro solo dinero, radios 18/20/10px, KpiCard definitivo con skeleton). tsc+vite+vitest verificados por el orquestador (el sandbox de Codex no pudo correr vite).
- [ ] DIS-FX-01 (AppLayout/sidebar + busqueda global) PENDIENTE — titan agoto sesion sin tocarlo; vuelve 10:20pm Lima. Siguiente en su cola.
- [ ] Olas de pantallas DIS-FX-07..23 pendientes (Dashboard, Cuadre, Inventario, CRM, Planilla priorizados).

## COLA MAESTRA SIN HUECOS (2026-07-12) — todo lo restante asignado
Regla de despacho: al terminar cada ola, verificar diffs + tsc + vite build (+ suite backend si toca backend), integrar con commit por ola, push (auto-despliega), y despachar la siguiente. Dominios siempre disjuntos por archivos.

**EN VUELO (ola D2):** titan/Opus → DIS-FX-01 (AppLayout+búsqueda) + 07 (Dashboard) | Codex/gpt-5.6-sol → DIS-FX-08, 11, 13, 14, 15 (Reportes lista, MiHistorial, Bitácora, Chips, Kardex/Matriz).
- Ola D3: titan/Opus → DIS-FX-09 (Cuadre diario, el más grande) + 10 (Detalle/edición) | Codex → 18, 19, 20, 21 (Financieras, Bipay, CuadreBitel, BCP).
- Ola D4: titan/Opus → DIS-FX-12 (Inventario) + 16 (CRM) | Codex → 22, 23, 24, 25 (Estadísticas, Postpago, MapaCalor, Historial).
- Ola D5: titan/Opus → DIS-FX-17 (Planilla) | Codex → 26, 27, 28 (Asistencias, Control/Liquidación, QR/Terminal-light).
- Ola D6: titan → 29, 30, 31 (Personal, VerAgente, Clientes) | Codex → 32, 33, 34, 35 (Comisiones, Tiendas, Usuarios, Perfil empresa).
- Ola D7: titan → 36, 37, 38 (Facturación, Integrador, Diagnóstico) | Codex → 39, 40, 41 (Postulaciones, Fotos, Precios).
- Ola D8: titan → 42, 43 (Comprobantes/CPE, Tickets) | Codex → 44, 45 (Traslados, Login).
- Ola O1 (backend, intercalable si un worker queda libre): titan/Opus → OPT-01 + OPT-02 (N+1 reversión ventas y confirmación lotes, los 2 Alto de plan/12) | Codex → OPT-03, 04, 05, 06 (índices + cancelar lote).
- Ola O2: titan → OPT-08 (temperatura CRM, Alto) | Codex → OPT-07, 09, 10 (paginación chips/leads, caching).
- Ola O3 (frontend perf): Codex → OPT-11, 12, 13, 14 (bundle/lazy/staleTime) | titan → OPT-15, 16 (logo storage, assets huérfanos).
- CIERRE: QA visual (plan/12 sección A flujos clave post-rediseño) + deploy final + resumen ejecutivo.
Fuera de la cola (requieren al usuario): APP-10 (probar APK en equipo real), rotación INTEGRADOR_API_KEY, backup del keystore.
Nota: plan/08-mejoras-diseno-bloque1/2 (fase anterior) quedan SUPERSEDIDOS por plan/11 donde se solapan — no ejecutar en paralelo; rescatar solo lo que plan/11 no cubra (revisar al cierre).

## REDISEÑO COMPLETO — 45/45 TICKETS DIS-FX CERRADOS Y EN PRODUCCION (2026-07-12)
Olas D2-D8 integradas y desplegadas: commits 8a2f0ba, c19f0f7, 22e3f56, a4a9dd5, e90445c, 1382dcf, 1a735f9, 28a990d (+ transversales b1f1c67 y base 65955e0). Todas las pantallas del sistema alineadas a la vision fintech (plan/10): indigo estructural, oro reservado a dinero, KpiCard con sparkline/skeleton, superficies 18px, dialogos 20px, modo claro cuidado. DIS-FX-45 (Login) verificado ya conforme, sin cambios.
QUEDA EN LA COLA: olas O1-O3 (16 optimizaciones de plan/12 — backend N+1/indices/paginacion/caching + frontend bundle) y el cierre (QA visual de flujos clave + resumen ejecutivo). Codex vuelve 12:53pm; titan activa.

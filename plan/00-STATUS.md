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
**Intento 1 (2026-07-09 ~8:15pm) FALLÓ por cuotas**: las 3 sesiones agotadas al instante con Fable medio, sin archivos producidos (0.3 caso 1, sin trabajo perdido). Resets: dev2/dev3 11:30pm, titan 11:50pm. Cron en sesión programado 11:37pm para relanzar con razonamiento BAJO. Si esta terminal muere, relanzar a mano tras esas horas con los mismos alcances de arriba.

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

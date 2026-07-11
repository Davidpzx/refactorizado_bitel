# Plan — App nativa del Terminal de Asistencia (Android)

**Objetivo:** convertir el terminal de asistencia (legacy `asistencia.php` → refactor `TerminalAsistenciaPage.tsx`) en una **app Android instalable**, con tres capacidades que el navegador no puede dar:

1. **GPS con permisos de verdad** — precisión fina garantizada, sin depender de que el navegador pregunte cada vez ni de que el agente acepte.
2. **Huella de dispositivo real** — hash derivado de identificadores físicos del equipo, no un UUID en localStorage que se borra limpiando datos.
3. **Monitoreo de presencia** — ping de ubicación cada 30 minutos durante el turno, para saber si el agente sigue dentro de la geocerca de su tienda.

## Lo que ya existe y se reutiliza (verificado en código)

| Pieza | Dónde | Estado |
|---|---|---|
| Terminal completo (QR, foto, DNI, flujos entrada/salida) | `frontend/src/pages/asistencias/TerminalAsistenciaPage.tsx` | Funciona en web; se reutiliza entero |
| Binding dispositivo↔agente con PIN de autorización | `POST /v1/autorizar-dispositivo`, `agentes.hash_dispositivo` | Funciona; solo cambia la CALIDAD del hash que recibe |
| Geocerca por tienda | `tiendas.lat_centro/lng_centro/radio_permitido` (default 60m) | Lista — el ping de presencia valida contra esto |
| Antifraude de dispositivos | `AsistenciaController` (~:812) + Monitor de Fraude (ticket-030) | Se alimenta con la huella nueva, más confiable |
| Validación de ubicación al marcar | `AsistenciaController` (distancia vs radio) | Se extiende al ping periódico |

## Decisión de stack — Capacitor (recomendado)

**Capacitor** envuelve el frontend React existente en una app Android nativa: se reutiliza el 95% del `TerminalAsistenciaPage` tal cual (misma UI, mismos endpoints) y lo nativo se añade como plugins. Alternativas descartadas: React Native/Flutter (reescribir toda la UI que ya funciona), PWA (no da background location ni identificadores de hardware — es exactamente lo que falta).

- Distribución: **APK directo instalado en los equipos de tienda** (sideload). No pasa por Play Store → sin fricción con la política de Google sobre background location, y son dispositivos del negocio. Actualizaciones: la app consulta `GET /v1/app-terminal/version` al abrir y ofrece descargar el APK nuevo desde el propio backend.
- **Distribución inicial (decisión del usuario 2026-07-11):** botón "Descargar app" en la pestaña Asistencias del panel admin (el equivalente refactor de `gerencia/panel_asistencias.php` del legacy), con URL de descarga estable y copiable que se comparte **por WhatsApp como enlace, no como archivo** — el enlace sirve siempre la última versión desde el backend; un APK adjunto en el chat envejece y genera versiones viejas circulando.
- iOS queda fuera del alcance (las tiendas usan Android — el cliente es "Mundo Android").

## Diseño técnico

### 1. Huella de dispositivo real
Hash `SHA-256(ANDROID_ID + fingerprint_build + install_uuid)` donde:
- `ANDROID_ID` (`Settings.Secure.ANDROID_ID`): estable por dispositivo+firma de app; sobrevive limpieza de datos del navegador (ya no aplica) y solo cambia con factory reset.
- `fingerprint_build` (`Build.FINGERPRINT` + `Build.MODEL` + `Build.SERIAL` si disponible): amarra el hash al hardware/firmware.
- `install_uuid`: UUID generado una vez y guardado en **Android Keystore** (no en storage borrable).
- Nota: IMEI/serial real no son accesibles desde Android 10 sin permisos de operador — ANDROID_ID + Keystore es el máximo real alcanzable y es suficiente: para clonar la identidad habría que rootear el equipo.
- El backend NO cambia de contrato: sigue recibiendo `device_hash` (máx 128 chars) en los mismos endpoints. Solo se añade en el payload `device_info` (modelo, marca, versión Android) para que el Monitor de Fraude muestre qué equipo es cada hash.

### 2. GPS con permisos nativos
- Plugin `@capacitor/geolocation` con `ACCESS_FINE_LOCATION` + `ACCESS_BACKGROUND_LOCATION` declarados en el manifest y solicitados en el onboarding de la app (pantalla explicativa → ajustes del sistema, flujo "Allow all the time" que Android exige en dos pasos).
- Al marcar asistencia: `getCurrentPosition` con `enableHighAccuracy`, timeout 15s, y reintento con última posición conocida si el fix tarda. Se elimina el flujo "fallback sin GPS" dentro de la app (en web se mantiene).
- Mock location: la app detecta `isFromMockProvider` en la posición y lo reporta en el payload (`mock_gps: true`) — el backend lo marca como incidencia de fraude en vez de aceptar la marca.

### 3. Ping de presencia cada 30 minutos
- **Ventana**: SOLO durante el turno — arranca al marcar ENTRADA, se detiene al marcar SALIDA (o a las N horas máximas de turno como corte de seguridad). Fuera de turno la app no rastrea — importante para batería y para el aspecto legal (ver abajo).
- **Mecanismo Android**: Foreground Service con notificación persistente ("Turno activo — Mundo Android") + `setExactAndAllowWhileIdle`/WorkManager como respaldo. El foreground service es lo que garantiza los 30 min exactos aunque el equipo duerma (Doze).
- **Payload del ping**: `POST /v1/attendance/ping-ubicacion` → `{device_hash, lat, lng, accuracy, mock_gps, battery_pct, timestamp}`. Autenticado con un token de terminal emitido al autorizar el dispositivo (no requiere sesión de usuario).
- **Backend evalúa**: distancia vs geocerca de la tienda del agente en turno. Estados: `ok` (dentro), `fuera_de_rango` (fuera del radio), `sin_señal` (ping esperado que no llegó — se detecta con un job que barre turnos activos sin ping en >45 min).
- **Persistencia offline**: si no hay red, el ping se encola en SQLite local y se reenvía en lote al recuperar conexión (con su timestamp original — el backend distingue "llegó tarde" de "se tomó tarde").

### 4. Backend nuevo (Laravel)
- Migración (ajustada por DECISIÓN-APP-03): `asistencia_presencia` (una fila por agente en turno, upsert del último ping: `agente_id UNIQUE, tienda_id, device_hash, lat, lng, accuracy, estado, battery_pct, capturado_en, recibido_en`) + `asistencia_incidencias_ubicacion` (solo eventos `fuera_de_rango|mock_gps|sin_señal`: mismos campos + `id` autoincremental, sin upsert).
- `POST /v1/attendance/ping-ubicacion` (throttle + token de terminal) y `GET /v1/asistencias-admin/presencia` (admin: mapa/lista de agentes en turno con último ping, semáforo verde/ámbar/rojo).
- Job programado cada 15 min: detecta turnos activos sin ping reciente → incidencia `sin_señal`.
- Alertas: incidencias `fuera_de_rango` repetidas (2+ consecutivas) y `mock_gps` van al Monitor de Fraude existente.
- Panel admin: nueva pestaña "Presencia" en Asistencias (tabla de agentes en turno: último ping, distancia a tienda, batería, dispositivo) — diseño alineado a la visión fintech del plan 10 (estados semánticos, sin dorado salvo nada monetario aquí).

### 5. Privacidad / laboral (no opcional)
- El rastreo ocurre **solo en horario de turno marcado** y la notificación persistente lo hace visible al agente en todo momento — sin rastreo oculto.
- Pantalla de consentimiento en el primer uso de la app (texto corto: qué se recolecta, cuándo, para qué) + registro del consentimiento en BD (`agente_id, fecha, versión_texto`).
- Los pings se retienen N días (propuesta: 90) y luego se purgan con un job — no es un historial de movimientos de por vida.

## Tickets (APP-01 … APP-10)

| ID | Título | Alcance | Esfuerzo | Modelo |
|---|---|---|---|---|
| APP-01 | Scaffold Capacitor + build Android del frontend actual | `frontend/` + carpeta `android/`; la SPA corre como app sin cambios funcionales | Medio | Sonnet 5 |
| APP-02 | Plugin de huella real (ANDROID_ID + Keystore + device_info) | Plugin Capacitor propio (Java/Kotlin) + integración en TerminalAsistenciaPage (si corre nativo usa huella real; si corre web, localStorage como hoy) | Alto | Opus 4.8 |
| APP-03 | Geolocalización nativa + detección de mock + onboarding de permisos | `@capacitor/geolocation`, pantalla de permisos, quitar fallback sin-GPS en nativo | Medio | Sonnet 5 |
| APP-04 | Migración `asistencia_ubicaciones` + endpoint ping + token de terminal | Backend puro, tests Feature | Medio | Sonnet 5 |
| APP-05 | Foreground service de pings cada 30 min (inicio/fin por turno) | Plugin/servicio Android + cola offline SQLite | Alto | Opus 4.8 |
| APP-06 | Job `sin_señal` + integración con Monitor de Fraude (fuera_de_rango, mock) | Backend: scheduler + incidencias | Medio | Sonnet 5 |
| APP-07 | Pestaña "Presencia" en Asistencias (admin) | Frontend web: tabla semáforo + detalle por agente | Medio | Sonnet 5 |
| APP-08 | Consentimiento + retención/purga de pings (90 días) | Backend + pantalla en app | Bajo | Sonnet 5 |
| APP-09a | Canal de distribución del APK: `/v1/app-terminal/version` + descarga pública estable + botón "Descargar app" en la pestaña Asistencias del admin (refactor) con enlace copiable para WhatsApp | `refactorizado_bitel` — backend + frontend admin | Bajo-Medio | Sonnet 5 |
| APP-09b | Mismo botón "Descargar app" en `gerencia/panel_asistencias.php` del **legacy** (`E:\laragon\www\sistema-rolando-salas`, en producción) — el gerente pide la app desde el mismo panel donde hoy administra asistencias, sin tener que cambiar de sistema. Sirve el APK desde el mismo endpoint del refactor (`/v1/app-terminal/version` de APP-09a) para no duplicar el canal de distribución; el legacy solo necesita el link/botón. | `sistema-rolando-salas` (legacy, PHP, **producción viva** — cambio pequeño pero se coordina con el usuario antes de tocar el sistema en vivo) | Bajo | Sonnet 5 |
| APP-10 | QA en dispositivo real: matriz de pruebas (Doze, sin red, GPS falso, reinicio, batería) | Manual con el equipo de una tienda piloto | Medio | Manual + Opus verifica |

**Dependencias**: 01 → (02, 03) → 05; 04 → (05, 06, 07); 08/09 tras 04. Piloto (10) al final con UNA tienda antes de repartir el APK a todas.

## Decisiones CONFIRMADAS por el usuario (2026-07-11)

1. **DECISIÓN-APP-01 — Ubicación exacta, notificación mínima silenciosa.** Aclaración técnica: Android exige UNA notificación fija mientras un foreground service corre — no es un aviso que "salta a cada rato", es una línea estática y silenciosa en la bandeja (como WhatsApp Web o un reproductor de música: sin sonido, sin banner, sin vibración). Se implementa con canal `IMPORTANCE_MIN`: colapsada, muda, solo visible si el agente despliega la bandeja. Eliminar la notificación por completo obligaría a WorkManager, y ahí Android puede estirar los 30 min a 45-60 en Doze — se perdería la exactitud pedida. **Resolución: foreground service con notificación mínima silenciosa** ("Turno activo") — exactitud garantizada y cero molestia práctica. De paso mantiene la transparencia legal del rastreo.
2. **DECISIÓN-APP-02 — La app REEMPLAZA al terminal web.** Cuando la app esté desplegada en todas las tiendas, la ruta web `/terminal-asistencia` se retira (o queda detrás de un flag solo-admin para emergencias). El ticket APP-01 incluye este flag; el retiro definitivo se hace al cerrar el piloto (APP-10).
3. **DECISIÓN-APP-03 — Retención mínima, no historial.** El razonamiento del usuario es correcto: no interesa un historial de movimientos. Los pings sirven para DOS cosas puntuales: (a) el semáforo de presencia en vivo del admin, y (b) la evidencia cuando hay una incidencia (agente fuera de tienda / GPS falso / sin señal), p.ej. ante un reclamo de descuento. **Resolución: se guarda solo el ÚLTIMO ping por agente (upsert, se sobreescribe) + las incidencias** (`fuera_de_rango`, `mock_gps`, `sin_señal`, que sí persisten porque son el motivo del monitoreo). Los pings `ok` intermedios no se acumulan. La tabla pasa de historial a estado: `asistencia_presencia` (una fila por agente en turno) + `asistencia_incidencias_ubicacion` (solo eventos). APP-08 se simplifica: ya no hay job de purga de 90 días, solo limpieza de la fila de presencia al cerrar turno.

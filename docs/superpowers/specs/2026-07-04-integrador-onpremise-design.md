# Módulo 7 — Integrador / Agente on-premise: diseño y GAP

Fecha: 2026-07-04 · Rama: `m7-integrador` · Autor: Claude (orquesta)
Fuente legacy: `E:\laragon\www\sis_bipay` · Destino: este worktree (`bitel-p0-5`, Laravel 12).

Este es el sub-proyecto que el GAP_ANALYSIS_MAESTRO dejó **para el final**: el agente
extractor que corre on-premise en las PC de las tiendas (donde está MSuite/Bitel) y
sincroniza saldo + movimientos + morosidad contra el servidor central. Es un sistema
**máquina-a-máquina (M2M)**, sin sesión de usuario.

---

## 1. Flujo legacy completo (end-to-end)

### 1.1 Arquitectura (README `bitel_bipay_integrador_completo/README.md:1-25`)

```
PC de la tienda (MSuite ON)              Servidor Central (ERP)
┌─────────────────────────┐              ┌──────────────────────────┐
│ lanzador.php            │  POST token  │ api/agente_codigo.php    │  ← entrega código
│  ├─ pide su código      │───────────►  │  (ofuscado, base64)      │     del agente
│  ├─ eval() en memoria   │◄─────────────│                          │
│  ├─ BitelBipayClient    │              │ api/agente_config.php    │  ← entrega credencial
│  │   (scrapea CAS/SM)   │  POST token  │  Bitel (username+pass)   │     Bitel descifrada
│  └─ agente_bipay        │───────────►  │                          │
│      envía saldo+movs   │  POST JSON   │ api/recibir_saldo.php    │  ← ingesta principal
└─────────────────────────┘  (api_key)   │ api/recibir_morosidad.php│
        │ túnel MSuite                    │ api/recibir_bitel_hist.  │
        ▼                                 └──────────────────────────┘
  Bitel Passport (CAS SSO) 10.121.7.24:8666
  SM Report                10.121.4.7:8002  → export .xls Bipay
```

### 1.2 Autenticación del agente contra Bitel (scraping, corre on-premise)

`README.md:27-63` documenta el flujo CAS descubierto: GET login → obtiene `lt` token →
POST credenciales → 302 con `ticket` CAS → GET SM con ticket → `struts.token` → POST
`exportReport.do` → HTML con link → GET del `.xls`. Esto lo hace `BitelBipayClient.php`
**en la PC de la tienda**. No es código de servidor; el servidor nunca habla con Bitel.

### 1.3 Entrega y ejecución del agente (el corazón del "descargar_agente")

Dos piezas legacy, **ambas máquina-a-máquina por token de tienda**:

- **`api/descargar_agente.php`** (POST, sesión admin/tienda): regenera el token de la
  tienda, y llama a `build_paquete_agente()` (`api/_build_paquete_agente.php:26-96`). El
  ZIP resultante contiene **solo**:
  - `lanzador.php` **ofuscado** (`php_strip_whitespace`) con marca de agua por descarga,
    y con `__AGENTE_TOKEN__`/`__SERVIDOR_CENTRAL__`/`__API_KEY_CENTRAL__` inyectados
    (`_build_paquete_agente.php:33-42`).
  - `ejecutar_agente.bat`, `instalar_tarea.bat` (schtasks cada 5 min como SYSTEM),
    `desinstalar_tarea.bat`, `LEEME.txt`.
  - **NO** contiene el código del agente (`agente_bipay.php`, `BitelBipayClient.php`).

- **`api/agente_codigo.php`** (POST token, sin sesión): el `lanzador.php` instalado, en
  **cada corrida**, hace POST de su token a este endpoint y recibe el código del agente
  **ofuscado + base64** (`construir_codigo_agente()` en `_build_paquete_agente.php:17-24`),
  que evalúa en memoria con `eval()` (`lanzador.php:51-52`). Efecto de seguridad: el
  código del scraper **nunca queda en disco en claro** en la PC de la tienda; cada
  descarga invalida el token anterior; cada corrida re-descarga con marca de agua.

`lanzador.php:9-12` además toma un lock (`flock`) para no solaparse entre corridas.

### 1.4 Ingesta en el servidor central

- **`api/agente_config.php:1-55`**: valida token (sha256 en `integrador_credenciales`,
  `activo=1`), descifra `bitel_password_enc` con `integrador_decrypt()`
  (`config/integrador_crypto.php`), actualiza `last_config_fetch=NOW()`, devuelve
  `bitel_username`, `bitel_password` (claro), `channel_code`, `channel_type`,
  `sync_intervalo_min`.
- **`api/recibir_saldo.php:53-59`**: valida **API key global compartida**
  (`API_KEY_VALIDA = 'KyrO+…vegetta777'`) + `timestamp` ±300 s. Auto-registra cuenta
  Bipay/tienda, agrupa movimientos por tienda+fecha+categoría, upsert
  `bitel_movimientos_diarios`, actualiza saldo en `cuentas_bipay`, historial en
  `transacciones_bipay` (`tipo_operacion='SYNC_AUTO'`), alerta de umbral.
- **`api/recibir_morosidad.php`**, **`api/recibir_bitel_historico.php`**: cierran colas
  `solicitudes_extraccion` / `bitel_historico_queue`, upsert `clientes_estado` /
  `lineas_morosidad` / `bitel_operaciones_detalle`.
- **`gerencia/configuracion_integrador.php:17-59`**: panel admin. Escribe solo
  `bitel_username`, `bitel_password_enc`, `sync_intervalo_min`, toggle `activo`,
  regenera token. **NO** setea `channel_type` (queda en default `'1'`).

> **Nota de las dos versiones de `recibir_saldo.php`**: la ACTIVA
> (`api/recibir_saldo.php:53`) usa **API key global**. La empaquetada en
> `bitel_bipay_integrador_completo/servidor/recibir_saldo.php:60-79` es un diseño
> alternativo (NO desplegado) que autentica con el **token por tienda**
> (`integrador_credenciales.agente_token_hash`), "así filtrar un agente no compromete al
> resto". El refactor portó la ACTIVA (API key global).

---

## 2. Estado actual del refactor

Controlador único: `backend/app/Http/Controllers/Api/IntegradorController.php`.
Rutas: `backend/routes/api.php:80-85` (M2M) y `:330-337` (admin, `auth:sanctum`).
Las 14 tablas viven en `2026_07_02_000001_create_integrador_bitel_tables.php` (ya corrida
en producción). Config M2M: `config/services.php:40` (`INTEGRADOR_API_KEY`).

| Pieza legacy | Destino refactor | Estado |
|---|---|---|
| `agente_config.php` | `IntegradorController::agenteConfig` (`:29`) | ✅ paridad (cifrado migró a `Crypt`/APP_KEY — mejora) |
| `recibir_saldo.php` | `recibirSaldo` (`:65`) | ✅ paridad fiel (API key global, `validarM2M` `:522`) |
| `recibir_morosidad.php` | `recibirMorosidad` (`:201`) | ✅ paridad |
| `recibir_bitel_historico.php` | `recibirBitelHistorico` (`:237`) | ✅ paridad |
| `solicitar_extraccion.php` | `solicitarExtraccion` (`:465`) | ✅ paridad |
| `solicitar_bitel_historico.php` | `solicitarBitelHistorico` (`:483`) | ✅ paridad |
| `gerencia/configuracion_integrador.php` | `credenciales`/`guardarCredenciales`/`regenerarToken`/`toggleActivo` (`:276-381`) | ✅ superset (misma escritura: username/pass/intervalo/activo/token) |
| `api/descargar_agente.php` + `_build_paquete_agente.php` | `descargarAgente` (`:388`) | ⚠️ **arquitectura distinta** (ver GAP) |
| `api/agente_codigo.php` (entrega código en runtime) | — | ❌ **no existe** |
| `lanzador.php` (fetch+eval en runtime, ofuscación, watermark) | — | ❌ **no existe** (modelo abandonado) |
| Binarios del agente (`agente_bipay.php`, `BitelBipayClient.php`) | `storage/app/integrador/agente/` | ❌ **directorio ausente** → el ZIP sale incompleto |

Cobertura de tests: **0**. Los endpoints M2M usan SQL crudo MySQL
(`ON DUPLICATE KEY UPDATE`, `INSERT IGNORE`) que **no corre en sqlite** (harness de test).
`cuentas_bipay`/`transacciones_bipay` ni siquiera se crean por migración (los tests que
las necesitan las crean ad-hoc, ej. `BipayAdminTest::setUp`).

---

## 3. GAP preciso

El módulo está **~95% portado y fiel**. El GAP real se concentra en la **entrega y
ejecución del agente** (el "descargar_agente"), que el refactor rediseñó:

### 3.1 Arquitectura de entrega divergente (⚠️ decisión de producto)

| Aspecto | Legacy | Refactor `descargarAgente` |
|---|---|---|
| Contenido del ZIP | `lanzador.php` ofuscado + bats | `agente_bipay.php` + `BitelBipayClient.php` **estáticos en claro** + `config.php` + bats |
| Código del agente en disco de la tienda | Nunca (se re-descarga y `eval()` en RAM) | **Sí, en claro** |
| Token en disco de la tienda | No (solo en el lanzador ofuscado) | **Sí, en claro en `config.php`** |
| Ofuscación / marca de agua | Sí (`php_strip_whitespace` + `$__wm`) | No |
| Endpoint `agente_codigo` (runtime fetch) | Sí | No |
| Credencial Bitel | La descarga el agente vía `agente-config` | Igual (config.php dice "no viven aquí") ✅ |

El docblock del refactor (`descargarAgente` `:383-387`) llama a su modelo "mejoras de
seguridad (token de un solo uso, ZIP on-the-fly)", pero en la práctica el modelo legacy
es **más duro** (código + token nunca en claro en la PC de la tienda). **Cuál modelo
adoptar es una decisión de producto/seguridad de David**, no inferible del código.

### 3.2 Binarios del agente ausentes (⚠️ defecto + decisión)

`descargarAgente` `:442-447` lee de `storage/app/integrador/agente/` y agrega los archivos
**solo si existen** (`is_file`). Ese directorio **no existe** en el repo → hoy el endpoint
produce un ZIP con `config.php` + bats pero **sin el agente** → instalador **no funcional**,
y lo hace **en silencio** (sin error). Provisionar los binarios implica decidir si se
versiona el scraper Bitel tal cual (depende del HTML vivo de Bitel, que puede cambiar) o
si se adopta el modelo lanzador/`agente-codigo`.

### 3.3 Notas de fidelidad menores (NO tocar sin verificación de producto/BD)

- `transacciones_bipay.tipo_operacion`: legacy `'SYNC_AUTO'` vs refactor `'AJUSTE'`
  (`:146`). El refactor conflaciona el sync automático con un ajuste manual. **No se
  cambia** porque la tabla `transacciones_bipay` no está migrada en este repo y su
  columna podría ser un `enum` sin `SYNC_AUTO` en producción → cambiarlo podría romper el
  INSERT. Verificar el enum real en la BD de producción antes de decidir.
- Auth de `recibir_saldo`: API key global (portada) vs token-por-tienda (versión
  empaquetada, no desplegada). Endurecer a token-por-tienda es una mejora de seguridad
  legítima pero es **cambio de contrato con los agentes ya desplegados** → decisión.
- `channel_type` no es configurable en el admin (ni en legacy ni en refactor); queda en
  `'1'`. Sin gap.

### 3.4 Defecto inequívoco y aislado (SÍ se implementa)

`descargarAgente` entrega en **silencio** un instalador roto cuando faltan los binarios
(§3.2). Independiente de qué modelo de entrega se elija, **un ZIP sin el agente nunca es
un resultado válido**: debe fallar ruidosamente para que el operador sepa que el servidor
no está provisionado, en vez de repartir instaladores inertes. Esto es portable
(query-builder + filesystem) y **testeable en el harness sqlite**.

---

## 4. Plan de implementación por piezas

1. **[INEQUÍVOCO — se implementa ahora, TDD]** `descargarAgente`: validar que los
   binarios requeridos del agente (`agente_bipay.php`, `BitelBipayClient.php`) existan en
   `storage/app/integrador/agente/` **antes** de armar el ZIP; si falta alguno, responder
   `503` con un error claro ("binarios del agente no provisionados") en lugar de generar
   un paquete no funcional. Tests: (a) faltan binarios → 503; (b) con binarios presentes →
   200 + descarga; (c) token inválido → 403; (d) tienda ajena → 403.

2. **[PENDIENTE — decisión de producto]** Modelo de entrega del agente (§3.1): mantener
   bundle estático vs. restaurar lanzador+`agente-codigo`+ofuscación. Si se elige el
   segundo, implementar `POST /v1/integrador/agente-codigo` (M2M por token, devuelve el
   código base64) y regenerar `descargarAgente` para empaquetar el lanzador.

3. **[PENDIENTE — decisión/deploy]** Provisionar los binarios del agente (§3.2) en
   `storage/app/integrador/agente/` o vía el modelo del punto 2.

4. **[PENDIENTE — verificación de BD]** `tipo_operacion` `SYNC_AUTO` vs `AJUSTE` (§3.3).

5. **[PENDIENTE — decisión de seguridad]** Auth por token-por-tienda en `recibir_saldo`
   (§3.3). No relajar; solo endurecer si David lo aprueba (rompe agentes desplegados).

6. **[DEUDA TÉCNICA — recomendado]** `integrador_credenciales.last_sync_at` se **lee** en
   el panel admin (`credenciales` `:285`) pero **nunca se escribe** → campo siempre vacío.
   Escribirlo en `recibirSaldo` al sincronizar con éxito (portable:
   `->where('tienda_codigo',$cod)->update(['last_sync_at'=>now()])`). No se implementa en
   esta tanda porque vive dentro del método M2M **no testeable en sqlite** (SQL crudo
   MySQL upstream) y "los tests son la única red" para código M2M. Requiere primero una
   suite de tests contra MySQL (o portar los `ON DUPLICATE KEY` a `upsert()`/
   `insertOrIgnore()` de Laravel, cambio grande sobre el endpoint más crítico → fuera de
   alcance de "lo inequívoco").

---

## 5. DECISIONES DE PRODUCTO PENDIENTES (para David)

1. **Modelo de entrega del agente**: ¿bundle estático (actual, más simple, código y token
   en claro en la PC de la tienda) o restaurar el modelo legacy lanzador +
   `agente-codigo` + ofuscación + marca de agua (código/token nunca en claro)? De esto
   depende si se implementa el endpoint `agente-codigo` y se rehace `descargarAgente`.
2. **Provisión de los binarios del agente**: ¿se versiona el scraper Bitel
   (`agente_bipay.php`, `BitelBipayClient.php`) tal cual del legacy en
   `storage/app/integrador/agente/`? Depende de si el HTML/flujo CAS de Bitel sigue
   vigente (el README avisa que el HTML puede cambiar y romper los regex).
3. **Auth de `recibir_saldo`**: ¿mantener la API key global compartida (paridad con el
   legacy activo) o endurecer a token-por-tienda (versión empaquetada)? Endurecer rompe a
   los agentes ya desplegados hasta re-descarga.
4. **`tipo_operacion` del sync**: confirmar el `enum` real de `transacciones_bipay` en
   producción para decidir si el historial de sync debe etiquetarse `SYNC_AUTO` (fidelidad
   legacy) en vez de `AJUSTE` (actual).
5. **Suite de tests M2M**: los endpoints M2M no son testeables en el harness sqlite. ¿Se
   añade una conexión MySQL de test o se portan los `ON DUPLICATE KEY UPDATE`/`INSERT
   IGNORE` a los helpers portables de Laravel (`upsert`/`insertOrIgnore`) para poder
   cubrirlos con TDD y desbloquear el punto 6 del §4 (`last_sync_at`)?
</content>
</invoke>

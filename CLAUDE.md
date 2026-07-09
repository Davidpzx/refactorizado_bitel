# Proyecto: SIS-KYRO-REFACTOR (Implementación)

## Propósito de esta ruta
Esta es la ruta de **implementación**. Aquí Codex escribe el código.
Es el refactor del sistema legacy Vitaltel/DASAM a Laravel 11 + React 18.

## Stack tecnológico
- **Backend**: Laravel 12, PHP 8.2, XAMPP
- **Frontend**: React 19 + TypeScript + Vite
- **BD**: MySQL (migración desde legacy)
- **Infra**: Docker (docker-compose.yml / docker-compose.prod.yml)

## Estructura

```
backend/     → Laravel 11 (API REST)
frontend/    → React 18 + TypeScript + Vite
```

## Rol de cada agente en esta ruta

```
Gemini (analiza legacy en C:\xampp\htdocs\refactorizacion) → Claude (plan) → Codex (implementa aquí)
```

- **Gemini**: NO actúa aquí directamente — analiza el legacy en la ruta de análisis
- **Claude**: Define qué archivo, qué línea, qué cambio exacto hacer
- **Codex**: Escribe y edita archivos en esta ruta según el plan de Claude

## Claude NO implementa directamente
Excepto:
- Cambios de 1-2 líneas triviales
- Usuario pide explícitamente "hazlo tú"
- Codex no está disponible

## Rutas API existentes (17 rutas)
Ver `routes/api.php` en backend para el listado completo.

## Modelos principales
`Agente` | `Usuario` | `Cliente` | `Venta` | `VentaItem` | `Comprobante`

## Referencia del legacy
- Código fuente: `C:\xampp\htdocs\refactorizacion\`
- GAP Analysis: `C:\xampp\htdocs\refactorizacion\GAP_ANALYSIS.md`

---

## Orquesta de 4 agentes (modelo vigente — supersede el flujo de 3 de arriba)

David (usuario) es la cabeza. **Claude orquesta**: planifica, reparte, verifica e integra.
Para **no agotar los tokens de Claude**, el trabajo pesado se delega a los otros 3 vía sus MCPs.

| Agente | MCP / cómo invocar | Rol | Estado |
|--------|--------------------|-----|--------|
| **Claude** (yo) | — | Cabeza técnica: plan, verificación, integración, git, decisiones finales | activo |
| **Codex** | `mcp__codex-cli__codex` (model `gpt-5.5`, `sandbox: danger-full-access`, workingDirectory absoluto) | Implementación pesada de features | ✔ conectado |
| **Gemini** | `mcp__gemini-cli__ask-gemini` / `brainstorm` | Leer/analizar mucho código, búsquedas amplias, brainstorm | ✔ conectado |
| **Antigravity** (`agy`) | CLI `C:\Users\Usuario\AppData\Local\agy\bin\agy.exe` (ya en User PATH; full access = flag `--dangerously-skip-permissions`). **NO es MCP** — no tiene modo servidor (`agy help mcp` → unknown subcommand). | 4º agente: lo maneja David en el IDE/terminal Antigravity; Claude le pasa el prompt | manual (interactivo) |

**Importante sobre `agy`:** es una CLI agéntica interactiva (como Claude Code). `agy -p` (headless) **cuelga al pipearse**, así que Claude NO puede scriptearlo de forma confiable. La orquesta lo usa con humano en el loop: David ejecuta `agy` y pega el prompt que Claude prepara.

**Modelos de Antigravity** (menú "Switch Model" en `agy`). Como el agente Gemini-MCP ya cubre Gemini, en `agy` usar **los otros**: Claude Sonnet 4.6 (`claude-sonnet-4-6`) o Gemini 2.5 Flash; evitar Claude Opus 4.6.
- **Recomendado por defecto:** `Claude Sonnet 4.6 (Thinking)` para implementación de calidad.
- **Rápido/barato:** `Gemini 2.5 Flash`.

**Regla de delegación (ahorro de tokens de Claude):**
- Análisis/lectura de muchos archivos → **Gemini** (MCP `gemini-cli`)
- Implementación de features completas → **Codex** (MCP `codex-cli`; verificar diff + build después; tiende a exceder scope)
- Tareas en el IDE Antigravity / segunda opinión → **agy** (David lo maneja, modelo Sonnet 4.6 o Flash)
- Claude no implementa en bloque salvo cambios triviales o si los MCPs fallan.

### Calibración de modelos por tarea — OBLIGATORIO antes de delegar

Ambos MCPs aceptan override de modelo por llamada. Claude DEBE elegir según complejidad:

#### Codex (`mcp__codex-cli__codex`)

| Complejidad | `model` | `reasoningEffort` | `sandbox` | Cuándo usar |
|-------------|---------|-------------------|-----------|-------------|
| **Simple** | `gpt-4o` | `low` | `workspace-write` | 1 archivo, bug fix puntual, rename, snippet |
| **Media** | `gpt-5.3-codex` | `medium` | `workspace-write` | Feature de 2-4 archivos, CRUD completo, migración simple |
| **Compleja** | `gpt-5.5` | `high` | `danger-full-access` | Feature multi-módulo, refactor grande, integración de sistemas |
| **Máxima** | `gpt-5.5` | `xhigh` | `danger-full-access` | Solo si `high` falló o la tarea es crítica/seguridad |

> `o4-mini` es alternativa económica a `gpt-5.5` para razonamiento lógico sin escritura masiva de código.

#### Gemini (`mcp__gemini-cli__ask-gemini`)

Gemini se usa poco. Cuando se usa, casi siempre el default (`gemini-2.5-pro`) es el correcto — es el modelo más capaz disponible (lo que `agy` llama "Gemini 3.1 Pro Preview" es el mismo modelo internamente).

| Caso | `model` | Cuándo usar |
|------|---------|-------------|
| **Casi siempre** | *(omitir → `gemini-2.5-pro`)* | Análisis de código, arquitectura, brainstorm, lectura amplia |
| **Consulta trivial** | `gemini-2.5-flash` | Solo si la respuesta esperada es 1-2 líneas y no requiere razonamiento |

> Default sin `model` = `gemini-2.5-pro`. No pasar Flash salvo que sea una búsqueda de 1 dato puntual.

## Handoff de sesión — 2026-06-14 (9 gaps de paridad)

Commit `10a00f5` en `main` cierra 9 gaps Tier 1+2. Detalle completo: `docs/comparacion/GAPS_PENDIENTES_v2.md`.
- **Hecho (backend+frontend, lint+tsc limpios):** T1.1 chips, T1.4 adelantos, T2.1 excepciones asistencia, T2.2 CRUD cuentas Bipay, T2.3 fijar precio agente, T2.4 ranking por categoría.
- **Parcial (backend listo, falta UI):** T1.2 panel "jefe de tienda"; T1.3 editores de rangos (PLAN/EQUIPO, bipay/krece/payjoy); T2.5 boletas/ficha RRHH en VerAgente.
- **PENDIENTE en VPS:** correr `php artisan migrate` en el contenedor backend para las 4 migraciones nuevas (`ventas.chips_descontados`, `adelantos`, `config_comisiones`, `comisiones_rangos`). Las pruebas se hacen en el VPS, no en local.

---

## Delegación multi-cuenta Claude (orquesta de cuotas)

Hay **5 cuentas Claude**, cada una en su propio `CLAUDE_CONFIG_DIR` (perfil aislado, **cuota independiente**). Este bloque está a nivel de proyecto, así que **cualquier cuenta que abra este repo lo lee** y sabe delegar.

| Perfil (`CLAUDE_CONFIG_DIR`) | Correo | Rol |
|---|---|---|
| `C:\Users\Usuario\.claude` | david365dgxd@gmail.com | orquestador default (modelo libre) |
| `C:\Users\Usuario\.claude-titan` | nashelitls@gmail.com | orquestador / cerebro |
| `C:\Users\Usuario\.claude-dev1` | comomellamotunosabes@gmail.com | worker (sonnet) |
| `C:\Users\Usuario\.claude-dev2` | tutorialesdavid3@gmail.com | worker (sonnet) |
| `C:\Users\Usuario\.claude-dev3` | joan.achenquipa@gmail.com | worker (sonnet) |

**Cuándo delegar:** cuando la cuenta actual se acerca a su límite, o para paralelizar subtareas. Cada llamada gasta la cuota de la cuenta **destino**, no la del orquestador.

**Comando (una línea, vía Bash tool):**
```
CLAUDE_CONFIG_DIR="C:/Users/Usuario/.claude-dev1" claude -p "<tarea autocontenida>" --model sonnet
```
- El **directorio actual** define el proyecto. Para otro repo: `cd <ruta> && CLAUDE_CONFIG_DIR=... claude -p "..."`.
- **Solo lectura/análisis:** basta lo de arriba.
- **Si va a editar archivos:** añadir `--dangerously-skip-permissions` (o `--permission-mode acceptEdits`), si no se cuelga pidiendo permiso.

**Reglas de la orquesta de cuotas:**
1. Cada `claude -p` arranca en **frío** (sin memoria del chat) → el prompt debe ser autocontenido: qué archivo, qué cambio, qué criterio de aceptación.
2. Reparte a cuentas **frescas** (dev1/2/3) las subtareas pesadas; el orquestador (titan/david) **verifica e integra**.
3. Recoge el `stdout` de cada worker y revisa el diff/resultado antes de dar la tarea por buena.
4. Los workers **no** necesitan conocer esta orquesta — solo ejecutan el prompt recibido.

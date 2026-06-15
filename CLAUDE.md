# Proyecto: SIS-KYRO-REFACTOR (Implementación)

## Propósito de esta ruta
Esta es la ruta de **implementación**. Aquí Codex escribe el código.
Es el refactor del sistema legacy Vitaltel/DASAM a Laravel 11 + React 18.

## Stack tecnológico
- **Backend**: Laravel 11, PHP 8.2, XAMPP
- **Frontend**: React 18 + TypeScript + Vite
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

**Modelos de Antigravity** (menú "Switch Model" en `agy`). Como el agente Gemini-MCP ya cubre Gemini, en `agy` usar **los otros**: Claude Sonnet 4.6 (normal) o Gemini 3.5 Flash; evitar Gemini 3.1 Pro (redundante) y Claude Opus 4.6 (premium / = Claude-orquestador).
- **Recomendado por defecto:** `Claude Sonnet 4.6 (Thinking)` (id `claude-sonnet-4-6`) para implementación de calidad.
- **Rápido/barato:** `Gemini 3.5 Flash (High/Medium/Low)`.
- Otros disponibles: `Gemini 3.1 Pro (Low/High)`, `Claude Opus 4.6 (Thinking)` (`claude-opus-4-6`), `GPT-OSS 120B (Medium)`.
- Seleccionar con el menú "Switch Model" dentro de `agy`.

**Regla de delegación (ahorro de tokens de Claude):**
- Análisis/lectura de muchos archivos → **Gemini** (MCP `gemini-cli`)
- Implementación de features completas → **Codex** (MCP `codex-cli`; verificar diff + build después; tiende a exceder scope)
- Tareas en el IDE Antigravity / segunda opinión → **agy** (David lo maneja, modelo Sonnet 4.6 o Flash)
- Claude no implementa en bloque salvo cambios triviales o si los MCPs fallan.

## Handoff de sesión — 2026-06-14 (9 gaps de paridad)

Commit `10a00f5` en `main` cierra 9 gaps Tier 1+2. Detalle completo: `docs/comparacion/GAPS_PENDIENTES_v2.md`.
- **Hecho (backend+frontend, lint+tsc limpios):** T1.1 chips, T1.4 adelantos, T2.1 excepciones asistencia, T2.2 CRUD cuentas Bipay, T2.3 fijar precio agente, T2.4 ranking por categoría.
- **Parcial (backend listo, falta UI):** T1.2 panel "jefe de tienda"; T1.3 editores de rangos (PLAN/EQUIPO, bipay/krece/payjoy); T2.5 boletas/ficha RRHH en VerAgente.
- **PENDIENTE en VPS:** correr `php artisan migrate` en el contenedor backend para las 4 migraciones nuevas (`ventas.chips_descontados`, `adelantos`, `config_comisiones`, `comisiones_rangos`). Las pruebas se hacen en el VPS, no en local.

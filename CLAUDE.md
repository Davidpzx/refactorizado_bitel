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

# TICKET-004 — `ReporteDetalleNormalizer` único + tests (transversal)

- **Modelo asignado:** Opus 4.8
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada. Si el presupuesto no alcanza, pedir subdivisión ANTES de empezar.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`

## Contexto
El JSON `detalle` heredado de `reporte_categorias` puede ser objeto `{}` o array `[{}]` — regla del legacy: **siempre normalizar al leer**. En el refactor las consultas JSON siguen vivas en varios puntos (reportes, ranking, comisiones, financieras, exports) junto a tablas normalizadas (`ventas`, `venta_lineas`, `venta_equipos`). Riesgo señalado por el informe de arquitectura: parsing disperso con `isset($detalle[0])` copiado, bugs silenciosos cuando el root alterna forma.

## Alcance
1. Crear `app/Services/ReporteDetalleNormalizer.php` con la regla obligatoria: decode → si es objeto envolver como array de 1 → validar keys → operar como array → al guardar preservar la forma original solo si la compatibilidad lo exige.
2. Buscar TODOS los puntos del backend que parsean `detalle` u `otros_flujo` (`grep` por `detalle`, `json_decode`, `->detalle`) y migrarlos al normalizador. Listar los archivos tocados en el PR.
3. Tests unitarios: objeto único, array múltiple, JSON inválido, campos faltantes, `otros_flujo` `{monto, motivo, comision_agente}`.
4. Para features nuevas, dejar comentario-guía en el service: preferir las tablas normalizadas `ventas/*` sobre el JSON.

## Criterio de aceptación
Cero parsing ad-hoc de `detalle` fuera del normalizador (verificable por grep); tests verdes; los ~66 tests Feature existentes siguen verdes.

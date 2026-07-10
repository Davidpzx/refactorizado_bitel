# TICKET-042 — Exportes PDF/Excel: verificar y reparar contra datos reales

- **Modelo asignado:** Sonnet 5
- **Skills obligatorias:** headroom, superpowers
- **Regla 0.3:** completar el ticket ENTERO en una sola pasada.
- **Repos:** refactor `C:\xampp\htdocs\refactorizado_bitel` · legacy `E:\laragon\www\sistema-rolando-salas`
- **Origen:** feedback del usuario en producción (2026-07-09): "los pdf o excel no tienen nada, creo no carga".

## Contexto
El usuario probó exportes en app.kyrocodelabs.cloud y salieron vacíos o no cargaron. Puede ser (a) bug real de los endpoints de export, (b) filtros que apuntan al día actual sin datos (el dashboard mostraba S/0.00 el 10/07 sin reportes), o (c) problema de streaming/headers en producción. Hay que DISTINGUIR cuál es.

## Alcance
1. Inventariar TODOS los exportes del refactor (grep exportar/export/pdf/excel en rutas y frontend): Dashboard exportar, Historial exportar, Bitácora, CRM excel, Agentes PDF, constancias, planilla, etc.
2. Para cada uno: test Feature con datos sembrados que verifique que el archivo devuelto TIENE contenido real (filas > 0, headers correctos, content-type correcto) — no solo HTTP 200.
3. Reproducir el caso del usuario: export con filtros sin datos → debe devolver un archivo válido con mensaje/estructura vacía clara O un aviso en UI ("no hay datos en el rango"), no un archivo roto ni una descarga muerta. Ver qué hace el legacy en ese caso y replicar.
4. Corregir todo endpoint que falle; si el bug es solo de UI (botón que no dispara la descarga / URL mal construida), corregirlo en el frontend.

## Criterio de aceptación
Tabla de todos los exportes con su estado verificado; tests con contenido real verdes; caso "sin datos" con comportamiento claro; suite completa verde.

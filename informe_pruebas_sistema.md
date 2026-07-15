Informe de Pruebas del Sistema

*Proyecto: Refactorización del sistema  ·  Elaborado por: Nasheli  ·  Fecha: 14 de julio de 2026*

# Resumen 
Se registraron 20 hallazgos durante las pruebas, distribuidos en 9 módulos del sistema. De estos, 2 son urgentes (con impacto directo en el cuadre de caja), 3 de prioridad alta, 11 de prioridad media y 4 de prioridad baja o son sugerencias de mejora.

Los dos puntos urgentes comparten una misma raíz: fallas silenciosas en el flujo de ventas y tickets que pueden descuadrar la caja sin dejar rastro claro (venta que no se guarda sin aviso, y edición de forma de pago sin marcarla como tal). Se recomienda priorizar ambos antes que el resto.
# Tabla resumen de hallazgos

|**#**|**Módulo**|**Hallazgo**|**Tipo**|**Prioridad**|
| :-: | :- | :- | :- | :-: |
|1|Ventas / Caja|Al vender, si se selecciona tienda pero no agente, el sistema no avisa y al guardar no registra nada.|Bug crítico|**URGENTE**|
|2|Tickets|La edición de tickets corresponde a la forma de pago; esto no está claro en el flujo y un cambio ahí afecta el cuadre de caja.|Bug crítico|**URGENTE**|
|3|Agentes|La URL de re-registro de datos muestra pantalla en negro (no carga).|Bug|**ALTA**|
|4|Personal / RRHH|Ficha de perfil de empleado está obsoleta: sin diseño, botón "Guardar ficha" no funciona, botón "Generar certificado" no funciona.|Bug|**ALTA**|
|5|Personal / RRHH|Módulo de generación de PDF (accedido desde botón "Generar PDF") no genera el archivo.|Bug|**ALTA**|
|6|Usuarios / Login|El error de "correo repetido" se muestra en inglés; todos los mensajes de error deberían estar en español.|Bug|**MEDIA**|
|7|Tiendas|El error de "código ya existe" se muestra en inglés.|Bug|**MEDIA**|
|8|Agentes|Al crear un nuevo agente, si falta horario de entrada/salida el sistema solo dice "no se puede" sin indicar el motivo.|Bug (UX de error)|**MEDIA**|
|9|Personal / Planilla|Botón "Exportar PDF constancia" no funciona.|Bug|**MEDIA**|
|10|Asistencia|La función "Editar asistencia" no completa el flujo correctamente; el resto de funciones sí funciona.|Bug|**MEDIA**|
|11|Tickets|La exportación a Excel no respeta los filtros aplicados; exporta todo en vez de lo mostrado en pantalla.|Bug|**MEDIA**|
|12|Reporte diario|Al imprimir boleta/factura no se especifica si el pago fue en efectivo ni el vuelto entregado.|Funcionalidad faltante|**MEDIA**|
|13|Ventas|Se puede editar una venta pero no el tipo de pago usado por el cliente (relacionado con el punto de tickets, ítem 2).|Funcionalidad faltante|**MEDIA**|
|14|Roles y permisos|El personal con rol "Gerencia" no debería poder asignarse como vendedor, pero el sistema aún lo permite.|Regla de negocio|**MEDIA**|
|15|Ventas / Caja|Sugerencia: si no hay efectivo en caja de ventas, no debería permitirse aplicar descuentos.|Sugerencia|**MEDIA**|
|16|Inventario / Buscador|El buscador global no encuentra subsecciones (ej. "Traslados" dentro de Inventario); debería indexar también las secciones internas de cada pestaña.|Mejora|**MEDIA**|
|17|Personal|Sugerencia: no debería permitirse registrar dos empleados con el mismo DNI.|Sugerencia|**BAJA**|
|18|Diseño general|Sugerencia: unificar filtros y botón "Buscar" en una sola fila (aplica a varias pantallas, incluida Tickets).|Mejora UX|**BAJA**|
|19|Tickets|Sugerencia: agregar botón "Hoy" para listar solo los tickets del día.|Mejora UX|**BAJA**|
|20|Pendiente de aclarar|"Corregir el Excel de Excel más fichas": falta detalle sobre qué error presenta este archivo/reporte. Confirmar con Nasheli antes de asignar prioridad.|Por aclarar|**BAJA**|

# Detalle por módulo
## 1\. Usuarios / Acceso al sistema
**[MEDIA] Mensajes de error en inglés**

- Al registrar un correo ya existente, el mensaje de error aparece en inglés. Todos los mensajes de error del sistema deben estar en español.
- *Recomendación: Auditar todos los mensajes de error (backend y frontend) y traducir los que aún estén en inglés.*
## 2\. Agentes
**[MEDIA] Error de creación sin motivo visible**

- Al crear un nuevo agente, el horario de entrada/salida es obligatorio, pero si falta el sistema solo indica "no se puede" sin explicar la causa.
- *Recomendación: Mostrar el motivo específico del error (ej. "Debe completar el horario de entrada y salida").*

**[ALTA] Pantalla en negro al re-registrar datos**

- La URL de re-registro de datos de agentes no carga correctamente; se muestra una pantalla en negro.
- *Recomendación: Revisar el enrutamiento/carga de esa vista; posible error de renderizado o recurso faltante.*
## 3\. Tiendas
**[MEDIA] Error de código duplicado en inglés**

- Al registrar una tienda con un código ya existente, el error se muestra correctamente pero en inglés.
- *Recomendación: Traducir el mensaje al español (mismo caso que el punto 1).*
## 4\. Personal / RRHH
**[BAJA] DNI duplicado permitido**

- El sistema permite registrar dos empleados con el mismo DNI.
- *Recomendación: Agregar validación de unicidad sobre el campo DNI.*

**[MEDIA] Exportar PDF de constancia (Planilla) no funciona**

- En la sección de Planilla, el botón "Exportar PDF constancia" no genera el documento.
- *Recomendación: Revisar la función de generación de PDF asociada a este botón.*

**[ALTA] Ficha de perfil de empleado obsoleta**

- Al abrir el perfil de un empleado se muestra una ficha de RRHH sin diseño actualizado. El botón "Guardar ficha" no funciona y el botón "Generar certificado" tampoco.
- *Recomendación: Rediseñar la ficha y revisar/reimplementar ambas funciones, o evaluar si esta sección debe reemplazarse.*

**[ALTA] Módulo de generación de PDF sin funcionalidad**

- Existe un botón "Generar PDF" que abre un módulo aparte; dentro de ese módulo, el botón que debería generar el PDF no lo hace.
- *Recomendación: Revisar y completar la funcionalidad, o adaptarla al nuevo diseño de RRHH.*
## 5\. Asistencia
**[MEDIA] Editar asistencia no completa el flujo**

- De todas las funciones del módulo de Asistencia, solo "Editar asistencia" no permite completar la edición por otras validaciones del sistema.
- *Recomendación: Revisar las validaciones que bloquean la edición y ajustar el flujo.*
## 6\. Tickets
**[URGENTE] Edición de ticket = forma de pago (impacto en cuadre de caja)**

- Lo que permite editar un ticket es en realidad la forma de pago del cliente. Esto no está claro en el flujo, y un cambio de forma de pago después de emitido el ticket afecta directamente el cuadre de caja.
- *Recomendación: Marcar visualmente que la edición corresponde a la forma de pago, y evaluar si este cambio debe quedar registrado en el cuadre (auditoría). Requiere revisión prioritaria por el impacto contable.*

**[MEDIA] Exportar a Excel no respeta filtros**

- Al exportar tickets a Excel, se exporta todo el listado en vez de solo lo que está filtrado/visible en pantalla.
- *Recomendación: Ajustar la exportación para que tome el resultado filtrado actual.*

**[BAJA] Filtros y botón "Hoy" (diseño)**

- Sugerencia: ubicar los filtros en una sola fila y agregar un botón "Hoy" para listar solo los tickets del día.
- *Recomendación: Incluir en la revisión de diseño general de filtros (ver punto 8).*
## 7\. Reporte diario / Ventas
**[MEDIA] Falta detalle de pago en efectivo y vuelto**

- Al imprimir boleta o factura no se especifica si el cliente pagó en efectivo ni el monto de vuelto entregado.
- *Recomendación: Agregar estos datos al comprobante impreso.*

**[MEDIA] No se puede editar el tipo de pago de una venta**

- Se puede editar una venta, pero no el tipo de pago que usó el cliente. Relacionado con el punto de edición de tickets (módulo 6).
- *Recomendación: Unificar el criterio: si la edición de forma de pago vive en Tickets, dejarlo claro también desde Ventas.*

**[MEDIA] Gerencia asignable como vendedor**

- Un empleado con rol de Gerencia no debería poder ser asignado como vendedor, pero el sistema todavía lo permite.
- *Recomendación: Restringir la asignación de rol "vendedor" cuando el empleado tiene rol de Gerencia.*

**[MEDIA] Descuentos sin efectivo en caja**

- Sugerencia: si no hay efectivo disponible en la caja de ventas, no debería permitirse aplicar descuentos.
- *Recomendación: Evaluar regla de negocio y validarla antes de aplicar un descuento.*

**[URGENTE] Venta no se guarda si falta seleccionar agente**

- Para registrar una venta se debe seleccionar tienda y agente. A veces solo se selecciona la tienda; el sistema no deja avanzar pero tampoco avisa, y al guardar no se registra nada.
- *Recomendación: Agregar validación explícita ("Debe seleccionar un agente") antes de intentar guardar.*
## 8\. Inventario / Buscador global
**[MEDIA] El buscador no indexa subsecciones**

- El buscador general no encuentra "Traslados", que es una subsección dentro de Inventario. Si existen otras subsecciones similares dentro de otras pestañas, tampoco aparecerían en la búsqueda.
- *Recomendación: Extender la indexación del buscador para incluir subsecciones/pestañas internas de cada módulo.*
## 9\. Diseño general
**[BAJA] Filtros y botón Buscar en una sola fila**

- Sugerencia transversal: en las pantallas con filtros (Tickets y otras), unificar filtros y el botón "Buscar" en una misma fila para optimizar espacio y uso.
- *Recomendación: Aplicar como estándar de UI en las pantallas de listado/filtrado.*
## 10\. Pendiente de aclarar
**[BAJA] "Corregir el Excel de Excel más fichas"**

- Se mencionó que hay que corregir este archivo/reporte, pero no se especificó cuál es el error concreto.
- *Recomendación: Confirmar con Nasheli qué falla exactamente en este Excel antes de asignarlo a desarrollo.*
# Sugerencia sobre el formato de reporte
Para las próximas rondas de pruebas, puede ser más rápido para ambos que cada mensaje tuyo venga con una etiqueta corta de módulo al inicio (ej. "[Ventas]", "[Tickets]"), y que cuando algo sea urgente lo marques ahí mismo con "URGENTE" en vez de al final. Así el informe se arma directamente en el orden en que van llegando los reportes, sin tener que reclasificar todo al final. También ayuda separar en el mensaje qué es bug (algo roto) de qué es sugerencia (algo que funciona pero podría mejorar), como ya vienes haciendo — eso se mantuvo en este informe con la columna "Tipo".

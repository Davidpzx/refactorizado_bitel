# Paridad visual/UX de "Registrar Cuadre Diario" (reportes/nuevo)

## Contexto

El legacy PHP (`E:\laragon\www\sistema-rolando-salas\reportes\nuevo_reporte.php`, rol
`jefe_tienda`) y el SPA (`frontend/src/pages/reportes/NuevoReportePage.tsx`, ruta
`/reportes/nuevo`) ya comparten la misma estructura de fondo: 5 secciones de venta
(Postpago, Prepago/Chips, Equipos y Accesorios, Otros Ingresos Fijos, Apoyo
inter-tienda), el mismo bloque "Total Sistema (Consolidado)" y el mismo "Cuadre
Final" (Total en Cajón, Efectivo Esperado, Mi Efectivo, Lo Entregué/En Tienda,
Diferencia). Verificado navegando ambas apps en vivo (usuarios de prueba
`jefe_tienda`/`tienda`) el 2026-07-13.

**Decisión de alcance ya tomada por el usuario:** el SPA usa un patrón de carga de
ventas distinto al legacy — un modal único "Agregar Registro" que persiste cada
venta contra el backend de inmediato (arquitectura con tablas normalizadas:
`ventas`/`venta_equipos`/`venta_lineas`), mientras que el legacy llena 5 secciones
inline y manda todo en un solo POST al finalizar. **Ese patrón NO se toca** — se
considera una mejora arquitectónica deliberada del SPA, no un bug. Esta spec cubre
únicamente gaps cosméticos/de contenido, no el modelo de guardado.

## Gaps a cerrar

1. **Bipay/Anypay embebido** — el legacy muestra un panel Bipay/Anypay con saldos y
   un botón "Cerrar Jornada" en la parte superior del formulario de cuadre. En el
   SPA, Bipay/Anypay solo existe como página aparte en el menú lateral. Mover (o
   replicar) ese panel + botón al tope de `NuevoReportePage.tsx`.

2. **Botón "Guardar Borrador"** — presente en la cabecera del legacy junto a
   "Reg. Consulta CRM" y el indicador de stock. Ausente en el SPA. Agregarlo a la
   fila de acciones de cabecera (junto a "Cancelar").

3. **"Reg. Consulta CRM" (modal "Registro de Cliente — Paso Previo")** — en el
   legacy, al entrar a la pantalla (o vía botón de cabecera) se puede abrir un
   modal con: selector "Agente que atiende", DNI Cliente (con botones Buscar /
   Recuperar Cliente), Nombres y Apellidos autocompletados al buscar, Celular/
   WhatsApp con selector de operadora, y botón "Finalizar Consulta". Este flujo de
   "modo consulta" no existe en el SPA. Agregarlo como modal equivalente,
   reutilizando los endpoints de CRM que ya expone el backend si existen
   (confirmar en `backend/routes/api.php`; si no existen, crear el endpoint
   mínimo de búsqueda/registro de cliente por DNI).

4. **Fecha nativa** — el legacy usa un único `<input type="date">`. El SPA usa 3
   spinners separados (Día/Mes/Año). Reemplazar por un `<input type="date">`
   nativo (o un date-picker de un solo campo) para igualar la interacción.

5. **Label "Yape / Plin" → "Yape"** — el legacy solo dice "Yape" en el campo de
   Dinero No Físico. El SPA dice "Yape / Plin". Corregir el texto al literal del
   legacy.

6. **Selector de Tienda oculto para rol `jefe_tienda`** — en el SPA, un usuario
   `jefe_tienda` todavía ve un combobox "Tienda \*" vacío que debe llenar
   manualmente (aunque solo pertenezca a una tienda). En el legacy, la tienda del
   jefe de tienda es completamente implícita a su sesión — no hay selector. Para
   rol `jefe_tienda` (no para admin/gerente, que sí necesitan elegir tienda en
   "Modo Dios"), ocultar el selector y fijar la tienda automáticamente a la de su
   sesión.

7. **Botón final "Guardar Reporte Completo"** — el legacy usa ese texto exacto en
   azul. El SPA dice "Guardar y Cerrar Caja · Empezar Nuevo" en dorado, con un
   texto de ayuda "Agrega al menos una venta para habilitar el cierre de caja."
   que no tiene equivalente en el legacy (el botón del legacy no exige ventas
   previas para habilitarse). Cambiar label a "Guardar Reporte Completo", color a
   azul (paridad visual), y remover el requisito de "al menos una venta" salvo que
   el backend lo exija por regla de negocio (confirmar antes de quitar la
   validación; si el backend rechaza reportes vacíos, dejar el mensaje pero
   ajustar el texto para que no contradiga el legacy).

8. **"Agregar Comprobante Electrónico" dentro del formulario** — el legacy tiene
   un botón "Agregar Comprobante Electrónico" (con contador) al pie del
   formulario de cuadre, antes de "Guardar Reporte Completo". En el SPA, los
   comprobantes son solo una página aparte del menú ("Comprobantes"), sin control
   embebido en el formulario de reporte. Agregar el control equivalente dentro de
   `NuevoReportePage.tsx`.

9. **Indicador de stock** — el SPA ya muestra un badge "N chips" cerca del botón
   Cancelar (parcialmente equivalente al "Sin stock ⌄" del legacy). Revisar su
   contenido/wording y acercarlo al patrón del legacy (ej. desplegable con el
   detalle de qué está sin stock), sin necesidad de rehacer el componente si el
   dato mostrado ya es correcto — solo ajustar texto/estilo si diverge mucho.

10. **Quitar las 4 tarjetas KPI superiores** (Ventas / Ingresos / Salidas / Total
    Sistema) — no existen en el legacy en esta pantalla. Decisión explícita del
    usuario: eliminarlas para lograr paridad exacta, no dejarlas como "extra".

## Fuera de alcance (explícito)

- Rediseñar el modal "Agregar Registro" para volverlo inline por sección como el
  legacy. Se mantiene el modal actual.
- Cualquier cambio al backend de guardado incremental (`agregar-venta`,
  `borrador`, etc.) salvo lo estrictamente necesario para el punto 3 (CRM) si no
  existe endpoint de búsqueda de cliente por DNI.
- Cambios a roles/permisos backend (ya cubiertos en otro trabajo de este mismo
  ciclo, en el repo legacy).

## Verificación

- Comparar visualmente ambas pantallas logueado como `jefe_tienda`/rol tienda en
  ambos sistemas tras cada cambio (capturas lado a lado).
- Confirmar que `npm run lint` / `tsc` pasan limpios en `frontend/`.
- Confirmar que el flujo completo de guardado (modal "Agregar Registro" → guardar
  reporte) sigue funcionando end-to-end tras los cambios de layout.

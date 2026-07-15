# F6 — Panel de contenido del bot: promociones + catálogo de equipos (refactorizado_bitel) Design

## Contexto

F5 dejó el bot de auto-respuesta funcionando con reglas de texto fijo (`WhatsAppBotRegla`), incluyendo "Planes" y "Equipos" con contenido de relleno. El usuario quiere que ese contenido sea editable desde un panel dedicado, con soporte de fotos, y que la respuesta de equipos jale del inventario real (`inventario_tiendas` / modelo `InventarioTienda`) en vez de un texto estático.

## Decisiones ya tomadas (con el usuario)

- Pestaña nueva **"Contenido del bot"** en `CrmPage.tsx`, separada de WhatsApp, solo admin.
- Fotos como **base64 en BD** (mismo patrón ya usado para el logo de empresa: `LogoProcessorService` + `configuracion_empresa.logo_base64`) — los contenedores no tienen disco persistente entre deploys.
- **Una sola promoción vigente** a la vez (foto + texto), se reemplaza al subir una nueva.
- Fotos de equipos: **una foto por `producto_nombre`**, reutilizada para todas las unidades de ese modelo.
- Respuesta de equipos: lista de modelos con stock, **filtrada por la tienda de la cuenta** (o todas si es cuenta Central), con fotos, top 5.

## Modelo de datos (migraciones)

```php
// create_whatsapp_bot_promocion_table
Schema::create('whatsapp_bot_promocion', function (Blueprint $table) {
    $table->id();
    $table->text('texto');
    $table->longText('foto_base64')->nullable();
    $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
});

// create_whatsapp_bot_fotos_producto_table
Schema::create('whatsapp_bot_fotos_producto', function (Blueprint $table) {
    $table->id();
    $table->string('producto_nombre', 150)->unique();
    $table->longText('foto_base64');
    $table->timestamp('actualizado_en')->useCurrent()->useCurrentOnUpdate();
});

// add_equipos_y_promocion_dinamica_a_whatsapp_bot_reglas
Schema::table('whatsapp_bot_reglas', function (Blueprint $table) {
    $table->boolean('usa_promocion_dinamica')->default(false);
});
```

`tipo` en `whatsapp_bot_reglas` ya es un enum de string en MySQL (Laravel no valida el enum a nivel de columna, solo en el `Rule::in()` del controller) — se amplía la validación existente en `validarBotRegla()` (`'tipo' => ['required', 'in:texto,menu,equipos']`), sin migración adicional sobre esa columna.

Seeder: actualizar `WhatsAppBotReglasSeeder` — la regla "Planes" queda con `usa_promocion_dinamica = true`; la regla "Equipos" queda con `tipo = 'equipos'` y `respuesta` como texto de fallback ("Por ahora no tenemos equipos en stock, un asesor te confirma disponibilidad.").

## Consulta de stock para la respuesta de equipos

```php
InventarioTienda::query()
    ->where('tipo', 'EQUIPO')
    ->where('estado', 'DISPONIBLE')
    ->where('cantidad', '>', 0)
    ->when($tiendaId !== null, fn ($q) => $q->where('tienda_id', $tiendaId))
    ->selectRaw('producto_nombre, SUM(cantidad) as stock, MAX(precio_normal) as precio')
    ->groupBy('producto_nombre')
    ->orderByDesc('stock')
    ->limit(5)
    ->get();
```

`$tiendaId` sale de `$cuenta->tienda_id` de la cuenta que recibió el mensaje. Si es `null` (cuenta Central), se omite el filtro (todas las tiendas).

## Servicio nuevo: `App\Services\WhatsApp\ImagenProductoService`

Distinto de `LogoProcessorService` (ese quita el fondo — mal para fotos de producto/promo, se vería roto). Uno simple:

```php
class ImagenProductoService
{
    private const MAX_LADO = 800;

    /** Redimensiona a max 800px de lado mayor y devuelve un data URI JPEG. Null si no es una imagen valida. */
    public function procesar(string $rutaArchivo): ?string { /* getimagesize + imagecreatefrom* + resize + imagejpeg(quality=80) + base64 */ }
}
```

## Extensión de `App\Services\WhatsApp\BotResponder`

`decidir()` no cambia (sigue devolviendo la `WhatsAppBotRegla` o `'op_asesor'`). El cambio va en el **job** `ResponderBotWhatsApp`, que al resolver el contenido de la regla decide entre 3 caminos:

1. `$regla->usa_promocion_dinamica` → lee `WhatsAppBotPromocion::first()`; si existe y tiene texto, usa eso (con foto si tiene); si la tabla está vacía, cae a `$regla->respuesta`.
2. `$regla->tipo === 'equipos'` → ejecuta la consulta de stock filtrada por tienda de `$chat->cuenta`, resuelve fotos por `WhatsAppBotFotoProducto::whereIn('producto_nombre', ...)`, y arma el envío: por cada modelo con foto, `enviarMedia()` (ya existe desde F2) con caption `"{nombre} — S/{precio}"` y un `usleep` de 1-2s entre cada uno; los modelos sin foto se acumulan en un texto resumen final. Si no hay stock → `$regla->respuesta` (fallback).
3. Caso normal (`texto`/`menu`) → comportamiento actual de F5, sin cambios.

Cada imagen/texto enviado se registra como su propia fila en `WhatsAppMensaje` (`enviado_por = null`), igual que hoy — el límite de 1 respuesta de bot por chat/minuto se evalúa **antes** de todo el envío compuesto (una ejecución del job = una "respuesta", sin importar cuántos mensajes internos genere).

## Endpoints y controller nuevo

`App\Http\Controllers\Api\WhatsAppContenidoController` (mismo middleware `role:administrador` — este panel es exclusivo de admin, a diferencia del resto de `whatsapp/*` que también entra gerente/jefe_tienda):

- `GET /v1/whatsapp/promocion` → `{texto, foto_base64}` o null.
- `POST /v1/whatsapp/promocion` (multipart: `texto`, `foto?`) → procesa con `ImagenProductoService` si viene foto, upsert `id=1`.
- `GET /v1/whatsapp/fotos-producto` → lista `[{id, producto_nombre, foto_base64}]`.
- `POST /v1/whatsapp/fotos-producto` (multipart: `producto_nombre`, `foto`) → upsert por `producto_nombre` único.
- `DELETE /v1/whatsapp/fotos-producto/{id}`.
- `GET /v1/whatsapp/inventario/nombres?q=` → autocompletar contra `InventarioTienda::distinct('producto_nombre')->where('producto_nombre', 'like', "%{$q}%")->limit(10)` para el buscador del panel (evita typos que rompan el match con el nombre real de un modelo).

## Frontend

- `CrmPage.tsx`: agregar `'contenido'` a `CrmTab`, nuevo botón en la barra de tabs (ícono `Image` o similar), solo si `usuario.rol === 'administrador'`.
- `pages/crm/CrmContenidoBotTab.tsx` (nuevo): dos secciones — `PromocionForm` (textarea + input file + preview) y `FotosProductoPanel` (buscador con autocompletar contra el endpoint de inventario, lista con thumbnails, subir/reemplazar/eliminar).
- Hooks nuevos en `hooks/useWhatsApp.ts`: `usePromocion`, `useGuardarPromocion`, `useFotosProducto`, `useGuardarFotoProducto`, `useEliminarFotoProducto`, `useBuscarProductosInventario`.
- Validación cliente: máx 2MB, `image/jpeg|png|webp` (mismo criterio que el logo).

## Manejo de errores

| Caso | Resultado |
|---|---|
| Promoción sin configurar | Regla "Planes" cae al texto fijo original de `respuesta` |
| Sin stock de equipos en la tienda de la cuenta | Mensaje de fallback fijo, sin fotos |
| `enviarMedia` falla en Evolution para un modelo | Se continúa con el resto; ese modelo se agrega igual al texto resumen |
| Foto subida > 2MB o mimetype inválido | 422, no se guarda |
| `producto_nombre` no coincide exactamente con ningún nombre real de `inventario_tiendas` | La foto queda guardada igual (no se valida existencia en inventario al guardar, solo se usa el autocompletar como ayuda) — si nunca hay stock con ese nombre exacto, simplemente nunca se usa |

## Testing

- Unit: `ImagenProductoService::procesar()` con una imagen fake (fixture) → data URI válido; archivo no-imagen → null.
- Feature: `WhatsAppContenidoController` — solo admin (403 para gerente/jefe_tienda); upsert de promoción reemplaza en vez de duplicar; CRUD de fotos de producto.
- Feature: job del bot con regla `usa_promocion_dinamica` sin fila en `whatsapp_bot_promocion` → usa el texto fallback de la regla (test con `Queue::fake()` desacoplado, o test directo del job con `Bus::dispatchSync`).
- Feature: job del bot con regla `tipo=equipos`, stock en la tienda de la cuenta, un producto con foto y otro sin foto → verifica que se llama `enviarMedia` una vez y `enviarTexto` una vez (mock del provider vía `Http::fake` o un fake del `WhatsAppProvider`).
- Manual end-to-end: igual que en el spec de mundo android (subir promo con foto → "precio" → llega foto+caption; subir foto de un producto real con stock → "equipos" → llega foto + resumen de los demás).

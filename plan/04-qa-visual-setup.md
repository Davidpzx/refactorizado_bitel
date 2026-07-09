# TICKET-026 — Entorno de QA visual (resuelto para Bloque A; reusable para B/C/D)

Estado al cierre de esta pasada: backend y frontend corren en local con datos de prueba
realistas. Los comandos de abajo dejan todo operativo en **menos de un minuto** sin tocar
MySQL ni Laragon.

## Decisión de entorno

- **Backend: SQLite**, no MySQL. El MySQL de Windows en :3306 (servicio `mysqld.exe`,
  PID visto en esta pasada: 6324) rechaza `root` sin password, y el binario de Laragon
  (`E:/laragon/bin/mysql/mysql-8.4.3-winx64`) no arrancaba — habría competido por el mismo
  puerto. En vez de pelear con eso, `backend/.env` quedó apuntando a
  `database/database.sqlite` (sqlite ya viene con `pdo_sqlite` habilitado en el PHP de XAMPP).
  El `.env` original (mysql/`migracion`) está comentado dentro del mismo archivo, no se perdió.
- **Datos de prueba: seeder propio**, no el `seed_bitel_reportes.php` del legacy. Ese script
  vive en `E:\laragon\www\sistema-rolando-salas\seed_bitel_reportes.php` pero su propio
  README (`SEED_DEMO_README.md`) dice que está pensado para correr **dentro del VPS**
  (resuelve el host de Dokploy) y trabaja contra el esquema legacy en PHP plano, no contra
  Laravel/Eloquent. Migrarlo tenía más riesgo que valor para QA visual local, así que se
  escribió `backend/database/seeders/QaDemoSeeder.php` (nuevo, no versionado aún — decidir en
  su momento si se comitea) con datos mínimos viables para las 8 pantallas del Bloque A.

## Comandos exactos (repetir en cada pasada nueva, o reusar si la sqlite ya existe)

```bash
# Backend — desde backend/
cd C:/xampp/htdocs/refactorizado_bitel/backend
# (.env ya está en sqlite; si se restaura a mysql, volver a comentar/descomentar ese bloque)
php artisan migrate:fresh --seed              # corre las 49 migraciones + DatabaseSeeder (User test + FacturacionConfig)
php artisan db:seed --class=QaDemoSeeder      # datos de negocio: tiendas, usuarios, agentes, ventas, reportes, CRM, inventario
php artisan serve --port=8000                 # deja corriendo en foreground o con & en background

# Frontend — desde frontend/
cd C:/xampp/htdocs/refactorizado_bitel/frontend
npm run dev                                    # Vite en :5173
```

**Login de prueba:** `admin@qa.test` / `password` (rol `admin`, ve todo el menú).
Otros usuarios sembrados: `tienda@qa.test` (rol `tienda`, tienda T01) y `agente1@qa.test`
(rol `vendedor`, ligado a `agente_id=1`, útil para probar Mi Historial Personal como
no-admin en un bloque futuro).

## Gotcha real encontrado y corregido — no repetir el diagnóstico

`frontend/.env.local` traía `VITE_API_BASE_URL=http://localhost:8000/api/v1`, pero
`frontend/src/services/api.ts` ya arma las rutas como `${baseURL}/v1/...` (su propio
fallback es `http://localhost:8000/api`). Con el `.env.local` viejo, **todas** las
llamadas duplicaban el prefijo (`/api/v1/v1/auth/login` → 404) y el login fallaba en
silencio (el POST devolvía 404 pero la UI solo mostraba "credenciales incorrectas").
Se corrigió a `VITE_API_BASE_URL=http://localhost:8000/api` (sin `/v1`). Vite necesita
reinicio para releer `.env.local` — si el login vuelve a fallar con 404 en
`/api/v1/v1/...`, es este mismo problema.

`.env.local` y `backend/.env` están gitignorados — el fix y el cambio a sqlite son
solo locales, no aparecen en `git status`.

## QaDemoSeeder — qué cubre y qué NO

Cubre (suficiente para Bloque A): 2 tiendas (T01/T02), 3 usuarios de login, 5 agentes
con ficha básica (dni, sueldo, horarios, dirección — **sin** `contactos_emergencia` ni
`carga_familiar`/`formación_académica`/etc., esos quedan en null), 20 días de reportes +
ventas (líneas y equipos) para Dashboard/Productividad/Historial, 6 clientes + leads +
interacciones para CRM, 8 ítems de inventario con precio fijado + 2 ítems "pendientes de
fijar precio" para Precios, 2 entradas de historial_reportes.

NO cubre (si un bloque futuro necesita alguna de estas pantallas, ampliar el seeder):
asistencias, planilla, tickets emitidos, comisiones (config_comisiones/comisiones_rangos),
traslados, postulantes, comprobantes/facturación, integrador Bipay, mapa de calor con
coordenadas variadas. `Cliente`/`Lead`/`InteraccionCrm` sí están cubiertos.

## Playwright (temporal — instalar de nuevo en cada pasada)

No hay skill de navegador disponible en este entorno; se instaló Playwright ad-hoc fuera
del repo (`C:/Users/Usuario/AppData/Local/Temp/qa026_playwright`, con su propio
`package.json` y `npx playwright install chromium`) y las capturas se guardaron en
`C:/Users/Usuario/AppData/Local/Temp/qa026_shots`. **Todo eso se borra al final de cada
pasada** (instrucción explícita del ticket) — el bloque siguiente debe reinstalarlo:

```bash
mkdir -p /tmp/qa026_playwright && cd /tmp/qa026_playwright
npm init -y && npm install playwright@latest
npx playwright install chromium
```

Patrón que funcionó para capturar pantallas autenticadas sin que la SPA se quede en
"Cargando...": **no** uses `page.goto()` para cada ruta (cada uno es una recarga dura que
reinicia el bootstrap de la app y encadena de nuevo `control-center` → `configuracion` →
`tiendas/select` antes del fetch específico de la página, y con `networkidle` +
timeout corto la screenshot cae a mitad de carga). En su lugar, tras el login inicial,
navega client-side dentro de la SPA ya montada:

```js
await page.evaluate((path) => {
  window.history.pushState({}, '', path);
  window.dispatchEvent(new Event('popstate'));
}, '/estadisticas');
```

y espera a que el texto "Cargando" desaparezca del `body` antes de la screenshot.

## Puertos y procesos — estado al cerrar esta pasada

Backend (`php artisan serve --port=8000`) y frontend (`npm run dev`, :5173) se **detuvieron**
al terminar esta pasada, tal como pide el ticket. Para el siguiente bloque, repetir los
comandos de arriba. No se tocó ningún proceso `node.exe` ajeno (los ~16 preexistentes) —
solo se mató por PID específico el proceso de Vite propio cuando hubo que reiniciarlo tras
editar `.env.local` (nunca `taskkill /IM`).

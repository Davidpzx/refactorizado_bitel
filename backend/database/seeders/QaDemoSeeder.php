<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder temporal para TICKET-026 (QA visual). Puebla datos mínimos viables
 * para renderizar Login/Dashboard/Productividad/CRM/Precios/Historial/
 * Mi Reporte/Ver Agente sin depender de MySQL legacy ni del VPS.
 *
 * Uso: php artisan db:seed --class=QaDemoSeeder
 */
class QaDemoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // ── Tiendas ──────────────────────────────────────────────────────────
        DB::table('tiendas')->insert([
            ['codigo' => 'T01', 'nombre' => 'Tienda Real Plaza', 'direccion' => 'Av. Ejercito 1000', 'telefono' => '987654321', 'activo' => true, 'radio_permitido' => 60, 'lat_centro' => -16.398800, 'lng_centro' => -71.536900],
            ['codigo' => 'T02', 'nombre' => 'Tienda Mall Aventura', 'direccion' => 'Av. Porongoche 500', 'telefono' => '987654322', 'activo' => true, 'radio_permitido' => 60, 'lat_centro' => -16.410000, 'lng_centro' => -71.520000],
        ]);

        // ── Usuarios (login) ─────────────────────────────────────────────────
        DB::table('usuarios')->insert([
            ['nombre' => 'Gerencia QA', 'email' => 'admin@qa.test', 'password' => Hash::make('password'), 'rol' => 'admin', 'tienda_id' => null, 'activo' => true, 'agente_id' => null, 'created_at' => $now],
            ['nombre' => 'Jefe Tienda T01', 'email' => 'tienda@qa.test', 'password' => Hash::make('password'), 'rol' => 'tienda', 'tienda_id' => 'T01', 'activo' => true, 'agente_id' => null, 'created_at' => $now],
            ['nombre' => 'Ana Torres', 'email' => 'agente1@qa.test', 'password' => Hash::make('password'), 'rol' => 'vendedor', 'tienda_id' => 'T01', 'activo' => true, 'agente_id' => 1, 'created_at' => $now],
        ]);

        // ── Agentes ──────────────────────────────────────────────────────────
        $agentes = [
            [1, 'Ana', 'Torres Quispe', '71234561', 'T01'],
            [2, 'Luis', 'Mamani Ccama', '71234562', 'T01'],
            [3, 'Rosa', 'Huanca Flores', '71234563', 'T02'],
            [4, 'Jose', 'Chavez Rondon', '71234564', 'T02'],
            [5, 'Maria', 'Salas Vera', '71234565', 'T01'],
        ];
        foreach ($agentes as [$id, $nombre, $apellidos, $dni, $tienda]) {
            DB::table('agentes')->insert([
                'id' => $id,
                'nombres' => $nombre,
                'apellidos' => $apellidos,
                'dni' => $dni,
                'estado' => 'ACTIVO',
                'tienda_base' => $tienda,
                'sueldo_base' => 1130.00,
                'pin_seguridad' => Hash::make('1234'),
                'hora_ingreso' => '09:00:00',
                'hora_salida' => '20:00:00',
                'fecha_ingreso' => '2025-01-15',
                'correo' => strtolower($nombre) . '.' . strtolower($apellidos[0] ?? 'x') . '@qa.test',
                'telefono' => '9'.rand(10000000, 99999999),
                'direccion' => 'Calle Los Alamos 123, Arequipa',
                'es_gerencia' => false,
                'grupo_sanguineo' => 'O+',
                'sistema_pension' => 'AFP',
                'nombre_afp' => 'Integra',
            ]);
        }

        // ── Reportes + ventas (últimos 20 días, 2 agentes activos por tienda) ─
        $reporteId = 1;
        $ventaId = 1;
        $lineaId = 1;
        $equipoId = 1;
        $planes = ['PLAN 49 LN', 'PLAN 69 LN', 'PLAN 89 PORTA', 'CHIP PREPAGO'];
        $productos = ['Samsung A15', 'Xiaomi Redmi 13', 'iPhone 13', 'Motorola G84'];

        for ($d = 19; $d >= 0; $d--) {
            $fecha = now()->subDays($d)->format('Y-m-d');
            foreach ([1, 2, 3, 4] as $agenteId) {
                $tienda = $agenteId <= 2 ? 'T01' : 'T02';
                $yape = rand(50, 400);
                $bipay = rand(100, 600);
                $transferencia = rand(0, 300);
                $totalDia = $yape + $bipay + $transferencia;

                DB::table('reportes')->insert([
                    'id' => $reporteId,
                    'agente_id' => $agenteId,
                    'tienda_id' => $tienda,
                    'usuario_id' => 1,
                    'fecha' => $fecha,
                    'total_dia' => $totalDia,
                    'total_calculado' => $totalDia,
                    'yape' => $yape,
                    'bipay' => $bipay,
                    'transferencia' => $transferencia,
                    'caja_inicial' => 100,
                    'efectivo_entregado' => $totalDia,
                    'total_restantes' => 0,
                    'efectivo_esperado' => $totalDia,
                    'diferencia' => $d === 3 ? -15 : 0,
                    'estado' => $d === 0 ? 'borrador' : 'cerrado',
                    'requiere_aprobacion' => $d === 3,
                    'created_at' => $fecha . ' 20:30:00',
                    'updated_at' => $fecha . ' 20:30:00',
                ]);

                // 1-2 ventas por reporte
                $numVentas = rand(1, 2);
                for ($v = 0; $v < $numVentas; $v++) {
                    $tipoVenta = $v === 0 ? 'LINEA_NUEVA' : 'EQUIPO';
                    $monto = rand(80, 350);
                    $comision = round($monto * 0.1, 2);

                    DB::table('ventas')->insert([
                        'id' => $ventaId,
                        'reporte_id' => $reporteId,
                        'vendedor_id' => $agenteId,
                        'tipo_venta' => $tipoVenta,
                        'monto_total' => $monto,
                        'comision_generada' => $comision,
                        'comision_estado' => 'ACTIVA',
                        'creado_en' => $fecha . ' 15:00:00',
                    ]);

                    if ($tipoVenta === 'LINEA_NUEVA') {
                        DB::table('venta_lineas')->insert([
                            'id' => $lineaId,
                            'venta_id' => $ventaId,
                            'plan_nombre_snap' => $planes[array_rand($planes)],
                            'tipo_alta' => 'LN',
                            'cantidad' => 1,
                            'cobrado_unitario' => $monto,
                            'comision_unitaria' => $comision,
                        ]);
                        $lineaId++;
                    } else {
                        DB::table('venta_equipos')->insert([
                            'id' => $equipoId,
                            'venta_id' => $ventaId,
                            'producto_nombre_snap' => $productos[array_rand($productos)],
                            'tipo_item' => 'EQUIPO',
                            'tipo_pago' => 'CONTADO',
                            'precio_venta' => $monto,
                            'costo_snap' => round($monto * 0.7, 2),
                            'ganancia_snap' => round($monto * 0.3, 2),
                        ]);
                        $equipoId++;
                    }
                    $ventaId++;
                }
                $reporteId++;
            }
        }

        // ── Historial de reportes (para Historial admin) ────────────────────
        DB::table('historial_reportes')->insert([
            ['reporte_id' => 1, 'usuario_id' => 1, 'accion' => 'EDICION', 'detalle' => 'Ajuste de yape por diferencia de caja', 'created_at' => $now, 'updated_at' => $now],
            ['reporte_id' => 5, 'usuario_id' => 1, 'accion' => 'APROBACION', 'detalle' => 'Aprobado con descuadre de S/ 15.00', 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ── Clientes + Leads + Interacciones (CRM) ──────────────────────────
        $clienteId = 1;
        $leadId = 1;
        $nombresCliente = ['Carlos Rivera', 'Pedro Suarez', 'Lucia Fernandez', 'Diana Paredes', 'Miguel Rojas', 'Sandra Vega'];
        foreach ($nombresCliente as $i => $nombreCliente) {
            DB::table('clientes')->insert([
                'id' => $clienteId,
                'dni_ruc' => (string) (72000000 + $i),
                'nombre' => $nombreCliente,
                'telefono' => '9'.rand(10000000, 99999999),
                'correo' => strtolower(str_replace(' ', '.', $nombreCliente)) . '@qa.test',
                'tipo_documento' => 'DNI',
                'creado_en' => $now,
            ]);

            $estados = ['NUEVO', 'CONTACTADO', 'INTERESADO', 'CONVERTIDO', 'PERDIDO'];
            $agenteId = ($i % 4) + 1;
            $tienda = $agenteId <= 2 ? 'T01' : 'T02';

            DB::table('leads')->insert([
                'id' => $leadId,
                'cliente_id' => $clienteId,
                'agente_id' => $agenteId,
                'tienda_id' => $tienda,
                'estado' => $estados[$i % count($estados)],
                'fuente' => $i % 2 === 0 ? 'PRESENCIAL' : 'REFERIDO',
                'notas' => 'Interesado en portabilidad a Bitel',
                'creado_en' => now()->subDays(rand(1, 15)),
            ]);

            DB::table('interacciones_crm')->insert([
                'lead_id' => $leadId,
                'cliente_id' => $clienteId,
                'agente_id' => $agenteId,
                'tipo' => $i % 2 === 0 ? 'LLAMADA' : 'WHATSAPP',
                'detalle' => 'Seguimiento de propuesta comercial',
                'fecha' => now()->subDays(rand(0, 10)),
            ]);

            $clienteId++;
            $leadId++;
        }

        // ── Inventario / precios (Precios — revisar_stock) ──────────────────
        $itemsInventario = [
            ['T01', 'Samsung A15', 'EQUIPO', 650, 700, 750],
            ['T01', 'Xiaomi Redmi 13', 'EQUIPO', 480, 520, 560],
            ['T01', 'Cargador Tipo C 25W', 'ACCESORIO', 15, 20, 25],
            ['T01', 'Case iPhone 13', 'ACCESORIO', 8, 12, 18],
            ['T02', 'iPhone 13', 'EQUIPO', 1450, 1550, 1650],
            ['T02', 'Motorola G84', 'EQUIPO', 780, 850, 900],
            ['T02', 'Mica templada universal', 'ACCESORIO', 3, 5, 8],
            ['T02', 'Audífonos Bluetooth', 'ACCESORIO', 25, 35, 45],
        ];
        foreach ($itemsInventario as [$tienda, $producto, $tipo, $costo, $min, $normal]) {
            DB::table('inventario_tiendas')->insert([
                'tienda_id' => $tienda,
                'producto_nombre' => $producto,
                'tipo' => $tipo,
                'precio_costo' => $costo,
                'precio_minimo' => $min,
                'precio_normal' => $normal,
                'cantidad' => rand(2, 15),
                'estado' => 'DISPONIBLE',
                'registrado_por' => 'Gerencia QA',
                'fecha_registro' => now()->subDays(rand(5, 40))->format('Y-m-d'),
            ]);
        }

        // Items SIN precio fijado (para la pestaña "Pendientes" — botón "Fijar")
        $pendientes = [
            ['T01', 'Poco X7 Pro', 'EQUIPO'],
            ['T02', 'Parlante Bluetooth JBL Clon', 'ACCESORIO'],
        ];
        foreach ($pendientes as [$tienda, $producto, $tipo]) {
            DB::table('inventario_tiendas')->insert([
                'tienda_id' => $tienda,
                'producto_nombre' => $producto,
                'tipo' => $tipo,
                'precio_costo' => 0,
                'precio_minimo' => 0,
                'precio_normal' => 0,
                'cantidad' => rand(1, 5),
                'estado' => 'DISPONIBLE',
                'registrado_por' => 'Gerencia QA',
                'fecha_registro' => now()->subDays(rand(1, 3))->format('Y-m-d'),
            ]);
        }

        // ── Asistencias (para Bloque B: Asistencias / Control / Liquidación / Fotos) ─
        $pixelPng = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        for ($d = 12; $d >= 0; $d--) {
            $fecha = now()->subDays($d)->format('Y-m-d');
            foreach ([1, 2, 3, 4, 5] as $agenteId) {
                $tienda = $agenteId <= 2 || $agenteId === 5 ? 'T01' : 'T02';

                // Ana Torres (1) el día d=5: falta injustificada, sin marcación
                if ($agenteId === 1 && $d === 5) {
                    DB::table('asistencias')->insert([
                        'agente_id' => $agenteId,
                        'tienda_id' => $tienda,
                        'fecha' => $fecha,
                        'estado_asistencia' => 'FALTA_INJUSTIFICADA',
                        'requiere_revision' => false,
                        'metodo_marcacion' => 'MANUAL',
                        'created_at' => $fecha . ' 09:00:00',
                        'updated_at' => $fecha . ' 09:00:00',
                    ]);
                    continue;
                }

                $tardanza = ($agenteId === 2 && in_array($d, [2, 7], true)) ? rand(5, 25) : 0;
                $conFoto = ($agenteId === 3 && in_array($d, [0, 1], true));
                $conGps = $agenteId === 4 && $d < 6;

                DB::table('asistencias')->insert([
                    'agente_id' => $agenteId,
                    'tienda_id' => $tienda,
                    'tienda_ingreso' => $tienda,
                    'fecha' => $fecha,
                    'hora_ingreso' => $tardanza > 0 ? sprintf('09:%02d:00', $tardanza) : '09:00:00',
                    'inicio_refrigerio' => '13:00:00',
                    'fin_refrigerio' => '14:00:00',
                    'hora_salida' => '20:00:00',
                    'minutos_tardanza' => $tardanza,
                    'estado_asistencia' => $tardanza > 0 ? 'TARDANZA' : 'REGULAR',
                    'requiere_revision' => $conFoto,
                    'metodo_marcacion' => $conFoto ? 'FOTO' : ($conGps ? 'GPS' : 'MANUAL'),
                    'foto_marcacion' => $conFoto ? $pixelPng : null,
                    'lat_entrada' => $conGps ? -16.3988 : null,
                    'lng_entrada' => $conGps ? -71.5369 : null,
                    'accuracy_entrada' => $conGps ? 12.5 : null,
                    'created_at' => $fecha . ' 09:00:00',
                    'updated_at' => $fecha . ' 09:00:00',
                ]);
            }
        }

        // ── Tickets emitidos (para Bloque B: Tickets) ────────────────────────
        $formasPago = ['Efectivo', 'Yape', 'Bipay', 'Transferencia'];
        $descripciones = [
            'Pago de servicio Bitel postpago', 'Recarga prepago', 'Venta accesorio - case',
            'Pago de cuota equipo', 'Portabilidad - trámite', 'Recarga BCP',
        ];
        for ($t = 0; $t < 10; $t++) {
            $agenteId = ($t % 4) + 1;
            $tienda = $agenteId <= 2 ? 'T01' : 'T02';
            $monto = rand(20, 250);
            $formaPago = $formasPago[$t % count($formasPago)];

            DB::table('tickets_emitidos')->insert([
                'tienda_id' => $tienda,
                'agente_id' => $agenteId,
                'vendedor' => $agentes[$agenteId - 1][1] . ' ' . $agentes[$agenteId - 1][2],
                'nombre_cliente' => $nombresCliente[$t % count($nombresCliente)],
                'dni_cliente' => (string) (72000000 + ($t % count($nombresCliente))),
                'forma_pago' => $formaPago,
                'telefono' => '9'.rand(10000000, 99999999),
                'descripcion' => $descripciones[$t % count($descripciones)] . ' S/' . number_format($monto, 2),
                'monto' => $monto,
                'cantidad' => 1,
                'efectivo' => $formaPago === 'Efectivo' ? $monto : 0,
                'yape' => $formaPago === 'Yape' ? $monto : 0,
                'bipay' => $formaPago === 'Bipay' ? $monto : 0,
                'transferencia' => $formaPago === 'Transferencia' ? $monto : 0,
                'vuelto' => 0,
                'creado_en' => now()->subDays(rand(0, 10))->subHours(rand(0, 8)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->command?->info('QaDemoSeeder: datos de prueba TICKET-026 (Bloque A + B) insertados.');
        $this->command?->info('Login admin: admin@qa.test / password');
    }
}

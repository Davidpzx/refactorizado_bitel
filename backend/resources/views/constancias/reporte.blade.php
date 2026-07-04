<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte #{{ str_pad($reporte->id, 5, '0', STR_PAD_LEFT) }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 10px; color: #1e293b; background: #fff; padding: 20px; }
    h1 { font-size: 15px; font-weight: bold; color: #1e3a8a; text-align: center; margin-bottom: 4px; }
    .empresa { text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 2px; }
    .subtitulo { text-align: center; font-size: 9px; color: #64748b; margin-bottom: 16px; }
    .separador { border-top: 2px solid #1e3a8a; margin: 10px 0; }
    .grid2 { display: table; width: 100%; margin-bottom: 10px; }
    .grid2 .col { display: table-cell; width: 50%; vertical-align: top; padding-right: 10px; }
    .campo { margin-bottom: 4px; }
    .campo strong { display: inline-block; min-width: 130px; color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; margin-bottom: 10px; font-size: 9.5px; page-break-inside: avoid; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    th.titulo-seccion { background: #1e3a8a; color: #fff; padding: 5px 6px; text-align: left; font-size: 10.5px; }
    th { background: #eef2ff; color: #1e3a8a; padding: 4px 6px; text-align: left; font-size: 9px; }
    td { padding: 3px 6px; border-bottom: 1px solid #e2e8f0; }
    tr:nth-child(even) td { background: #f8fafc; }
    .monto { text-align: right; }
    .centro { text-align: center; }
    .total-row td { font-weight: bold; background: #eff6ff; }
    .obs-titulo { background: #0d6efd; color: #fff; padding: 5px 6px; font-weight: bold; font-size: 10.5px; }
    .obs-cuerpo { padding: 6px; }
    .badge { display: inline-block; font-size: 7.8px; font-weight: bold; padding: 1px 4px; border-radius: 3px;
             margin: 0 2px 2px 0; background: #fef3c7; color: #92400e; }
    .badge-info { background: #dbeafe; color: #1e40af; }
    .footer { text-align: center; font-size: 8px; color: #94a3b8; margin-top: 16px; }
</style>
</head>
<body>

<p class="empresa">{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</p>
<h1>REPORTE DETALLADO DE VENTAS</h1>
<p class="subtitulo">
    Reporte #{{ str_pad($reporte->id, 5, '0', STR_PAD_LEFT) }}
    &nbsp;|&nbsp; Fecha: {{ \Carbon\Carbon::parse($reporte->fecha)->format('d/m/Y') }}
    &nbsp;|&nbsp; Tienda: {{ $reporte->tienda_id }} ({{ $reporte->tienda_nombre }})
    &nbsp;|&nbsp; Generado: {{ now()->timezone('America/Lima')->format('d/m/Y H:i') }}
</p>

<div class="separador"></div>

<div class="grid2">
    <div class="col">
        <div class="campo"><strong>Cajero:</strong> {{ $reporte->agente_nombre }}</div>
        <div class="campo"><strong>DNI Cajero:</strong> {{ $reporte->agente_dni }}</div>
        <div class="campo"><strong>Estado:</strong> {{ strtoupper($reporte->estado) }}</div>
    </div>
    <div class="col">
        <div class="campo"><strong>Caja Inicial:</strong> S/ {{ number_format($reporte->caja_inicial, 2) }}</div>
        <div class="campo"><strong>Total Calculado:</strong> S/ {{ number_format($reporte->total_calculado, 2) }}</div>
        <div class="campo"><strong>Efectivo Entregado:</strong> S/ {{ number_format($reporte->efectivo_entregado, 2) }}</div>
        <div class="campo"><strong>Diferencia:</strong> S/ {{ number_format($reporte->diferencia, 2) }}</div>
    </div>
</div>

<div class="separador"></div>

@php
    $postpago = $ventas->filter(fn ($v) => $v->tipo_venta === 'POSTPAGO')->values();
    $prepago  = $ventas->filter(fn ($v) => $v->tipo_venta === 'PREPAGO')->values();
    $equipos  = $ventas->filter(fn ($v) => in_array($v->tipo_venta, ['EQUIPO', 'ACCESORIO'], true))->values();
    $otros    = $ventas->filter(fn ($v) => $v->tipo_venta === 'OTROS_FLUJO')->values();
    $apoyo    = $ventas->filter(fn ($v) => $v->tipo_venta === 'APOYO')->values();

    $nombreVendedor = fn ($v) => $v->vendedor?->nombres ?? 'N/A';
    $dniCliente     = fn ($v) => $v->cliente?->dni_ruc ?? '—';
    $tipoAltaLabel  = fn ($ta) => $ta === 'MNP' ? 'PORT.' : ($ta ?? 'LN');
@endphp

@if(!empty($reporte->obs_dia))
<table>
    <thead><tr><th colspan="2" class="obs-titulo">OBSERVACIONES DEL DÍA</th></tr></thead>
    <tbody><tr><td colspan="2" class="obs-cuerpo">{!! nl2br(e($reporte->obs_dia)) !!}</td></tr></tbody>
</table>
@endif

@if($postpago->count() > 0)
<table>
    <thead>
        <tr><th colspan="7" class="titulo-seccion">1. VENTAS POSTPAGO (LÍNEAS)</th></tr>
        <tr>
            <th>Plan</th><th>Tipo Alta</th><th class="centro">Cant.</th>
            <th class="monto">Cobrado</th><th>Vendedor</th><th>DNI/Cel. Cliente</th>
            <th>Badges</th>
        </tr>
    </thead>
    <tbody>
        @foreach($postpago as $v)
        <tr>
            <td>{{ $v->linea?->plan_nombre_snap ?? '—' }}</td>
            <td class="centro">{{ $tipoAltaLabel($v->linea?->tipo_alta ?? null) }}</td>
            <td class="centro">{{ $v->linea?->cantidad ?? 1 }}</td>
            <td class="monto">S/ {{ number_format($v->monto_total, 2) }}</td>
            <td>{{ $nombreVendedor($v) }}</td>
            <td class="centro">{{ $dniCliente($v) }}</td>
            <td>
                @if($v->linea?->es_migracion)<span class="badge">Migración</span>@endif
                @if($v->linea?->es_upgrade)<span class="badge">Upgrade</span>@endif
                @if($v->linea?->es_esim)<span class="badge badge-info">eSIM</span>@endif
                @if($v->es_remate)<span class="badge">Remate</span>@endif
                @if($v->es_extranjero)<span class="badge">Extranjero</span>@endif
                @if($v->cross_selling)<span class="badge badge-info">Cross {{ $v->tienda_destino }}</span>@endif
            </td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="3">TOTAL POSTPAGO ({{ $postpago->count() }})</td>
            <td class="monto">S/ {{ number_format($postpago->sum('monto_total'), 2) }}</td>
            <td colspan="3">Comisión: S/ {{ number_format($postpago->sum('comision_generada'), 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@if($prepago->count() > 0)
<table>
    <thead>
        <tr><th colspan="7" class="titulo-seccion">2. VENTAS PREPAGO (CHIPS)</th></tr>
        <tr>
            <th>Plan / Chip</th><th>Tipo Alta</th><th class="centro">Cant.</th>
            <th class="monto">Cobrado</th><th>Vendedor</th><th>DNI/Cel. Cliente</th>
            <th>Badges</th>
        </tr>
    </thead>
    <tbody>
        @foreach($prepago as $v)
        <tr>
            <td>{{ $v->linea?->plan_nombre_snap ?? '—' }}</td>
            <td class="centro">{{ $tipoAltaLabel($v->linea?->tipo_alta ?? null) }}</td>
            <td class="centro">{{ $v->linea?->cantidad ?? 1 }}</td>
            <td class="monto">S/ {{ number_format($v->monto_total, 2) }}</td>
            <td>{{ $nombreVendedor($v) }}</td>
            <td class="centro">{{ $dniCliente($v) }}</td>
            <td>
                @if($v->linea?->es_migracion)<span class="badge">Migración</span>@endif
                @if($v->linea?->es_upgrade)<span class="badge">Upgrade</span>@endif
                @if($v->linea?->es_esim)<span class="badge badge-info">eSIM</span>@endif
                @if($v->es_remate)<span class="badge">Remate</span>@endif
                @if($v->es_extranjero)<span class="badge">Extranjero</span>@endif
                @if($v->cross_selling)<span class="badge badge-info">Cross {{ $v->tienda_destino }}</span>@endif
            </td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="3">TOTAL PREPAGO ({{ $prepago->count() }})</td>
            <td class="monto">S/ {{ number_format($prepago->sum('monto_total'), 2) }}</td>
            <td colspan="3">Comisión: S/ {{ number_format($prepago->sum('comision_generada'), 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@if($equipos->count() > 0)
<table>
    <thead>
        <tr><th colspan="8" class="titulo-seccion">3. EQUIPOS Y ACCESORIOS</th></tr>
        <tr>
            <th>Producto</th><th>IMEI/Serial</th><th class="monto">Precio</th>
            <th>Tipo Pago</th><th>Financiera</th><th>Vendedor</th><th>DNI Cliente</th>
            <th class="monto">Ganancia</th>
        </tr>
    </thead>
    <tbody>
        @php $totPrecio = 0; $totGanancia = 0; @endphp
        @foreach($equipos as $v)
            @php
                $e = $v->equipo;
                $tp = strtoupper($e?->tipo_pago ?? 'CONTADO');
                $precio = $tp === 'CUOTAS' ? (float) $v->efectivo_inicial : (float) ($e?->precio_venta ?? $v->monto_total);
                $ganancia = (float) ($e?->ganancia_snap ?? 0);
                $totPrecio += $precio;
                $totGanancia += $ganancia;
            @endphp
        <tr>
            <td>{{ $e?->producto_nombre_snap ?? '—' }}</td>
            <td class="centro">{{ $e?->imei_serial_snap ?? '—' }}</td>
            <td class="monto">S/ {{ number_format($precio, 2) }}</td>
            <td class="centro">{{ $tp }}</td>
            <td class="centro">{{ $e?->financiera ?? '—' }}</td>
            <td>{{ $nombreVendedor($v) }}</td>
            <td class="centro">{{ $dniCliente($v) }}</td>
            <td class="monto">S/ {{ number_format($ganancia, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="2">TOTAL EQUIPOS/ACCESORIOS ({{ $equipos->count() }})</td>
            <td class="monto">S/ {{ number_format($totPrecio, 2) }}</td>
            <td colspan="4">Comisión: S/ {{ number_format($equipos->sum('comision_generada'), 2) }}</td>
            <td class="monto">S/ {{ number_format($totGanancia, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@if($otros->count() > 0)
<table>
    <thead>
        <tr><th colspan="5" class="titulo-seccion">4. OTROS INGRESOS / SERVICIOS</th></tr>
        <tr><th>Concepto</th><th>Vendedor</th><th>DNI Cliente</th><th class="monto">Monto</th><th class="monto">Comisión</th></tr>
    </thead>
    <tbody>
        @foreach($otros as $v)
        <tr>
            <td>{{ $v->subtipo ?? 'Otros' }}</td>
            <td>{{ $nombreVendedor($v) }}</td>
            <td class="centro">{{ $v->cliente ? $dniCliente($v) : '—' }}</td>
            <td class="monto">S/ {{ number_format($v->monto_total, 2) }}</td>
            <td class="monto">S/ {{ number_format($v->comision_generada, 2) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="3">TOTAL OTROS ({{ $otros->count() }})</td>
            <td class="monto">S/ {{ number_format($otros->sum('monto_total'), 2) }}</td>
            <td class="monto">S/ {{ number_format($otros->sum('comision_generada'), 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@if($apoyo->count() > 0)
<table>
    <thead>
        <tr><th colspan="7" class="titulo-seccion">5. APOYO A OTRAS TIENDAS</th></tr>
        <tr>
            <th>Plan</th><th>Tipo Alta</th><th class="centro">Cant.</th>
            <th class="monto">Cobrado</th><th>Tienda Destino</th><th>Vendedor</th><th>DNI/Cel. Cliente</th>
        </tr>
    </thead>
    <tbody>
        @foreach($apoyo as $v)
        <tr>
            <td>{{ $v->linea?->plan_nombre_snap ?? '—' }}</td>
            <td class="centro">{{ $tipoAltaLabel($v->linea?->tipo_alta ?? null) }}</td>
            <td class="centro">{{ $v->linea?->cantidad ?? 1 }}</td>
            <td class="monto">S/ {{ number_format($v->monto_total, 2) }}</td>
            <td class="centro">{{ $v->tienda_destino ?? '—' }}</td>
            <td>{{ $nombreVendedor($v) }}</td>
            <td class="centro">{{ $dniCliente($v) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td colspan="3">TOTAL APOYO ({{ $apoyo->count() }})</td>
            <td class="monto">S/ {{ number_format($apoyo->sum('monto_total'), 2) }}</td>
            <td colspan="3">Comisión: S/ {{ number_format($apoyo->sum('comision_generada'), 2) }}</td>
        </tr>
    </tfoot>
</table>
@endif

@if($salidas->count() > 0)
<table>
    <thead>
        <tr><th colspan="3" class="titulo-seccion">6. SALIDAS Y GASTOS</th></tr>
        <tr><th>Tipo</th><th class="monto">Monto</th><th>Observación</th></tr>
    </thead>
    <tbody>
        @foreach($salidas as $s)
        <tr>
            <td>{{ strtoupper($s->tipo) }}</td>
            <td class="monto">S/ {{ number_format($s->monto, 2) }}</td>
            <td>{{ $s->observacion }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total-row">
            <td>TOTAL SALIDAS</td>
            <td class="monto">S/ {{ number_format($salidas->sum('monto'), 2) }}</td>
            <td></td>
        </tr>
    </tfoot>
</table>
@endif

<table>
    <thead><tr><th colspan="2" class="titulo-seccion">7. CUADRE FINANCIERO Y EFECTIVO</th></tr></thead>
    <tbody>
        <tr>
            <td>TOTAL VENTAS BRUTAS:</td>
            <td class="monto">S/ {{ number_format($reporte->total_calculado, 2) }}</td>
        </tr>
        <tr>
            <td>(+) Sencillo Inicial (Caja Base):</td>
            <td class="monto">S/ {{ number_format($reporte->caja_inicial, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>EFECTIVO FÍSICO ENTREGADO:</td>
            <td class="monto">S/ {{ number_format($reporte->efectivo_entregado, 2) }}</td>
        </tr>
        <tr>
            <td>DIFERENCIA:</td>
            <td class="monto">S/ {{ number_format($reporte->diferencia, 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>COMISIÓN TOTAL GENERADA:</td>
            <td class="monto">S/ {{ number_format($ventas->sum('comision_generada'), 2) }}</td>
        </tr>
        <tr class="total-row">
            <td>GANANCIA TOTAL (EQUIPOS):</td>
            <td class="monto">S/ {{ number_format($equipos->sum(fn ($v) => (float) ($v->equipo?->ganancia_snap ?? 0)), 2) }}</td>
        </tr>
        @if(!empty($reporte->observaciones))
        <tr>
            <td>OBSERVACIONES:</td>
            <td>{!! nl2br(e($reporte->observaciones)) !!}</td>
        </tr>
        @endif
    </tbody>
</table>

<p class="footer">Documento generado automáticamente por {{ $empresa->razon_social ?? 'SIS KYRO DASAM' }} — {{ now()->format('Y') }}</p>
</body>
</html>

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
    table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 9.5px; }
    th { background: #1e3a8a; color: #fff; padding: 4px 6px; text-align: left; }
    td { padding: 3px 6px; border-bottom: 1px solid #e2e8f0; }
    tr:nth-child(even) td { background: #f8fafc; }
    .monto { text-align: right; }
    .total-row td { font-weight: bold; background: #eff6ff; }
    .footer { text-align: center; font-size: 8px; color: #94a3b8; margin-top: 16px; }
</style>
</head>
<body>

<p class="empresa">{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</p>
<h1>REPORTE DE CAJA</h1>
<p class="subtitulo">
    Reporte #{{ str_pad($reporte->id, 5, '0', STR_PAD_LEFT) }}
    &nbsp;|&nbsp; Fecha: {{ \Carbon\Carbon::parse($reporte->fecha)->format('d/m/Y') }}
    &nbsp;|&nbsp; Generado: {{ now()->timezone('America/Lima')->format('d/m/Y H:i') }}
</p>

<div class="separador"></div>

<div class="grid2">
    <div class="col">
        <div class="campo"><strong>Agente:</strong> {{ $reporte->agente_nombre }}</div>
        <div class="campo"><strong>DNI:</strong> {{ $reporte->agente_dni }}</div>
        <div class="campo"><strong>Tienda:</strong> {{ $reporte->tienda_id }} ({{ $reporte->tienda_nombre }})</div>
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

@if($ventas->count() > 0)
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Tipo Venta</th>
            <th>Subtipo</th>
            <th class="monto">Monto</th>
        </tr>
    </thead>
    <tbody>
        @foreach($ventas as $idx => $venta)
        <tr>
            <td>{{ $idx + 1 }}</td>
            <td>{{ $venta->tipo_venta }}</td>
            <td>{{ $venta->subtipo ?? '—' }}</td>
            <td class="monto">S/ {{ number_format($venta->monto_total, 2) }}</td>
        </tr>
        @endforeach
        <tr class="total-row">
            <td colspan="3">TOTAL VENTAS</td>
            <td class="monto">S/ {{ number_format($ventas->sum('monto_total'), 2) }}</td>
        </tr>
    </tbody>
</table>
@else
<p style="text-align:center; color: #94a3b8;">Sin ventas registradas en este reporte.</p>
@endif

@if($reporte->observaciones)
<div style="margin-top: 10px;"><strong>Observaciones:</strong> {{ $reporte->observaciones }}</div>
@endif

<p class="footer">Documento generado automáticamente por {{ $empresa->razon_social ?? 'SIS KYRO DASAM' }} — {{ now()->format('Y') }}</p>
</body>
</html>

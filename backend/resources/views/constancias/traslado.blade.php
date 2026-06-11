<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Constancia de Traslado</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; background: #fff; padding: 20px; }
    h1 { font-size: 16px; font-weight: bold; color: #1e3a8a; text-align: center; margin-bottom: 4px; }
    .subtitulo { text-align: center; font-size: 10px; color: #64748b; margin-bottom: 16px; }
    .empresa { text-align: center; font-size: 13px; font-weight: bold; margin-bottom: 2px; }
    .separador { border-top: 2px solid #1e3a8a; margin: 12px 0; }
    .grid2 { display: table; width: 100%; margin-bottom: 10px; }
    .grid2 .col { display: table-cell; width: 50%; vertical-align: top; padding: 0 8px 0 0; }
    .campo { margin-bottom: 5px; }
    .campo strong { display: inline-block; min-width: 110px; color: #475569; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background: #1e3a8a; color: #fff; padding: 5px 8px; text-align: left; font-size: 10px; }
    td { padding: 4px 8px; border-bottom: 1px solid #e2e8f0; font-size: 10px; }
    tr:nth-child(even) td { background: #f8fafc; }
    .estado-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; }
    .estado-CONFIRMADO { background: #dcfce7; color: #166534; }
    .estado-PENDIENTE  { background: #dbeafe; color: #1e40af; }
    .estado-RECHAZADO  { background: #fee2e2; color: #991b1b; }
    .firmas { display: table; width: 100%; margin-top: 30px; }
    .firma-col { display: table-cell; width: 50%; text-align: center; padding-top: 40px; border-top: 1px solid #94a3b8; }
    .footer { text-align: center; font-size: 9px; color: #94a3b8; margin-top: 20px; }
</style>
</head>
<body>

<p class="empresa">{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</p>
<h1>CONSTANCIA DE TRASLADO</h1>
<p class="subtitulo">
    @if($tipo === 'chips')
        Traslado de Chips #{{ str_pad($traslado->id, 5, '0', STR_PAD_LEFT) }}
    @elseif(!empty($traslado->codigo_lote))
        Lote: {{ $traslado->codigo_lote }}
    @else
        Traslado #{{ str_pad($traslado->id, 5, '0', STR_PAD_LEFT) }}
    @endif
    &nbsp;|&nbsp; Generado: {{ now()->timezone('America/Lima')->format('d/m/Y H:i') }}
</p>

<div class="separador"></div>

<div class="grid2">
    <div class="col">
        <div class="campo"><strong>Tienda Origen:</strong> {{ $traslado->tienda_origen }} @if($traslado->tienda_origen_nombre)({{ $traslado->tienda_origen_nombre }})@endif</div>
        <div class="campo"><strong>Tienda Destino:</strong> {{ $traslado->tienda_destino }} @if($traslado->tienda_destino_nombre)({{ $traslado->tienda_destino_nombre }})@endif</div>
        <div class="campo"><strong>Estado:</strong>
            <span class="estado-badge estado-{{ $traslado->estado }}">{{ $traslado->estado }}</span>
        </div>
    </div>
    <div class="col">
        <div class="campo"><strong>Enviado por:</strong> {{ $traslado->enviado_por_nombres ?? '—' }} @if($traslado->enviado_por_dni)(DNI: {{ $traslado->enviado_por_dni }})@endif</div>
        <div class="campo"><strong>Confirmado por:</strong> {{ $traslado->confirmado_por_nombres ?? '—' }} @if($traslado->confirmado_por_dni)(DNI: {{ $traslado->confirmado_por_dni }})@endif</div>
        @if($traslado->fecha_confirmacion)
        <div class="campo"><strong>Fecha Confirmación:</strong> {{ \Carbon\Carbon::parse($traslado->fecha_confirmacion)->format('d/m/Y H:i') }}</div>
        @endif
    </div>
</div>

@if($traslado->notas)
<div class="campo"><strong>Notas:</strong> {{ $traslado->notas }}</div>
@endif
@if($traslado->observacion_recepcion)
<div class="campo"><strong>Obs. Recepción:</strong> {{ $traslado->observacion_recepcion }}</div>
@endif

<div class="separador"></div>

@if($tipo === 'chips')
<table>
    <thead><tr><th>Descripción</th><th>Cantidad</th><th>Tipo</th></tr></thead>
    <tbody>
        <tr>
            <td>Chips de {{ $traslado->tienda_origen }}</td>
            <td>{{ $traslado->cantidad }}</td>
            <td>FÍSICO</td>
        </tr>
    </tbody>
</table>
@else
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Producto</th>
            <th>IMEI / Serie</th>
            <th>Tipo</th>
            <th>Cantidad</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $idx => $item)
        <tr>
            <td>{{ $idx + 1 }}</td>
            <td>{{ $item->producto_nombre ?? ($item->producto_nombre_snap ?? '—') }}</td>
            <td>{{ $item->imei_serial ?? ($item->imei_serial_snap ?? '—') }}</td>
            <td>{{ $item->tipo_producto ?? '—' }}</td>
            <td>{{ $item->cantidad }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

<div class="firmas">
    <div class="firma-col">
        FIRMA: {{ $traslado->enviado_por_nombres ?? '_______________' }}<br>
        <small>Responsable de Envío</small>
    </div>
    <div class="firma-col">
        FIRMA: {{ $traslado->confirmado_por_nombres ?? '_______________' }}<br>
        <small>Responsable de Recepción</small>
    </div>
</div>

<p class="footer">Documento generado automáticamente por {{ $empresa->razon_social ?? 'SIS KYRO DASAM' }} — {{ now()->format('Y') }}</p>
</body>
</html>

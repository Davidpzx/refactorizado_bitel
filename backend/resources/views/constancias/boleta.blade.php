<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Liquidación de Pago</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; background: #fff; padding: 30px 40px; }
    .empresa { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 2px; }
    h1 { font-size: 17px; font-weight: bold; color: #1e3a8a; text-align: center; margin-bottom: 4px; }
    .subtitulo { text-align: center; font-size: 10px; color: #64748b; margin-bottom: 20px; }
    .separador { border-top: 2px solid #1e3a8a; margin: 14px 0; }
    .seccion { font-weight: bold; color: #f59e0b; border-bottom: 2px solid #f59e0b; padding-bottom: 4px; margin: 14px 0 6px; font-size: 11px; text-transform: uppercase; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    td { padding: 6px 4px; font-size: 11px; border-bottom: 1px solid #e2e8f0; }
    td:last-child { text-align: right; font-weight: 600; font-family: 'Courier New', monospace; font-size: 13px; }
    .descuento { color: #ef4444; }
    .total-box { background: #0f172a; color: #fff; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; margin: 16px 0; border-radius: 6px; }
    .total-box .label { font-size: 12px; font-weight: 600; text-transform: uppercase; color: #94a3b8; }
    .total-box .amount { font-size: 22px; font-weight: 700; color: #fde68a; font-family: 'Courier New', monospace; }
    .legal { font-size: 9.5px; color: #64748b; background: #f8fafc; padding: 12px; border-radius: 4px; line-height: 1.6; margin: 12px 0 40px; text-align: justify; }
    .firmas { display: table; width: 100%; margin-top: 50px; }
    .firma-col { display: table-cell; width: 50%; text-align: center; border-top: 2px solid #0f172a; padding-top: 8px; font-weight: bold; font-size: 11px; }
    .firma-col span { font-weight: 400; color: #64748b; display: block; margin-top: 3px; font-size: 10px; }
    .campo { margin-bottom: 6px; display: flex; }
    .campo .label { min-width: 160px; color: #64748b; }
</style>
</head>
<body>

<p class="empresa">{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</p>
<h1>LIQUIDACIÓN DE PAGO</h1>
<p class="subtitulo">
    Periodo: {{ \Carbon\Carbon::parse($pago->fecha_inicio)->format('d/m/Y') }}
    — {{ \Carbon\Carbon::parse($pago->fecha_fin)->format('d/m/Y') }}
</p>

<div class="separador"></div>

<div class="campo"><span class="label">Nombres y Apellidos:</span><span>{{ $agente->nombres }}</span></div>
<div class="campo"><span class="label">Documento de Identidad:</span><span>{{ $agente->dni }}</span></div>
<div class="campo"><span class="label">Sede Comercial:</span><span>{{ $agente->tienda_base ?? ($agente->tienda_id ?? '—') }}</span></div>
<div class="campo"><span class="label">Fecha de Emisión:</span><span>{{ \Carbon\Carbon::parse($pago->fecha_pago)->format('d/m/Y H:i') }}</span></div>

<div class="separador"></div>

<div class="seccion">Ingresos (+)</div>
<table>
    <tr><td>Sueldo Base Asignado</td><td>S/ {{ number_format($pago->sueldo_base, 2) }}</td></tr>
    <tr><td>Comisiones / Horas Extras / Bonos</td><td>S/ {{ number_format($pago->bonos_comisiones, 2) }}</td></tr>
</table>

<div class="seccion">Descuentos (-)</div>
<table>
    <tr><td>Tardanzas / Faltas / Deudas</td><td class="descuento">- S/ {{ number_format($pago->descuento_tardanza, 2) }}</td></tr>
    <tr><td>Adelantos de Efectivo</td><td class="descuento">- S/ {{ number_format($pago->descuento_adelantos, 2) }}</td></tr>
</table>

<div class="total-box">
    <div class="label">Neto a Pagar</div>
    <div class="amount">S/ {{ number_format($pago->total_pagado, 2) }}</div>
</div>

<div class="legal">
    Yo, <strong>{{ $agente->nombres }}</strong>, declaro haber recibido de manera conforme la cantidad
    descrita en este documento, correspondiente al pago total de mis labores en el periodo indicado.
    Al firmar este recibo, confirmo que la empresa no me adeuda ningún saldo pendiente por los conceptos aquí detallados.
</div>

<div class="firmas">
    <div class="firma-col">
        {{ $agente->nombres }}
        <span>DNI: {{ $agente->dni }}</span>
    </div>
    <div class="firma-col">
        {{ $empresa->gerente_general ?? 'Gerente General' }}
        <span>{{ $empresa->razon_social ?? 'Administración' }}</span>
    </div>
</div>
</body>
</html>

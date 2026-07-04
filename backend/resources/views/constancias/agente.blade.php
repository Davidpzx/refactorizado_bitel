<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Certificado de Agente</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, sans-serif; font-size: 11px; color: #1e293b; background: #fff; padding: 30px 40px; }
    h1 { font-size: 18px; font-weight: bold; color: #1e3a8a; text-align: center; margin-bottom: 4px; }
    .empresa { text-align: center; font-size: 14px; font-weight: bold; margin-bottom: 2px; }
    .subtitulo { text-align: center; font-size: 10px; color: #64748b; margin-bottom: 20px; }
    .separador { border-top: 2px solid #1e3a8a; margin: 14px 0; }
    .campo { margin-bottom: 8px; display: flex; }
    .campo .label { min-width: 160px; color: #475569; font-weight: bold; }
    .campo .valor { flex: 1; }
    .estado-ACTIVO   { color: #166534; font-weight: bold; }
    .estado-INACTIVO { color: #991b1b; font-weight: bold; }
    .footer { text-align: center; font-size: 9px; color: #94a3b8; margin-top: 40px; }
    .firma-area { margin-top: 50px; border-top: 1px solid #94a3b8; padding-top: 8px; text-align: center; }
</style>
</head>
<body>

<p class="empresa">{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</p>
<h1>CERTIFICADO DE AGENTE</h1>
<p class="subtitulo">Generado: {{ now()->timezone('America/Lima')->format('d/m/Y H:i') }}</p>

<div class="separador"></div>

<div class="campo"><span class="label">Nombres Completos:</span><span class="valor">{{ $agente->nombres }}</span></div>
<div class="campo"><span class="label">DNI:</span><span class="valor">{{ $agente->dni }}</span></div>
<div class="campo"><span class="label">Tienda:</span><span class="valor">{{ $agente->tienda_base }} @if($agente->tienda_nombre)({{ $agente->tienda_nombre }})@endif</span></div>
<div class="campo"><span class="label">Estado:</span><span class="valor estado-{{ $agente->estado }}">{{ $agente->estado }}</span></div>
@if($agente->fecha_ingreso)
<div class="campo"><span class="label">Fecha Ingreso:</span><span class="valor">{{ \Carbon\Carbon::parse($agente->fecha_ingreso)->format('d/m/Y') }}</span></div>
@endif
@if($agente->telefono)
<div class="campo"><span class="label">Teléfono:</span><span class="valor">{{ $agente->telefono }}</span></div>
@endif
@if($agente->correo)
<div class="campo"><span class="label">Correo:</span><span class="valor">{{ $agente->correo }}</span></div>
@endif
@if($agente->es_gerencia)
<div class="campo"><span class="label">Cargo:</span><span class="valor">Gerencia / Responsable de tienda</span></div>
@endif

<div class="separador"></div>

<p style="text-align:center; margin-top: 20px; font-size: 11px; color: #475569;">
    Se certifica que el agente identificado en el presente documento forma parte del equipo de ventas de
    <strong>{{ $empresa->razon_social ?? 'SIS KYRO DASAM' }}</strong>.
</p>

<div class="firma-area">
    <p>___________________________</p>
    <p>Firma Autorizada</p>
    <p>{{ $empresa->razon_social ?? 'Administración' }}</p>
</div>

<p class="footer">Documento generado automáticamente — {{ now()->format('Y') }}</p>
</body>
</html>

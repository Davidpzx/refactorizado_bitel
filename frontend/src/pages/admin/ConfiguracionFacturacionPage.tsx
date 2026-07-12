import { useState, type ReactNode } from 'react'
import {
  Receipt, Buildings, Certificate, Key, Info, ShieldCheck, Rocket, UploadSimple,
  CheckCircle, WarningCircle, CaretDown, ListNumbers, FloppyDisk, LockKey, ImageSquare,
} from '@phosphor-icons/react'
import { PageHeader } from '../../components/PageHeader'
import { Button } from '../../components/ui/button'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { useFacturacionConfigs, useGuardarFacturacionConfig, useConfigurarSunat, useSyncLogoFacturacion } from '../../hooks/useFacturacionConfig'
import { apiErrorData } from '../../lib/httpError'
import type { FacturacionConfig } from '../../services/facturacionConfig.api'

/** El backend responde `{ok:false, msg}` para errores de negocio (400/422/500) del wizard SUNAT. */
function sunatErrorMessage(e: unknown): string {
  const data = (e as { response?: { data?: { msg?: string; message?: string; errors?: Record<string, string[]> } } })
    ?.response?.data
  if (data?.msg) return data.msg
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  if (data?.message) return data.message
  return 'No se pudo activar la facturación real. Intenta de nuevo.'
}

function CardSectionTitle({ icon: Icon, children }: { icon: typeof Info; children: ReactNode }) {
  return (
    <p className="mb-3 flex items-center gap-2 text-sm font-semibold text-kyro-text">
      <Icon size={17} weight="bold" className="text-kyro-indigo" />
      {children}
    </p>
  )
}

interface WizardProps {
  tiendaSeleccionada: string
  tiendaNombre: string
  own: FacturacionConfig | null
  global: FacturacionConfig | null
}

function FacturacionWizard({ tiendaSeleccionada, tiendaNombre, own, global: globalCfg }: WizardProps) {
  const effective = own ?? globalCfg ?? null
  const confirmDialog = useConfirmDialog()
  const guardar = useGuardarFacturacionConfig()
  const configurarSunat = useConfigurarSunat()
  const syncLogo = useSyncLogoFacturacion()

  /* ── Datos del emisor y series ─────────────────────────────────────────── */
  const [emisor, setEmisor] = useState({
    ruc: effective?.ruc ?? '',
    razon_social_emisor: effective?.razon_social_emisor ?? '',
    serie_boleta: effective?.serie_boleta ?? '',
    serie_factura: effective?.serie_factura ?? '',
    serie_nota_credito: effective?.serie_nota_credito ?? '',
    igv_porcentaje: effective ? Number(effective.igv_porcentaje) : 18,
  })
  const [emisorMsg, setEmisorMsg] = useState<{ tipo: 'success' | 'error'; texto: string } | null>(null)

  const guardarEmisor = async () => {
    setEmisorMsg(null)
    const esNuevaTienda = !own && tiendaSeleccionada !== ''
    if (esNuevaTienda) {
      const ok = await confirmDialog({
        title: `¿Crear configuración propia para ${tiendaNombre}?`,
        description: 'Ahora mismo esta tienda usa la configuración global. Al guardar se creará una configuración independiente solo para ella.',
        intent: 'indigo',
        icon: Buildings,
        confirmLabel: 'Crear configuración',
      })
      if (!ok) return
    }
    guardar.mutate(
      {
        id: own?.id ?? null,
        payload: {
          ...emisor,
          igv_porcentaje: Number(emisor.igv_porcentaje),
          tienda_id: tiendaSeleccionada || null,
          ...(esNuevaTienda
            ? { company_id: effective?.company_id ?? 1, branch_id: effective?.branch_id ?? 1 }
            : {}),
        },
      },
      {
        onSuccess: () => setEmisorMsg({ tipo: 'success', texto: 'Datos guardados correctamente.' }),
        onError: (e) => {
          const d = apiErrorData(e)
          const primerError = d.errors ? Object.values(d.errors).flat()[0] : undefined
          setEmisorMsg({ tipo: 'error', texto: primerError ?? d.message ?? 'No se pudo guardar.' })
        },
      },
    )
  }

  /* ── Activar facturación real ──────────────────────────────────────────── */
  const [certificado, setCertificado] = useState<File | null>(null)
  const [certificadoPassword, setCertificadoPassword] = useState('')
  const [usuarioSol, setUsuarioSol] = useState('')
  const [claveSol, setClaveSol] = useState('')
  const [activacionMsg, setActivacionMsg] = useState<{ tipo: 'success' | 'error'; texto: string } | null>(null)

  const puedeActivar = certificado && certificadoPassword.trim() && usuarioSol.trim() && claveSol.trim()

  const activarProduccion = async () => {
    if (!puedeActivar || !certificado) return
    setActivacionMsg(null)
    const ok = await confirmDialog({
      title: `¿Activar facturación real para ${tiendaSeleccionada ? tiendaNombre : 'la configuración global'}?`,
      description: 'Se subirá el certificado digital, se guardarán las credenciales SOL y el sistema quedará en MODO PRODUCCIÓN. SUNAT hace la validación definitiva al emitir el primer comprobante real.',
      intent: 'gold',
      icon: Rocket,
      confirmLabel: 'Activar producción',
    })
    if (!ok) return

    const formData = new FormData()
    formData.append('tienda_id', tiendaSeleccionada)
    formData.append('certificado', certificado)
    formData.append('certificado_password', certificadoPassword)
    formData.append('usuario_sol', usuarioSol)
    formData.append('clave_sol', claveSol)

    configurarSunat.mutate(formData, {
      onSuccess: (r) => {
        setActivacionMsg({ tipo: 'success', texto: r.msg })
        setCertificadoPassword('')
        setClaveSol('')
      },
      onError: (e) => setActivacionMsg({ tipo: 'error', texto: sunatErrorMessage(e) }),
    })
  }

  /* ── Sincronizar logo con la API de facturación ─────────────────────────── */
  const [syncLogoMsg, setSyncLogoMsg] = useState<{ tipo: 'success' | 'error'; texto: string } | null>(null)

  const sincronizarLogo = async () => {
    setSyncLogoMsg(null)
    if (!claveSol.trim()) {
      setSyncLogoMsg({ tipo: 'error', texto: 'Ingresa la Clave SOL arriba para poder sincronizar el logo.' })
      return
    }
    const ok = await confirmDialog({
      title: '¿Sincronizar el logo con el servicio de facturación?',
      description: 'Se enviará el logo del Perfil de Empresa a la API de facturación. Nota: el PDF oficial del comprobante trae su propio logo fijo — esto solo actualiza los datos de la empresa, no cambia el PDF.',
      intent: 'indigo',
      icon: ImageSquare,
      confirmLabel: 'Sincronizar',
    })
    if (!ok) return

    syncLogo.mutate(
      { claveSol: claveSol.trim(), tiendaId: tiendaSeleccionada || null },
      {
        onSuccess: (r) => setSyncLogoMsg({ tipo: 'success', texto: r.msg }),
        onError: (e) => setSyncLogoMsg({ tipo: 'error', texto: sunatErrorMessage(e) }),
      },
    )
  }

  /* ── Configuración técnica avanzada ─────────────────────────────────────── */
  const [avanzadoAbierto, setAvanzadoAbierto] = useState(false)
  const [avanzado, setAvanzado] = useState({
    base_url: effective?.base_url ?? '',
    company_id: effective?.company_id ?? 1,
    branch_id: effective?.branch_id ?? 1,
    modo: effective?.modo ?? 'beta',
    activo: effective?.activo ?? true,
    api_token: '',
  })
  const [avanzadoMsg, setAvanzadoMsg] = useState<{ tipo: 'success' | 'error'; texto: string } | null>(null)

  const guardarAvanzado = () => {
    setAvanzadoMsg(null)
    guardar.mutate(
      {
        id: own?.id ?? null,
        payload: {
          base_url: avanzado.base_url || null,
          company_id: Number(avanzado.company_id),
          branch_id: Number(avanzado.branch_id),
          modo: avanzado.modo,
          activo: avanzado.activo,
          tienda_id: tiendaSeleccionada || null,
          ...(avanzado.api_token.trim() ? { api_token: avanzado.api_token.trim() } : {}),
        },
      },
      {
        onSuccess: () => {
          setAvanzadoMsg({ tipo: 'success', texto: 'Configuración técnica guardada.' })
          setAvanzado((a) => ({ ...a, api_token: '' }))
        },
        onError: (e) => {
          const d = apiErrorData(e)
          const primerError = d.errors ? Object.values(d.errors).flat()[0] : undefined
          setAvanzadoMsg({ tipo: 'error', texto: primerError ?? d.message ?? 'No se pudo guardar.' })
        },
      },
    )
  }

  return (
    <div className="space-y-5">
      {/* Estado actual */}
      <div className="kyro-card rounded-[18px] p-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-kyro-indigo/30 bg-kyro-indigo/10 text-kyro-indigo">
              <ShieldCheck size={20} weight="bold" />
            </span>
            <div>
              <p className="text-sm font-semibold text-kyro-text">Estado de facturación electrónica</p>
              <p className="text-xs text-kyro-muted">
                {tiendaSeleccionada ? tiendaNombre : 'Configuración global (todas las tiendas)'}
                {!own && tiendaSeleccionada !== '' && ' — usando la config. global'}
              </p>
            </div>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={effective?.modo === 'produccion' ? 'success' : 'warning'} className="gap-1">
              {effective?.modo === 'produccion' ? <CheckCircle size={11} weight="bold" /> : <WarningCircle size={11} weight="bold" />}
              {effective?.modo === 'produccion' ? 'MODO PRODUCCIÓN' : 'MODO PRUEBA (beta)'}
            </Badge>
            <Badge variant={effective?.esta_operativa ? 'success' : 'outline'}>
              {effective?.esta_operativa ? 'Operativa' : 'No operativa'}
            </Badge>
          </div>
        </div>
      </div>

      {/* Educativa */}
      <div className="kyro-card rounded-[18px] p-5">
        <CardSectionTitle icon={Info}>¿Qué necesito para emitir facturas reales?</CardSectionTitle>
        <ul className="space-y-2.5 text-sm text-kyro-body">
          <li className="flex gap-2.5">
            <Certificate size={16} weight="bold" className="mt-0.5 shrink-0 text-kyro-indigo" />
            <span><strong className="text-kyro-text">Certificado digital</strong> (.pfx, .p12 o .pem) entregado por tu proveedor de firma electrónica, junto con su contraseña.</span>
          </li>
          <li className="flex gap-2.5">
            <Key size={16} weight="bold" className="mt-0.5 shrink-0 text-kyro-indigo" />
            <span><strong className="text-kyro-text">Usuario y clave SOL</strong> — las mismas credenciales con las que entras a SUNAT Operaciones en Línea.</span>
          </li>
          <li className="flex gap-2.5">
            <Buildings size={16} weight="bold" className="mt-0.5 shrink-0 text-kyro-indigo" />
            <span><strong className="text-kyro-text">RUC y razón social</strong> del emisor, guardados en la sección de abajo.</span>
          </li>
        </ul>
      </div>

      {/* Activar facturación real */}
      <div className="kyro-card rounded-[18px] p-5">
        <div className="mb-1 h-px w-full bg-gradient-to-r from-kyro-indigo/60 to-transparent" aria-hidden />
        <CardSectionTitle icon={LockKey}>Activar Facturación Real</CardSectionTitle>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Certificado digital (.pfx, .p12 o .pem)
            <input
              type="file"
              accept=".pem,.pfx,.p12"
              onChange={(e) => setCertificado(e.target.files?.[0] ?? null)}
              className="mt-1 h-10 rounded-[10px] border border-kyro-border bg-transparent px-2 py-1.5 text-xs text-kyro-body file:mr-3 file:rounded-lg file:border-0 file:bg-kyro-indigo file:px-2.5 file:py-1 file:text-xs file:font-bold file:text-white"
            />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Contraseña del certificado
            <Input type="password" value={certificadoPassword} onChange={(e) => setCertificadoPassword(e.target.value)} autoComplete="new-password" />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Usuario SOL
            <Input value={usuarioSol} onChange={(e) => setUsuarioSol(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Clave SOL
            <Input type="password" value={claveSol} onChange={(e) => setClaveSol(e.target.value)} autoComplete="new-password" />
          </label>
        </div>

        {activacionMsg && (
          <p className={`mt-3 flex items-start gap-2 text-xs ${activacionMsg.tipo === 'success' ? 'text-kyro-success' : 'text-kyro-danger'}`}>
            {activacionMsg.tipo === 'success' ? <CheckCircle size={14} weight="bold" className="mt-0.5 shrink-0" /> : <WarningCircle size={14} weight="bold" className="mt-0.5 shrink-0" />}
            {activacionMsg.texto}
          </p>
        )}

        <div className="mt-4 flex flex-wrap items-center gap-3">
          <Button
            variant="gold"
            className="gap-2"
            disabled={!puedeActivar || configurarSunat.isPending}
            onClick={activarProduccion}
          >
            <UploadSimple size={15} weight="bold" />
            {configurarSunat.isPending ? 'Activando…' : 'Activar producción'}
          </Button>
          <Button
            variant="glassIndigo"
            className="gap-2"
            disabled={syncLogo.isPending}
            onClick={sincronizarLogo}
          >
            <ImageSquare size={15} weight="bold" />
            {syncLogo.isPending ? 'Sincronizando…' : 'Sincronizar logo con facturación'}
          </Button>
        </div>

        {syncLogoMsg && (
          <p className={`mt-3 flex items-start gap-2 text-xs ${syncLogoMsg.tipo === 'success' ? 'text-kyro-success' : 'text-kyro-danger'}`}>
            {syncLogoMsg.tipo === 'success' ? <CheckCircle size={14} weight="bold" className="mt-0.5 shrink-0" /> : <WarningCircle size={14} weight="bold" className="mt-0.5 shrink-0" />}
            {syncLogoMsg.texto}
          </p>
        )}

        <p className="mt-2 text-[11px] text-kyro-muted">
          El PDF oficial del comprobante trae su propio logo fijo — sincronizar solo actualiza los datos de la empresa en el servicio de facturación.
        </p>
      </div>

      {/* Datos del emisor y series */}
      <div className="kyro-card rounded-[18px] p-5">
        <CardSectionTitle icon={ListNumbers}>Datos del emisor y series</CardSectionTitle>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            RUC
            <Input value={emisor.ruc} maxLength={20} onChange={(e) => setEmisor((f) => ({ ...f, ruc: e.target.value }))} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Razón social del emisor
            <Input value={emisor.razon_social_emisor} maxLength={200} onChange={(e) => setEmisor((f) => ({ ...f, razon_social_emisor: e.target.value }))} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Serie de boletas
            <Input value={emisor.serie_boleta} maxLength={10} onChange={(e) => setEmisor((f) => ({ ...f, serie_boleta: e.target.value }))} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Serie de facturas
            <Input value={emisor.serie_factura} maxLength={10} onChange={(e) => setEmisor((f) => ({ ...f, serie_factura: e.target.value }))} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            Serie de notas de crédito
            <Input value={emisor.serie_nota_credito} maxLength={10} onChange={(e) => setEmisor((f) => ({ ...f, serie_nota_credito: e.target.value }))} />
          </label>
          <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
            IGV (%)
            <Input type="number" min={0} max={100} step="0.01" value={emisor.igv_porcentaje}
              onChange={(e) => setEmisor((f) => ({ ...f, igv_porcentaje: Number(e.target.value) }))} />
          </label>
        </div>

        {emisorMsg && (
          <p className={`mt-3 text-xs ${emisorMsg.tipo === 'success' ? 'text-kyro-success' : 'text-kyro-danger'}`}>{emisorMsg.texto}</p>
        )}

        <Button variant="default" className="mt-4 gap-2" disabled={guardar.isPending} onClick={guardarEmisor}>
          <FloppyDisk size={15} weight="bold" />
          {guardar.isPending ? 'Guardando…' : 'Guardar datos del emisor'}
        </Button>
      </div>

      {/* Avanzado */}
      <div className="kyro-card rounded-[18px] p-5">
        <button
          type="button"
          onClick={() => setAvanzadoAbierto((v) => !v)}
          className="flex w-full items-center justify-between text-left"
        >
          <span className="flex items-center gap-2 text-sm font-semibold text-kyro-text">
            <Buildings size={17} weight="bold" className="text-kyro-muted" />
            Configuración técnica (avanzada)
          </span>
          <CaretDown size={16} className={`text-kyro-muted transition-transform ${avanzadoAbierto ? 'rotate-180' : ''}`} />
        </button>

        {avanzadoAbierto && (
          <div className="mt-4 space-y-4">
            <p className="rounded-lg border border-kyro-danger/30 bg-kyro-danger/10 p-3 text-xs text-kyro-danger">
              Estos datos suelen configurarlos el equipo técnico. No los modifiques si no sabes lo que hacen.
            </p>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
                URL base de la API
                <Input value={avanzado.base_url} onChange={(e) => setAvanzado((a) => ({ ...a, base_url: e.target.value }))} />
              </label>
              <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
                Modo
                <Select value={avanzado.modo} onChange={(e) => setAvanzado((a) => ({ ...a, modo: e.target.value as 'beta' | 'produccion' }))}>
                  <option value="beta">Beta (pruebas)</option>
                  <option value="produccion">Producción</option>
                </Select>
              </label>
              <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
                Company ID
                <Input type="number" min={1} value={avanzado.company_id} onChange={(e) => setAvanzado((a) => ({ ...a, company_id: Number(e.target.value) }))} />
              </label>
              <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
                Branch ID
                <Input type="number" min={1} value={avanzado.branch_id} onChange={(e) => setAvanzado((a) => ({ ...a, branch_id: Number(e.target.value) }))} />
              </label>
              <label className="flex flex-col gap-1 text-xs font-semibold text-kyro-muted">
                API Token {effective?.tiene_api_token && '(configurado — vacío = no cambiar)'}
                <Input type="password" value={avanzado.api_token} onChange={(e) => setAvanzado((a) => ({ ...a, api_token: e.target.value }))} autoComplete="new-password" />
              </label>
              <label className="flex items-center gap-2 text-xs font-semibold text-kyro-muted">
                <input type="checkbox" checked={avanzado.activo} onChange={(e) => setAvanzado((a) => ({ ...a, activo: e.target.checked }))} />
                Configuración activa
              </label>
            </div>

            {avanzadoMsg && (
              <p className={`text-xs ${avanzadoMsg.tipo === 'success' ? 'text-kyro-success' : 'text-kyro-danger'}`}>{avanzadoMsg.texto}</p>
            )}

            <Button variant="outline" className="gap-2" disabled={guardar.isPending} onClick={guardarAvanzado}>
              <FloppyDisk size={15} weight="bold" />
              Guardar configuración técnica
            </Button>
          </div>
        )}
      </div>
    </div>
  )
}

export function ConfiguracionFacturacionPage() {
  const { tiendas, isLoading: cargandoTiendas } = useTiendasSelect()
  const { data: configs, isLoading: cargandoConfigs } = useFacturacionConfigs()
  const [tiendaSeleccionada, setTiendaSeleccionada] = useState('')

  const isLoading = cargandoTiendas || cargandoConfigs
  const globalCfg = configs?.find((c) => c.es_global) ?? null
  const own = tiendaSeleccionada
    ? configs?.find((c) => c.tienda_id === tiendaSeleccionada) ?? null
    : globalCfg
  const tiendaNombre = tiendas.find((t) => t.codigo === tiendaSeleccionada)?.nombre ?? tiendaSeleccionada

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader
        title="Facturación Electrónica"
        subtitle="Configura el emisor, sube tu certificado digital y activa la facturación real — sin pasos técnicos."
        Icon={Receipt}
      />

      <div className="kyro-card flex flex-wrap items-center gap-3 rounded-[18px] p-5">
        <Buildings size={18} className="text-kyro-muted" />
        <label className="text-xs font-semibold uppercase tracking-wide text-kyro-muted">Configurando</label>
        <Select value={tiendaSeleccionada} onChange={(e) => setTiendaSeleccionada(e.target.value)} className="w-72">
          <option value="">Configuración Global (todas las tiendas)</option>
          {tiendas.map((t) => (
            <option key={t.codigo} value={t.codigo}>{t.codigo} — {t.nombre}</option>
          ))}
        </Select>
        {tiendaSeleccionada && !configs?.find((c) => c.tienda_id === tiendaSeleccionada) && (
          <Badge variant="indigo">Usando la config. global</Badge>
        )}
        {tiendaSeleccionada && configs?.find((c) => c.tienda_id === tiendaSeleccionada) && (
          <Badge variant="indigo">Config. propia de esta tienda</Badge>
        )}
      </div>

      {isLoading ? (
        <div className="flex h-48 items-center justify-center text-sm text-kyro-muted">Cargando…</div>
      ) : (
        <FacturacionWizard
          key={tiendaSeleccionada}
          tiendaSeleccionada={tiendaSeleccionada}
          tiendaNombre={tiendaNombre}
          own={own}
          global={globalCfg}
        />
      )}
    </div>
  )
}

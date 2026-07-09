import { Fragment, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowsClockwise as RefreshCw,
  ArrowCounterClockwise as RotateCcw,
  Receipt,
  FilePdf,
  FileCode,
  FileZip,
  FileMinus,
  Prohibit,
  WhatsappLogo,
  Eye,
  Clock,
  SealCheck,
  WarningCircle,
  PaperPlaneTilt as Send,
  X,
} from '@phosphor-icons/react'
import { comprobantesColaApi, type ComprobanteCola, type ComprobanteColaEstado, type ComprobanteColaTipo } from '../../services/comprobantesCola.api'
import { useTiendasSelect } from '../../hooks/useTiendasSelect'
import { Button } from '../../components/ui/button'
import { Badge, type BadgeVariant } from '../../components/ui/badge'
import { ActionIconButton, TableActions } from '../../components/ui/ActionIconButton'
import { SegmentedToggle } from '../../components/ui/SegmentedToggle'
import { Dialog } from '../../components/ui/dialog'
import { Select } from '../../components/ui/select'
import { Input } from '../../components/ui/input'
import { Label } from '../../components/ui/label'
import { PageHeader } from '../../components/PageHeader'
import { ListToolbar } from '../../components/ListToolbar'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

// ── Constantes ────────────────────────────────────────────────────────────────

const TIPO_LABEL: Record<ComprobanteColaTipo, string> = {
  BOLETA: 'Boleta',
  FACTURA: 'Factura',
  NOTA_CREDITO: 'N. Crédito',
}

const ESTADO_META: Record<ComprobanteColaEstado, { label: string; variant: BadgeVariant; Icon: React.ComponentType<{ size?: number }> }> = {
  PENDIENTE: { label: 'Pendiente', variant: 'warning', Icon: Clock },
  ENVIADO:   { label: 'Enviando',  variant: 'default', Icon: Send },
  ACEPTADO:  { label: 'Aceptado',  variant: 'success', Icon: SealCheck },
  ERROR:     { label: 'Error',     variant: 'warning', Icon: WarningCircle },
  RECHAZADO: { label: 'Rechazado', variant: 'destructive', Icon: WarningCircle },
  ANULADO:   { label: 'Anulado',   variant: 'outline', Icon: Prohibit },
}

const ESTADOS = [
  { value: '',           label: 'Todos',     tone: 'indigo'  as const },
  { value: 'PENDIENTE',  label: 'Pendiente', tone: 'warning' as const },
  { value: 'ENVIADO',    label: 'Enviando',  tone: 'info'    as const },
  { value: 'ACEPTADO',   label: 'Aceptado',  tone: 'success' as const },
  { value: 'ERROR',      label: 'Error',     tone: 'warning' as const },
  { value: 'RECHAZADO',  label: 'Rechazado', tone: 'danger'  as const },
  { value: 'ANULADO',    label: 'Anulado',   tone: 'indigo'  as const },
]

const TIPOS = [
  { value: '',             label: 'Todos',        tone: 'indigo' as const },
  { value: 'BOLETA',       label: 'Boleta',       tone: 'info'   as const },
  { value: 'FACTURA',      label: 'Factura',      tone: 'gold'   as const },
  { value: 'NOTA_CREDITO', label: 'N. Crédito',   tone: 'warning' as const },
]

const MOTIVOS_NC = [
  { value: '01', label: '01 - Anulación de la operación' },
  { value: '02', label: '02 - Anulación por error en el RUC' },
  { value: '07', label: '07 - Devolución por ítem' },
]

const pen = (v: number | string | null | undefined, moneda = 'PEN') =>
  v != null ? `${moneda} ${Number(v).toFixed(2)}` : '—'

function EstadoBadge({ c }: { c: ComprobanteCola }) {
  const meta = ESTADO_META[c.estado] ?? ESTADO_META.RECHAZADO
  const { Icon } = meta
  const mostrarIntentos = c.estado === 'ERROR' || c.estado === 'RECHAZADO'
  return (
    <Badge variant={meta.variant} className="gap-1">
      <Icon size={11} />
      {meta.label}
      {mostrarIntentos && ` (${c.intentos}/${c.max_intentos})`}
    </Badge>
  )
}

// ── Detalle de la cola (historial de intentos/errores) ─────────────────────────

function fecha(v: string | null) {
  return v ? new Date(v).toLocaleString('es-PE') : '—'
}

function FilaDetalle({ c }: { c: ComprobanteCola }) {
  return (
    <tr className="bg-kyro-elevated/60">
      <td colSpan={8} className="px-4 py-4">
        <div className="grid grid-cols-2 gap-x-6 gap-y-2 text-xs sm:grid-cols-4">
          <div><span className="text-kyro-subtle">Intentos</span><div className="text-kyro-body">{c.intentos} / {c.max_intentos}</div></div>
          <div><span className="text-kyro-subtle">Próximo intento</span><div className="text-kyro-body">{fecha(c.proximo_intento_at)}</div></div>
          <div><span className="text-kyro-subtle">Serie-correlativo</span><div className="text-kyro-body">{c.numero_completo ?? '—'}</div></div>
          <div><span className="text-kyro-subtle">ID en API facturación</span><div className="text-kyro-body">{c.api_doc_id ?? '—'}</div></div>
          <div><span className="text-kyro-subtle">Ticket SUNAT</span><div className="text-kyro-body">{c.sunat_ticket ?? '—'}</div></div>
          <div><span className="text-kyro-subtle">Estado CDR</span><div className="text-kyro-body">{c.cdr_estado ?? '—'}</div></div>
          <div><span className="text-kyro-subtle">Creado</span><div className="text-kyro-body">{fecha(c.creado_en)}</div></div>
          <div><span className="text-kyro-subtle">Actualizado</span><div className="text-kyro-body">{fecha(c.actualizado_en)}</div></div>
          {c.ultimo_error && (
            <div className="col-span-2 sm:col-span-4">
              <span className="text-kyro-subtle">Último error</span>
              <div className="text-kyro-danger">{c.ultimo_error}</div>
            </div>
          )}
          {c.estado === 'ANULADO' && (
            <div className="col-span-2 sm:col-span-4">
              <span className="text-kyro-subtle">Anulación</span>
              <div className="text-kyro-body">{fecha(c.anulado_en)} — {c.anulado_motivo ?? 'sin motivo registrado'}</div>
            </div>
          )}
        </div>
      </td>
    </tr>
  )
}

// ── Modal Nota de Crédito ───────────────────────────────────────────────────────

function NotaCreditoDialog({
  target,
  onClose,
  onConfirm,
  isPending,
}: {
  target: ComprobanteCola
  onClose: () => void
  onConfirm: (payload: { cod_motivo: string; des_motivo: string }) => void
  isPending: boolean
}) {
  const [codMotivo, setCodMotivo] = useState('01')
  const [desMotivo, setDesMotivo] = useState('Anulacion de la operacion')

  return (
    <Dialog open onClose={onClose} title={`Emitir Nota de Crédito (${target.numero_completo ?? '—'})`} maxWidth="sm">
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label>Motivo (catálogo SUNAT 09)</Label>
          <Select value={codMotivo} onChange={(e) => setCodMotivo(e.target.value)}>
            {MOTIVOS_NC.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </Select>
        </div>
        <div className="space-y-1.5">
          <Label>Descripción del motivo</Label>
          <textarea
            rows={3}
            value={desMotivo}
            onChange={(e) => setDesMotivo(e.target.value)}
            className="flex w-full rounded-lg border border-gray-300/90 bg-white/90 px-3 py-2 text-sm text-gray-800 shadow-[0_1px_2px_rgba(15,23,42,0.04)] transition-all duration-200 hover:border-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:hover:border-white/20 dark:focus:border-indigo-400"
          />
        </div>
        <div className="flex justify-end gap-2 pt-1">
          <Button variant="ghost" onClick={onClose}>Cancelar</Button>
          <Button variant="gold" disabled={isPending} onClick={() => onConfirm({ cod_motivo: codMotivo, des_motivo: desMotivo })}>
            Confirmar
          </Button>
        </div>
      </div>
    </Dialog>
  )
}

// ── Page ──────────────────────────────────────────────────────────────────────

export function ComprobantesPage() {
  const qc = useQueryClient()
  const { tiendas } = useTiendasSelect()

  const [filtroTienda, setFiltroTienda] = useState('')
  const [filtroEstado, setFiltroEstado] = useState('')
  const [filtroTipo,   setFiltroTipo]   = useState('')
  const [desde, setDesde] = useState('')
  const [hasta, setHasta] = useState('')
  const [page, setPage] = useState(1)

  const [expandedId, setExpandedId] = useState<number | null>(null)
  const [ncTarget, setNcTarget] = useState<ComprobanteCola | null>(null)
  const [downloadingKey, setDownloadingKey] = useState<string | null>(null)
  const [actionError, setActionError] = useState('')

  const confirmDialog = useConfirmDialog()

  const { data, isLoading } = useQuery({
    queryKey: ['comprobantes-cola', filtroTienda, filtroEstado, filtroTipo, desde, hasta, page],
    queryFn: () => comprobantesColaApi.index({
      tienda_id:        filtroTienda || undefined,
      estado:            filtroEstado || undefined,
      tipo_comprobante:  filtroTipo   || undefined,
      desde:             desde || undefined,
      hasta:             hasta || undefined,
      page,
    }),
  })

  const invalidar = () => qc.invalidateQueries({ queryKey: ['comprobantes-cola'] })

  const errorDe = (e: unknown, fallback: string) => {
    const err = e as { response?: { data?: { error?: string; msg?: string } } }
    return err?.response?.data?.error ?? err?.response?.data?.msg ?? fallback
  }

  const reintentarMutation = useMutation({
    mutationFn: (id: number) => comprobantesColaApi.reintentar(id),
    onSuccess: (r) => {
      invalidar()
      if (!r.ok && r.msg) setActionError(r.msg)
      else setActionError('')
    },
    onError: (e) => setActionError(errorDe(e, 'No se pudo reintentar la emisión.')),
  })

  const notaCreditoMutation = useMutation({
    mutationFn: (vars: { id: number; cod_motivo: string; des_motivo: string }) =>
      comprobantesColaApi.notaCredito(vars.id, { cod_motivo: vars.cod_motivo, des_motivo: vars.des_motivo }),
    onSuccess: () => {
      invalidar()
      setNcTarget(null)
      setActionError('')
    },
    onError: (e) => setActionError(errorDe(e, 'No se pudo encolar la nota de crédito.')),
  })

  const anularMutation = useMutation({
    mutationFn: (id: number) => comprobantesColaApi.anular(id),
    onSuccess: () => { invalidar(); setActionError('') },
    onError: (e) => setActionError(errorDe(e, 'No se pudo anular la boleta.')),
  })

  const linkMutation = useMutation({
    mutationFn: (id: number) => comprobantesColaApi.link(id),
    onSuccess: (r) => {
      window.open(r.whatsapp_url, '_blank', 'noopener,noreferrer')
      setActionError('')
    },
    onError: (e) => setActionError(errorDe(e, 'No se pudo generar el link público.')),
  })

  async function descargar(c: ComprobanteCola, tipo: 'pdf' | 'xml' | 'cdr') {
    const key = `${c.id}-${tipo}`
    setDownloadingKey(key)
    setActionError('')
    try {
      const resp = await comprobantesColaApi.descargar(c.id, tipo)
      const ext = tipo === 'cdr' ? 'zip' : tipo
      const nombre = `${c.numero_completo ?? ('comprobante_' + c.id)}.${ext}`
      const url = URL.createObjectURL(resp.data as Blob)
      const a = document.createElement('a')
      a.href = url
      a.download = nombre
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      setActionError(errorDe(e, 'No se pudo descargar el archivo.'))
    } finally {
      setDownloadingKey(null)
    }
  }

  async function handleAnular(c: ComprobanteCola) {
    const ok = await confirmDialog({
      title: `¿Anular ${c.numero_completo ?? 'esta boleta'}?`,
      description: 'Se enviará una comunicación de baja a SUNAT. Esta acción no se puede deshacer.',
      intent: 'danger',
      icon: Prohibit,
      confirmLabel: 'Sí, anular',
    })
    if (ok) anularMutation.mutate(c.id)
  }

  const cola = data?.data ?? []
  const totalPags = data?.last_page ?? 1
  const total = data?.total ?? cola.length

  const limpiarFiltros = () => {
    setFiltroTienda(''); setFiltroEstado(''); setFiltroTipo(''); setDesde(''); setHasta(''); setPage(1)
  }

  return (
    <div className="space-y-6">
      <PageHeader title="Comprobantes Electrónicos" subtitle="Cola de emisión SUNAT: boletas, facturas y notas de crédito" Icon={Receipt}>
        <Button variant="glassInfo" onClick={invalidar}>
          <RefreshCw size={14} className="mr-2" /> Actualizar
        </Button>
      </PageHeader>

      {actionError && (
        <div className="flex items-center justify-between rounded-lg border border-kyro-danger/30 bg-kyro-danger/10 px-4 py-2.5 text-sm text-kyro-danger">
          <span>{actionError}</span>
          <button onClick={() => setActionError('')} aria-label="Cerrar" className="ml-3 shrink-0"><X size={14} /></button>
        </div>
      )}

      <ListToolbar description="Segmenta la cola por tienda, estado, tipo y rango de fechas">
        <div className="flex flex-wrap items-end gap-4">
          <div className="flex flex-col gap-1.5">
            <label className="text-sm text-kyro-muted">Tienda</label>
            <Select className="w-44" value={filtroTienda} onChange={(e) => { setFiltroTienda(e.target.value); setPage(1) }}>
              <option value="">Todas</option>
              {tiendas.map((t) => <option key={t.codigo} value={t.codigo}>{t.nombre}</option>)}
            </Select>
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm text-kyro-muted">Desde</label>
            <Input type="date" className="w-40" value={desde} onChange={(e) => { setDesde(e.target.value); setPage(1) }} />
          </div>
          <div className="flex flex-col gap-1.5">
            <label className="text-sm text-kyro-muted">Hasta</label>
            <Input type="date" className="w-40" value={hasta} onChange={(e) => { setHasta(e.target.value); setPage(1) }} />
          </div>
          <div className="flex items-center gap-2">
            <label className="shrink-0 text-sm text-kyro-muted">Estado:</label>
            <SegmentedToggle ariaLabel="Filtrar por estado" size="sm" options={ESTADOS} value={filtroEstado}
              onChange={(v) => { setFiltroEstado(v); setPage(1) }} />
          </div>
          <div className="flex items-center gap-2">
            <label className="shrink-0 text-sm text-kyro-muted">Tipo:</label>
            <SegmentedToggle ariaLabel="Filtrar por tipo" size="sm" options={TIPOS} value={filtroTipo}
              onChange={(v) => { setFiltroTipo(v); setPage(1) }} />
          </div>
          <Button variant="ghost" size="sm" onClick={limpiarFiltros}>Limpiar</Button>
          <span className="ml-auto text-sm text-kyro-muted">{total} comprobante{total !== 1 ? 's' : ''}</span>
        </div>
      </ListToolbar>

      <div className="kyro-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                <th className="kyro-table-head px-4 py-3 text-left">Fecha</th>
                <th className="kyro-table-head px-4 py-3 text-left">Tipo</th>
                <th className="kyro-table-head px-4 py-3 text-left">Número</th>
                <th className="kyro-table-head px-4 py-3 text-left">Cliente</th>
                <th className="kyro-table-head px-4 py-3 text-right">Total</th>
                <th className="kyro-table-head px-4 py-3 text-center">Estado</th>
                <th className="kyro-table-head px-4 py-3 text-left">Tienda</th>
                <th className="kyro-table-head px-4 py-3 text-center">Acciones</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-kyro-border">
              {isLoading ? (
                <tr><td colSpan={8} className="px-4 py-8 text-center text-kyro-muted">Cargando...</td></tr>
              ) : cola.length === 0 ? (
                <tr><td colSpan={8} className="px-4 py-8 text-center text-kyro-muted">No hay comprobantes en la cola.</td></tr>
              ) : cola.map((c) => {
                const cliente = c.razon_social?.trim() || c.num_doc_cliente || '—'
                const puedeDescargar = c.estado === 'ACEPTADO' && c.api_doc_id != null
                const puedeNc = c.estado === 'ACEPTADO' && (c.tipo_comprobante === 'BOLETA' || c.tipo_comprobante === 'FACTURA')
                const puedeAnular = c.estado === 'ACEPTADO' && c.tipo_comprobante === 'BOLETA' && c.api_doc_id != null
                const puedeReintentar = c.estado === 'PENDIENTE' || c.estado === 'ERROR'
                const puedeWhatsapp = c.estado === 'ACEPTADO' || c.estado === 'ANULADO'
                const expandida = expandedId === c.id

                return (
                  <Fragment key={c.id}>
                    <tr className="transition-colors hover:bg-kyro-elevated">
                      <td className="px-4 py-3 text-xs text-kyro-muted">{fecha(c.creado_en)}</td>
                      <td className="px-4 py-3 text-kyro-body">{TIPO_LABEL[c.tipo_comprobante] ?? c.tipo_comprobante}</td>
                      <td className="px-4 py-3 font-mono font-medium text-kyro-text">{c.numero_completo ?? '—'}</td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-kyro-body">{cliente}</div>
                        {c.num_doc_cliente && c.razon_social && (
                          <div className="text-xs text-kyro-subtle">{c.num_doc_cliente}</div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-right font-medium text-kyro-gold">{pen(c.total, c.moneda)}</td>
                      <td className="px-4 py-3 text-center"><EstadoBadge c={c} /></td>
                      <td className="px-4 py-3 text-kyro-body">{c.tienda_id ?? '—'}</td>
                      <td className="px-4 py-3">
                        <TableActions className="justify-center">
                          <ActionIconButton
                            tone="view"
                            label={expandida ? 'Ocultar detalle' : 'Ver detalle de la cola'}
                            icon={<Eye size={15} />}
                            onClick={() => setExpandedId(expandida ? null : c.id)}
                          />
                          {puedeReintentar && (
                            <Button
                              variant="glassWarning"
                              size="iconSm"
                              title="Reintentar ahora"
                              aria-label="Reintentar ahora"
                              disabled={reintentarMutation.isPending}
                              onClick={() => reintentarMutation.mutate(c.id)}
                            >
                              <RotateCcw size={13} />
                            </Button>
                          )}
                          {puedeDescargar && (
                            <>
                              <Button variant="glassInfo" size="iconSm" title="Descargar PDF" aria-label="Descargar PDF"
                                disabled={downloadingKey === `${c.id}-pdf`} onClick={() => descargar(c, 'pdf')}>
                                <FilePdf size={13} />
                              </Button>
                              <Button variant="glassInfo" size="iconSm" title="Descargar XML" aria-label="Descargar XML"
                                disabled={downloadingKey === `${c.id}-xml`} onClick={() => descargar(c, 'xml')}>
                                <FileCode size={13} />
                              </Button>
                              <Button variant="glassInfo" size="iconSm" title="Descargar CDR" aria-label="Descargar CDR"
                                disabled={downloadingKey === `${c.id}-cdr`} onClick={() => descargar(c, 'cdr')}>
                                <FileZip size={13} />
                              </Button>
                            </>
                          )}
                          {puedeWhatsapp && (
                            <Button variant="glassSuccess" size="iconSm" title="Enviar link por WhatsApp" aria-label="Enviar link por WhatsApp"
                              disabled={linkMutation.isPending} onClick={() => linkMutation.mutate(c.id)}>
                              <WhatsappLogo size={13} />
                            </Button>
                          )}
                          {puedeNc && (
                            <Button variant="glassWarning" size="iconSm" title="Emitir Nota de Crédito" aria-label="Emitir Nota de Crédito"
                              onClick={() => setNcTarget(c)}>
                              <FileMinus size={13} />
                            </Button>
                          )}
                          {puedeAnular && (
                            <ActionIconButton
                              tone="delete"
                              label="Anular boleta"
                              icon={<Prohibit size={15} />}
                              onClick={() => handleAnular(c)}
                            />
                          )}
                        </TableActions>
                      </td>
                    </tr>
                    {expandida && <FilaDetalle c={c} />}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>

        {totalPags > 1 && (
          <div className="flex items-center justify-between border-t border-kyro-border bg-kyro-elevated px-4 py-3 text-sm text-kyro-muted">
            <span>Página {page} de {totalPags}</span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1}>
                Anterior
              </Button>
              <Button variant="outline" size="sm" onClick={() => setPage((p) => Math.min(totalPags, p + 1))} disabled={page >= totalPags}>
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </div>

      {ncTarget && (
        <NotaCreditoDialog
          target={ncTarget}
          isPending={notaCreditoMutation.isPending}
          onClose={() => setNcTarget(null)}
          onConfirm={(payload) => notaCreditoMutation.mutate({ id: ncTarget.id, ...payload })}
        />
      )}
    </div>
  )
}

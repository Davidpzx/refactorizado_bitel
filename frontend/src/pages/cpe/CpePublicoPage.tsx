import { useMemo, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  SealCheck as CheckCircle2,
  Prohibit as Banned,
  XCircle,
  Clock,
  FilePdf,
  FileCode,
  WhatsappLogo,
  ShieldWarning,
} from '@phosphor-icons/react'
import { api } from '../../services/api'

interface ComprobantePublico {
  id: number
  tipo_comprobante: 'BOLETA' | 'FACTURA' | 'NOTA_CREDITO'
  estado: 'PENDIENTE' | 'ENVIADO' | 'ACEPTADO' | 'RECHAZADO' | 'ANULADO' | 'ERROR'
  serie: string | null
  correlativo: number | null
  numero_completo: string | null
  moneda: string
  total: number | string
  razon_social: string | null
  tipo_doc_cliente: string | null
  num_doc_cliente: string | null
  direccion_cliente: string | null
  creado_en: string | null
  anulado_en: string | null
  puede_descargar: boolean
}

const TIPO_LABEL: Record<string, string> = {
  BOLETA: 'Boleta de Venta',
  FACTURA: 'Factura',
  NOTA_CREDITO: 'Nota de Crédito',
}

const ESTADO_CONFIG: Record<string, { label: string; class: string; Icon: React.ComponentType<{ size?: number }> }> = {
  PENDIENTE: { label: 'En proceso',   class: 'bg-kyro-warning/10 text-kyro-warning', Icon: Clock },
  ENVIADO:   { label: 'Enviando...',  class: 'bg-kyro-info/10 text-kyro-info',       Icon: Clock },
  ACEPTADO:  { label: 'Aceptado por SUNAT', class: 'bg-kyro-success/10 text-kyro-success', Icon: CheckCircle2 },
  RECHAZADO: { label: 'Rechazado',    class: 'bg-kyro-danger/10 text-kyro-danger',   Icon: XCircle },
  ANULADO:   { label: 'Anulado',      class: 'bg-gray-400/10 text-gray-500',         Icon: Banned },
  ERROR:     { label: 'En proceso',   class: 'bg-kyro-warning/10 text-kyro-warning', Icon: Clock },
}

const FORMATOS: { value: string; label: string }[] = [
  { value: 'A4',    label: 'A4' },
  { value: 'a5',    label: 'A5' },
  { value: '80mm',  label: '80mm' },
  { value: 'ticket', label: 'Ticket' },
]

const apiBase = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://localhost:8000/api'

function urlDescarga(id: string, tipo: 'xml', qs: string): string {
  return `${apiBase}/v1/cpe/${id}/descargar/${tipo}?${qs}`
}

export function CpePublicoPage() {
  const { id } = useParams<{ id: string }>()
  const [params] = useSearchParams()
  const [formato, setFormato] = useState('A4')

  const exp   = params.get('exp')
  const firma = params.get('firma')
  const qs    = useMemo(() => new URLSearchParams({ exp: exp ?? '', firma: firma ?? '' }).toString(), [exp, firma])

  const { data: comprobante, isLoading, isError, error } = useQuery<ComprobantePublico>({
    queryKey: ['cpe-publico', id, exp, firma],
    queryFn: async () => {
      const r = await api.get(`/v1/cpe/${id}`, { params: { exp, firma } })
      return r.data
    },
    enabled: !!id && !!exp && !!firma,
    retry: false,
  })

  const enlaceInvalido = !exp || !firma

  return (
    <div className="public-premium-shell flex min-h-screen items-center justify-center p-4 sm:p-6">
      <div className="public-premium-card w-full max-w-md rounded-3xl p-7 sm:p-8">
        {/* Logo / título */}
        <div className="mb-6 text-center">
          <div className="relative mb-5 inline-flex h-14 w-14 items-center justify-center rounded-2xl border border-indigo-300/40 bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-[0_16px_35px_-16px_rgba(79,70,229,0.9)]">
            <span className="absolute inset-0 rounded-2xl bg-white/10" />
            <FilePdf className="h-6 w-6 text-white" weight="bold" />
          </div>
          <h1
            className="text-xl font-bold tracking-[0.14em] text-gray-900 dark:text-zinc-50"
            style={{ fontFamily: "'Orbitron', sans-serif" }}
          >
            SIS-KYRO
          </h1>
          <p className="mt-2 text-sm text-gray-500 dark:text-zinc-400">Comprobante electrónico</p>
        </div>

        {enlaceInvalido && (
          <ErrorCard mensaje="Este enlace está incompleto. Pide que te reenvíen el link desde WhatsApp." />
        )}

        {!enlaceInvalido && isLoading && (
          <div className="py-10 text-center text-sm text-kyro-muted">Verificando el enlace...</div>
        )}

        {!enlaceInvalido && isError && (
          <ErrorCard
            mensaje={
              (error as { response?: { status?: number } })?.response?.status === 403
                ? 'Este enlace ya no es válido o ha expirado.'
                : 'No se pudo cargar el comprobante.'
            }
          />
        )}

        {!enlaceInvalido && comprobante && (
          <div className="space-y-5">
            {(() => {
              const cfg = ESTADO_CONFIG[comprobante.estado] ?? ESTADO_CONFIG.PENDIENTE
              const { Icon } = cfg
              return (
                <div className="flex items-center justify-center">
                  <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ${cfg.class}`}>
                    <Icon size={13} /> {cfg.label}
                  </span>
                </div>
              )
            })()}

            <div className="text-center">
              <p className="text-xs uppercase tracking-wide text-kyro-muted">
                {TIPO_LABEL[comprobante.tipo_comprobante] ?? comprobante.tipo_comprobante}
              </p>
              <p className="mt-1 font-mono text-2xl font-bold text-kyro-text">
                {comprobante.numero_completo ?? 'Pendiente de numeración'}
              </p>
            </div>

            <div className="rounded-2xl border border-kyro-border bg-kyro-elevated/60 p-4 text-sm">
              <div className="flex justify-between py-1">
                <span className="text-kyro-muted">Cliente</span>
                <span className="text-right font-medium text-kyro-body">{comprobante.razon_social || '—'}</span>
              </div>
              {comprobante.num_doc_cliente && (
                <div className="flex justify-between py-1">
                  <span className="text-kyro-muted">Documento</span>
                  <span className="font-medium text-kyro-body">{comprobante.num_doc_cliente}</span>
                </div>
              )}
              {comprobante.creado_en && (
                <div className="flex justify-between py-1">
                  <span className="text-kyro-muted">Emitido</span>
                  <span className="text-kyro-body">{new Date(comprobante.creado_en).toLocaleDateString('es-PE')}</span>
                </div>
              )}
              {comprobante.anulado_en && (
                <div className="flex justify-between py-1">
                  <span className="text-kyro-muted">Anulado</span>
                  <span className="text-kyro-body">{new Date(comprobante.anulado_en).toLocaleDateString('es-PE')}</span>
                </div>
              )}
              <div className="mt-2 flex justify-between border-t border-kyro-border pt-2">
                <span className="font-semibold text-kyro-text">Total</span>
                <span className="font-mono text-lg font-bold text-kyro-text">
                  {new Intl.NumberFormat('es-PE', { style: 'currency', currency: comprobante.moneda || 'PEN' }).format(Number(comprobante.total))}
                </span>
              </div>
            </div>

            {comprobante.puede_descargar ? (
              <>
                <div>
                  <p className="mb-1.5 text-xs font-medium text-kyro-muted">Formato de impresión</p>
                  <div className="flex flex-wrap gap-1.5">
                    {FORMATOS.map((f) => (
                      <button
                        key={f.value}
                        type="button"
                        onClick={() => setFormato(f.value)}
                        className={`rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors ${
                          formato === f.value
                            ? 'border-indigo-500 bg-indigo-500/10 text-indigo-600 dark:text-indigo-300'
                            : 'border-kyro-border text-kyro-muted hover:border-indigo-300'
                        }`}
                      >
                        {f.label}
                      </button>
                    ))}
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-2.5">
                  <Link
                    to={`/cpe/${id}/imprimir?${qs}&formato=${formato}`}
                    className="flex h-11 items-center justify-center gap-2 rounded-xl border border-indigo-700 bg-gradient-to-b from-indigo-500 to-indigo-700 px-3 text-sm font-semibold text-white shadow-[0_12px_28px_-14px_rgba(79,70,229,0.9)] transition-all hover:-translate-y-px hover:brightness-110"
                  >
                    <FilePdf size={16} weight="bold" /> Ver / Imprimir
                  </Link>
                  <a
                    href={urlDescarga(id ?? '', 'xml', qs)}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex h-11 items-center justify-center gap-2 rounded-xl border border-kyro-border bg-white/70 px-3 text-sm font-semibold text-kyro-body shadow-sm transition-all hover:-translate-y-px hover:border-indigo-300 dark:bg-black/20"
                  >
                    <FileCode size={16} weight="bold" /> Descargar XML
                  </a>
                </div>

                <a
                  href={`https://wa.me/?text=${encodeURIComponent(`Aquí tienes tu comprobante ${comprobante.numero_completo ?? ''}: ${window.location.href}`)}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex h-10 items-center justify-center gap-2 rounded-xl border border-green-600/30 bg-green-500/10 text-sm font-medium text-green-700 transition-all hover:bg-green-500/15 dark:text-green-400"
                >
                  <WhatsappLogo size={16} weight="fill" /> Compartir por WhatsApp
                </a>
              </>
            ) : (
              <div className="rounded-xl border border-kyro-border bg-kyro-elevated/60 px-4 py-3 text-center text-sm text-kyro-muted">
                Este comprobante todavía se está procesando ante SUNAT. Vuelve a intentarlo en unos minutos.
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function ErrorCard({ mensaje }: { mensaje: string }) {
  return (
    <div className="flex flex-col items-center gap-3 py-8 text-center">
      <ShieldWarning size={40} weight="duotone" className="text-kyro-danger" />
      <p className="text-sm text-kyro-body">{mensaje}</p>
    </div>
  )
}

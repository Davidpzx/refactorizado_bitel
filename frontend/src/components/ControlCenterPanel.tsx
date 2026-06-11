import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, ArrowLeftRight, BellRing, Check, X, Trash2,
  TrendingDown, ShieldAlert, Inbox,
} from 'lucide-react'
import { controlCenterApi, type ControlCenterResponse, type TrasladoAccion } from '../services/controlCenter.api'

interface Props {
  cc?: ControlCenterResponse
  isDark: boolean
  onClose: () => void
}

function fechaCorta(raw: string | null): string {
  if (!raw) return ''
  const d = new Date(raw.replace(' ', 'T'))
  if (isNaN(d.getTime())) return raw
  return d.toLocaleDateString('es-PE', { day: '2-digit', month: 'short' }) +
    ' ' + d.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
}

export function ControlCenterPanel({ cc, isDark, onClose }: Props) {
  const qc = useQueryClient()
  const [busyId, setBusyId] = useState<string | null>(null)

  const invalidate = () => qc.invalidateQueries({ queryKey: ['control-center'] })

  const traslado = useMutation({
    mutationFn: ({ tipo, id, action, lote }: { tipo: 'equipos' | 'chips'; id: number; action: TrasladoAccion; lote?: string | null }) =>
      controlCenterApi.gestionarTraslado(tipo, id, action, lote),
    onSettled: () => { setBusyId(null); invalidate() },
  })

  const notif = useMutation({
    mutationFn: ({ id, accion }: { id: number; accion: 'leido' | 'borrar' }) =>
      controlCenterApi.marcarNotificacion(id, accion),
    onSettled: () => { setBusyId(null); invalidate() },
  })

  /* ── tokens ── */
  const panelBg = isDark
    ? { background: 'rgba(12,12,15,0.96)', border: '1px solid rgba(255,255,255,0.08)', boxShadow: '0 24px 60px -12px rgba(0,0,0,0.7)' }
    : { background: 'rgba(255,255,255,0.98)', border: '1px solid rgba(0,0,0,0.08)', boxShadow: '0 24px 60px -12px rgba(0,0,0,0.22)' }
  const txt      = isDark ? 'text-zinc-200' : 'text-gray-800'
  const txtMuted = isDark ? 'text-zinc-500' : 'text-gray-400'
  const rowBg    = isDark ? 'bg-zinc-900/60' : 'bg-gray-50'
  const rowBorder = isDark ? 'border-[rgba(255,255,255,0.05)]' : 'border-gray-100'
  const sectionLabel = isDark ? 'text-zinc-600' : 'text-gray-400'

  const anomalias = cc?.anomalias_caja.data ?? []
  const traslados = cc?.traslados_pendientes.data ?? []
  const notifs = cc?.notificaciones_sistema.data ?? []
  const empty = anomalias.length === 0 && traslados.length === 0 && notifs.length === 0

  function Section({ icon, title, count, accent, children }: {
    icon: React.ReactNode; title: string; count: number; accent: string; children: React.ReactNode
  }) {
    if (count === 0) return null
    return (
      <div className="mb-4 last:mb-0">
        <div className="flex items-center gap-2 px-1 mb-2">
          <span style={{ color: accent }}>{icon}</span>
          <span className={`text-[10px] font-bold uppercase tracking-widest ${sectionLabel}`}>{title}</span>
          <span className="text-[10px] font-bold rounded-full px-1.5 py-0.5"
            style={{ background: `${accent}22`, color: accent }}>{count}</span>
        </div>
        <div className="space-y-1.5">{children}</div>
      </div>
    )
  }

  return (
    <>
      <div className="fixed inset-0 z-40" onClick={onClose} />
      <div
        className="absolute z-50 w-80 max-h-[70vh] overflow-y-auto rounded-xl p-3"
        style={panelBg}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-1 pb-2 mb-2 border-b" style={{ borderColor: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.06)' }}>
          <span className={`text-xs font-bold uppercase tracking-widest ${txt}`}
            style={{ fontFamily: "'Orbitron', sans-serif" }}>
            Centro de Control
          </span>
          <button onClick={onClose} className={`p-1 rounded-md ${txtMuted} hover:${txt}`}><X size={14} /></button>
        </div>

        {empty && (
          <div className={`flex flex-col items-center gap-2 py-8 ${txtMuted}`}>
            <Inbox size={28} />
            <span className="text-xs">Sin alertas pendientes</span>
          </div>
        )}

        {/* Anomalías de caja */}
        <Section icon={<TrendingDown size={13} />} title="Anomalías de Caja" count={anomalias.length} accent="#f87171">
          {anomalias.map((a) => (
            <div key={a.agente_id} className={`flex items-center justify-between gap-2 rounded-lg border px-2.5 py-2 ${rowBg} ${rowBorder}`}>
              <div className="min-w-0">
                <p className={`text-xs font-semibold truncate ${txt}`}>{a.cajero ?? `Agente ${a.agente_id}`}</p>
                <p className={`text-[10px] ${txtMuted}`}>{a.tienda ?? '—'} · {a.dias_desc} días desc.</p>
              </div>
              <span className="text-xs font-bold tabular-nums shrink-0"
                style={{ color: a.mayor_diferencia < 0 ? '#f87171' : '#4ade80' }}>
                {a.mayor_diferencia > 0 ? '+' : ''}S/{a.mayor_diferencia.toFixed(2)}
              </span>
            </div>
          ))}
        </Section>

        {/* Traslados pendientes */}
        <Section icon={<ArrowLeftRight size={13} />} title="Traslados Pendientes" count={traslados.length} accent="#fbbf24">
          {traslados.map((t) => {
            const key = `${t.tipo_lote}-${t.id}`
            const busy = busyId === key && traslado.isPending
            return (
              <div key={key} className={`rounded-lg border px-2.5 py-2 ${rowBg} ${rowBorder}`}>
                <div className="flex items-center justify-between gap-2">
                  <div className="min-w-0">
                    <p className={`text-xs font-semibold truncate ${txt}`}>{t.detalle}</p>
                    <p className={`text-[10px] ${txtMuted}`}>
                      {t.tienda_origen} → {t.tienda_destino} · {t.cantidad}u · {fechaCorta(t.fecha_creacion)}
                    </p>
                  </div>
                  <span className="text-[9px] font-bold uppercase px-1.5 py-0.5 rounded shrink-0"
                    style={{ background: isDark ? 'rgba(251,191,36,0.15)' : 'rgba(217,119,6,0.12)', color: isDark ? '#fbbf24' : '#d97706' }}>
                    {t.tipo_lote}
                  </span>
                </div>
                <div className="flex gap-1.5 mt-2">
                  <button
                    disabled={busy}
                    onClick={() => { setBusyId(key); traslado.mutate({ tipo: t.tipo_lote, id: t.id, action: 'aprobar', lote: t.codigo_lote }) }}
                    className="flex-1 flex items-center justify-center gap-1 text-[11px] font-semibold py-1 rounded-md transition-colors disabled:opacity-40"
                    style={{ background: 'rgba(34,197,94,0.15)', color: '#22c55e' }}>
                    <Check size={12} /> Aprobar
                  </button>
                  <button
                    disabled={busy}
                    onClick={() => { setBusyId(key); traslado.mutate({ tipo: t.tipo_lote, id: t.id, action: 'rechazar', lote: t.codigo_lote }) }}
                    className="flex-1 flex items-center justify-center gap-1 text-[11px] font-semibold py-1 rounded-md transition-colors disabled:opacity-40"
                    style={{ background: 'rgba(239,68,68,0.13)', color: '#ef4444' }}>
                    <X size={12} /> Rechazar
                  </button>
                </div>
              </div>
            )
          })}
        </Section>

        {/* Notificaciones del sistema */}
        <Section icon={<BellRing size={13} />} title="Notificaciones" count={notifs.length} accent={isDark ? '#a78bfa' : '#7c3aed'}>
          {notifs.map((n) => {
            const key = `notif-${n.id}`
            const busy = busyId === key && notif.isPending
            const isAlert = /seguridad|alerta|cerrado/i.test(n.tipo)
            return (
              <div key={n.id} className={`flex items-start gap-2 rounded-lg border px-2.5 py-2 ${rowBg} ${rowBorder}`}>
                <span className="mt-0.5 shrink-0" style={{ color: isAlert ? '#f87171' : (isDark ? '#a78bfa' : '#7c3aed') }}>
                  {isAlert ? <ShieldAlert size={13} /> : <AlertTriangle size={13} />}
                </span>
                <div className="min-w-0 flex-1">
                  <p className={`text-[11px] leading-snug ${txt}`}>{n.mensaje}</p>
                  <p className={`text-[9px] ${txtMuted}`}>{fechaCorta(n.fecha_creacion)}</p>
                </div>
                <div className="flex flex-col gap-1 shrink-0">
                  <button disabled={busy} title="Marcar leído"
                    onClick={() => { setBusyId(key); notif.mutate({ id: n.id, accion: 'leido' }) }}
                    className={`p-1 rounded ${txtMuted} hover:text-green-500 disabled:opacity-40`}><Check size={12} /></button>
                  <button disabled={busy} title="Borrar"
                    onClick={() => { setBusyId(key); notif.mutate({ id: n.id, accion: 'borrar' }) }}
                    className={`p-1 rounded ${txtMuted} hover:text-red-500 disabled:opacity-40`}><Trash2 size={12} /></button>
                </div>
              </div>
            )
          })}
        </Section>
      </div>
    </>
  )
}

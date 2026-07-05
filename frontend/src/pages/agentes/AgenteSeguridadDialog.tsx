import { useState } from 'react'
import { Copy, Check, Eye, EyeOff, RefreshCw, Ban, Smartphone, Infinity as InfinityIcon, CalendarCheck } from 'lucide-react'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { useAgenteSeguridad, useTokenSeguridad, useResetDispositivo } from '../../hooks/useAgentes'
import type { Agente } from '../../types/agente'
import type { TipoTokenAccion } from '../../services/agentes.api'
import { formatearFechaCorta } from '../../lib/fechas'

interface Props {
  agente: Agente | undefined
  onClose: () => void
}

export function AgenteSeguridadDialog({ agente, onClose }: Props) {
  const [mostrarToken, setMostrarToken] = useState(false)
  const [copiado, setCopiado] = useState(false)

  const { data, isLoading } = useAgenteSeguridad(agente?.id)
  const tokenMutation = useTokenSeguridad(agente?.id)
  const resetMutation = useResetDispositivo(agente?.id)

  const ejecutarAccion = (tipo: TipoTokenAccion) => {
    tokenMutation.mutate(tipo, { onSuccess: () => setMostrarToken(true) })
  }

  const copiarToken = async () => {
    if (!data?.token) return
    await navigator.clipboard.writeText(data.token)
    setCopiado(true)
    setTimeout(() => setCopiado(false), 2000)
  }

  const confirmarReset = () => {
    if (!confirm('¿Desvincular el dispositivo y revocar el token de este agente?')) return
    resetMutation.mutate()
  }

  return (
    <Dialog open={!!agente} onClose={onClose} title={agente ? `Seguridad — ${agente.nombres}` : 'Seguridad'} maxWidth="sm">
      {isLoading && <p className="text-sm text-kyro-muted">Cargando estado de seguridad…</p>}

      {!isLoading && data && (
        <div className="space-y-5">
          <div className="kyro-card space-y-1 px-4 py-3 text-xs">
            <div className="flex items-center gap-2 text-kyro-body">
              <Smartphone size={14} />
              <span className="font-semibold">
                {data.dispositivo_vinculado ? 'Dispositivo vinculado' : 'Sin dispositivo vinculado'}
              </span>
            </div>
            {data.fecha_registro_dispositivo && (
              <p className="text-kyro-muted">Registrado: {formatearFechaCorta(data.fecha_registro_dispositivo)}</p>
            )}
            {data.tienda_registro_inicial && (
              <p className="text-kyro-muted">Tienda de registro: {data.tienda_registro_inicial}</p>
            )}
          </div>

          {!data.tiene_token && (
            <div className="space-y-2">
              <p className="text-xs text-kyro-muted">
                Selecciona el tipo de acceso por token a otorgar a este agente.
              </p>
              <Button variant="glassWarning" className="w-full justify-start gap-2" onClick={() => ejecutarAccion('diario')} disabled={tokenMutation.isPending}>
                <CalendarCheck size={15} /> Generar token diario (expira hoy)
              </Button>
              <Button variant="glassInfo" className="w-full justify-start gap-2" onClick={() => ejecutarAccion('permanente')} disabled={tokenMutation.isPending}>
                <InfinityIcon size={15} /> Generar token permanente
              </Button>
            </div>
          )}

          {data.tiene_token && (
            <div className="space-y-3">
              <p className="text-xs text-kyro-muted">Este agente ya tiene un token de acceso activo.</p>
              <div className="flex items-center justify-between gap-3 rounded-kyro border border-dashed border-kyro-gold/40 bg-black/20 px-4 py-3">
                <span className="font-mono text-2xl font-bold tracking-[0.3em] text-kyro-gold">
                  {mostrarToken ? data.token : '••••••'}
                </span>
                <button
                  type="button"
                  onClick={() => setMostrarToken((v) => !v)}
                  className="text-kyro-muted hover:text-kyro-body"
                  aria-label="Mostrar u ocultar token"
                >
                  {mostrarToken ? <EyeOff size={18} /> : <Eye size={18} />}
                </button>
              </div>
              <p className="text-center text-xs text-kyro-muted">
                {data.tipo_token === 'permanente' ? 'Token permanente (no caduca)' : `Token diario · expira ${formatearFechaCorta(data.expiracion_token ?? '')}`}
              </p>

              <Button variant="gold" className="w-full gap-2" onClick={copiarToken}>
                {copiado ? <Check size={15} /> : <Copy size={15} />} {copiado ? '¡Copiado!' : 'Copiar token'}
              </Button>

              <div className="flex gap-2">
                <Button
                  variant="glassIndigo"
                  className="flex-1 gap-2"
                  onClick={() => ejecutarAccion(data.tipo_token === 'permanente' ? 'permanente' : 'diario')}
                  disabled={tokenMutation.isPending}
                >
                  <RefreshCw size={14} /> Regenerar
                </Button>
                <Button
                  variant="glassDanger"
                  className="flex-1 gap-2"
                  onClick={() => ejecutarAccion('revocar')}
                  disabled={tokenMutation.isPending}
                >
                  <Ban size={14} /> Revocar
                </Button>
              </div>
            </div>
          )}

          {data.dispositivo_vinculado && (
            <Button variant="outline" className="w-full" onClick={confirmarReset} disabled={resetMutation.isPending}>
              Desvincular dispositivo
            </Button>
          )}
        </div>
      )}
    </Dialog>
  )
}

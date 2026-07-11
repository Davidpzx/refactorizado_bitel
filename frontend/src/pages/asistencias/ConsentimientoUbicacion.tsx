import { useState } from 'react'
import { api } from '../../services/api'
import { guardarConsentimientoLocal } from '../../utils/consentimientoUbicacion'

/**
 * Pantalla de consentimiento de rastreo de ubicación (APP-05 / APP-08).
 *
 * Se muestra UNA vez por dispositivo antes de arrancar el rastreo (o cuando falte el
 * consentimiento). Al aceptar registra el consentimiento en backend
 * (`POST /v1/attendance/consentimiento-ubicacion`) — sin él, `ping-ubicacion` responde 428
 * CONSENT_REQUIRED y el service no rastrea — y guarda un flag local por versión de texto
 * para no volver a mostrarla (helpers en utils/consentimientoUbicacion.ts).
 */

interface Props {
  dni: string
  deviceHash: string
  onAceptar: () => void
  onRechazar: () => void
}

export function ConsentimientoUbicacion({ dni, deviceHash, onAceptar, onRechazar }: Props) {
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')

  async function aceptar() {
    setEnviando(true)
    setError('')
    try {
      await api.post('/v1/attendance/consentimiento-ubicacion', { dni, device_hash: deviceHash })
      guardarConsentimientoLocal(deviceHash)
      onAceptar()
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error
      setError(msg ?? 'No se pudo registrar el consentimiento. Intenta de nuevo.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex flex-col gap-5">
      <div className="text-center">
        <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-kyro-gold/15 text-2xl">📍</div>
        <p className="text-lg font-bold text-white">Registro de presencia durante el turno</p>
      </div>

      <div className="space-y-3 rounded-2xl border border-zinc-700 bg-zinc-800/50 p-4 text-sm text-zinc-300">
        <p className="flex gap-2">
          <span className="text-kyro-gold">•</span>
          <span>Se registra tu <b className="text-white">ubicación cada 30 minutos, solo mientras tu turno está marcado</b> (desde la Entrada hasta la Salida). Fuera del turno la app no rastrea.</span>
        </p>
        <p className="flex gap-2">
          <span className="text-kyro-gold">•</span>
          <span><b className="text-white">Para qué:</b> verificar que estás en tu tienda durante el turno.</span>
        </p>
        <p className="flex gap-2">
          <span className="text-kyro-gold">•</span>
          <span>Mientras se registra, verás una <b className="text-white">notificación fija "Turno activo"</b> en la barra — el rastreo nunca es oculto.</span>
        </p>
      </div>

      {error && <p className="text-center text-sm text-red-400">{error}</p>}

      <button
        onClick={aceptar}
        disabled={enviando}
        className="w-full rounded-2xl bg-gradient-to-b from-[#ffd028] to-[#ffc200] py-4 text-lg font-bold text-[#1a1a1a] transition-all hover:brightness-105 active:scale-95 disabled:opacity-40"
      >
        {enviando ? 'Registrando...' : 'Acepto'}
      </button>
      <button
        onClick={onRechazar}
        disabled={enviando}
        className="text-xs text-zinc-500 hover:text-zinc-300 disabled:opacity-40"
      >
        Ahora no
      </button>
    </div>
  )
}

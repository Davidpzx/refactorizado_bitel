import { registerPlugin } from '@capacitor/core'
import { esPlataformaNativa } from './deviceIdentity'

/**
 * Wrapper TypeScript del plugin nativo `PresenceTracker` (Android, APP-05).
 *
 * Arranca/detiene el foreground service que hace un ping de ubicación a
 * `POST /v1/attendance/ping-ubicacion` cada 30 min EXACTOS mientras el turno está abierto.
 * El service corre FUERA del WebView y hace sus propios POST, por eso recibe `baseUrl`
 * (la base de la API que usa axios). Solo existe en el APK nativo; en web es inerte —
 * usar siempre `esPlataformaNativa()` como guarda (las funciones helper de abajo ya lo hacen).
 */
export interface PresenceTrackerPlugin {
  startTracking(opts: { baseUrl: string; dni: string; deviceHash: string }): Promise<void>
  stopTracking(): Promise<void>
  isTracking(): Promise<{ tracking: boolean }>
}

const PresenceTracker = registerPlugin<PresenceTrackerPlugin>('PresenceTracker')

/**
 * Inicia el rastreo de presencia. No-op y silencioso en web o si el plugin falla
 * (nunca debe romper el flujo de marcación, que ya fue exitoso cuando se llama esto).
 */
export async function iniciarRastreoPresencia(baseUrl: string, dni: string, deviceHash: string): Promise<void> {
  if (!esPlataformaNativa()) return
  try {
    await PresenceTracker.startTracking({ baseUrl, dni, deviceHash })
  } catch {
    /* noop: el rastreo es best-effort; la marcación ya quedó registrada */
  }
}

/** Detiene el rastreo de presencia (al marcar SALIDA). No-op en web. */
export async function detenerRastreoPresencia(): Promise<void> {
  if (!esPlataformaNativa()) return
  try {
    await PresenceTracker.stopTracking()
  } catch {
    /* noop */
  }
}

export default PresenceTracker

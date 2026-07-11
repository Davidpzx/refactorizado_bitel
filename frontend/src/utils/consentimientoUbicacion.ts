/**
 * Helpers de consentimiento de rastreo de ubicación (APP-05 / APP-08).
 *
 * Separado del componente `ConsentimientoUbicacion.tsx` para no mezclar exports de
 * componentes con constantes/funciones (regla react-refresh/only-export-components).
 * El flag local evita re-mostrar la pantalla si ya se aceptó esta versión de texto.
 */

// Debe coincidir con AsistenciaPresenciaController::VERSION_TEXTO_CONSENTIMIENTO.
export const CONSENT_VERSION = 'v1'

const consentKey = (deviceHash: string) => `kyro_consent_ubicacion_${CONSENT_VERSION}_${deviceHash}`

export function consentimientoAceptado(deviceHash: string): boolean {
  if (!deviceHash) return false
  try {
    return localStorage.getItem(consentKey(deviceHash)) === '1'
  } catch {
    return false
  }
}

export function guardarConsentimientoLocal(deviceHash: string) {
  try {
    localStorage.setItem(consentKey(deviceHash), '1')
  } catch {
    /* noop */
  }
}

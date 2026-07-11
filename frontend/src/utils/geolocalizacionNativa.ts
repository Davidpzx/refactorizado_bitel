import { Capacitor } from '@capacitor/core'
import { Geolocation } from '@capacitor/geolocation'

export interface PosicionObtenida {
  lat: number
  lng: number
  accuracy: number
  /**
   * Detección de GPS falso (mock location). NOTA HONESTA (APP-03): el plugin
   * oficial `@capacitor/geolocation` NO expone `Location.isFromMockProvider()`
   * de Android — eso requiere un plugin nativo propio (se añade en APP-02,
   * junto con el plugin de huella real). Hasta que exista, este campo siempre
   * es `false`; no se inventa una detección que no es real.
   */
  mockGps: boolean
}

export type ErrorPermisoGps = 'no_disponible' | 'permiso_denegado' | 'timeout' | 'desconocido'

/**
 * Obtiene la posición actual usando el plugin nativo de Capacitor cuando la app
 * corre como APK Android (permisos ACCESS_FINE_LOCATION reales, más confiables
 * que el prompt del navegador), y `navigator.geolocation` sin cambios en web.
 */
export async function obtenerPosicionActual(): Promise<PosicionObtenida> {
  if (Capacitor.isNativePlatform()) {
    return obtenerPosicionNativa()
  }
  return obtenerPosicionWeb()
}

async function obtenerPosicionNativa(): Promise<PosicionObtenida> {
  try {
    const permiso = await Geolocation.checkPermissions()
    if (permiso.location !== 'granted') {
      const solicitado = await Geolocation.requestPermissions({ permissions: ['location'] })
      if (solicitado.location !== 'granted') {
        throw { code: 'permiso_denegado' as ErrorPermisoGps }
      }
    }

    const pos = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 15000 })
    return {
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      mockGps: false, // ver nota en PosicionObtenida
    }
  } catch (err: unknown) {
    const code = (err as { code?: ErrorPermisoGps })?.code
    if (code === 'permiso_denegado') throw err
    throw { code: 'timeout' as ErrorPermisoGps, original: err }
  }
}

function obtenerPosicionWeb(): Promise<PosicionObtenida> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) {
      reject({ code: 'no_disponible' as ErrorPermisoGps })
      return
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve({
        lat: pos.coords.latitude,
        lng: pos.coords.longitude,
        accuracy: pos.coords.accuracy,
        mockGps: false,
      }),
      (err) => reject({
        code: (err.code === err.PERMISSION_DENIED ? 'permiso_denegado' : 'timeout') as ErrorPermisoGps,
        original: err,
      }),
      { enableHighAccuracy: true, timeout: 10000 },
    )
  })
}

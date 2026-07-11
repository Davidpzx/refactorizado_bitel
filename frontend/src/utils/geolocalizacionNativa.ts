import { Capacitor } from '@capacitor/core'
import { Geolocation } from '@capacitor/geolocation'
import DeviceIdentity, { esPlataformaNativa } from '../plugins/deviceIdentity'

export interface PosicionObtenida {
  lat: number
  lng: number
  accuracy: number
  /**
   * Detección de GPS falso (mock location).
   * - Nativo (APK): valor REAL de `Location.isFromMockProvider()` / `isMock()`,
   *   obtenido por el plugin propio `DeviceIdentity.getCurrentLocation()` (APP-02),
   *   porque `@capacitor/geolocation` no entrega el objeto `Location` de Android.
   * - Web: siempre `false` (el navegador no expone si la posición es simulada);
   *   no se inventa una detección que no es real.
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
    // Permisos: se sigue usando @capacitor/geolocation (flujo ya cableado en APP-03).
    const permiso = await Geolocation.checkPermissions()
    if (permiso.location !== 'granted') {
      const solicitado = await Geolocation.requestPermissions({ permissions: ['location'] })
      if (solicitado.location !== 'granted') {
        throw { code: 'permiso_denegado' as ErrorPermisoGps }
      }
    }

    // Fix + detección de mock reales via plugin propio (APP-02).
    if (esPlataformaNativa()) {
      try {
        const nativa = await DeviceIdentity.getCurrentLocation()
        return { lat: nativa.lat, lng: nativa.lng, accuracy: nativa.accuracy, mockGps: nativa.isMock }
      } catch (pluginErr: unknown) {
        // Códigos que emite el plugin (call.reject(...)): 'permiso_denegado' | 'no_disponible' | 'timeout'.
        const msg = (pluginErr as { code?: string; message?: string })?.code
          ?? (pluginErr as { message?: string })?.message
        if (msg === 'permiso_denegado') throw { code: 'permiso_denegado' as ErrorPermisoGps }
        // Si el plugin falla por otra razón (p. ej. APK construido sin el plugin),
        // se cae a @capacitor/geolocation sin mock-check en vez de romper la marcación.
      }
    }

    const pos = await Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 15000 })
    return {
      lat: pos.coords.latitude,
      lng: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
      mockGps: false, // fallback sin mock-check; ver nota en PosicionObtenida
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

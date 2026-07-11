import { registerPlugin, Capacitor } from '@capacitor/core'

/**
 * Wrapper TypeScript del plugin nativo `DeviceIdentity` (Android, APP-02).
 *
 * En web `Capacitor.isNativePlatform()` es `false` y este plugin NO existe: los
 * llamadores deben usar `esPlataformaNativa()` como guarda y caer al comportamiento
 * web actual (huella localStorage en TerminalAsistenciaPage, geolocation del
 * navegador en geolocalizacionNativa.ts). Nunca llamar a los métodos en web.
 */

export interface DeviceInfo {
  model: string
  brand: string
  androidVersion: string
  sdkInt?: number
}

export interface DeviceFingerprintResult {
  /** SHA-256 (64 hex) de ANDROID_ID + Build.FINGERPRINT + Build.MODEL + install_uuid. */
  fingerprint: string
  deviceInfo: DeviceInfo
}

export interface NativeLocationResult {
  lat: number
  lng: number
  accuracy: number
  /** Real: viene de Location.isFromMockProvider() / isMock() del fix nativo. */
  isMock: boolean
  provider: string
  time?: number
}

export interface DeviceIdentityPlugin {
  getDeviceFingerprint(): Promise<DeviceFingerprintResult>
  getCurrentLocation(): Promise<NativeLocationResult>
}

const DeviceIdentity = registerPlugin<DeviceIdentityPlugin>('DeviceIdentity')

/** True solo cuando corre como APK Android (plugin nativo disponible). */
export function esPlataformaNativa(): boolean {
  return Capacitor.isNativePlatform() && Capacitor.getPlatform() === 'android'
}

export default DeviceIdentity

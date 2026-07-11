package cloud.kyrocodelabs.asistencia;

import android.Manifest;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.HandlerThread;

import androidx.core.content.ContextCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.UUID;
import java.util.concurrent.atomic.AtomicBoolean;

/**
 * DeviceIdentityPlugin — plugin Capacitor propio (no hay equivalente oficial) para dos cosas
 * que el navegador / @capacitor/geolocation no exponen:
 *
 *  1) getDeviceFingerprint(): huella real del equipo = SHA-256(ANDROID_ID + Build.FINGERPRINT +
 *     Build.MODEL + install_uuid). Devuelve también deviceInfo (model, brand, androidVersion)
 *     para el Monitor de Fraude.
 *
 *  2) getCurrentLocation(): obtiene la ubicación de forma NATIVA (LocationManager) para poder
 *     leer Location.isFromMockProvider() / Location.isMock() — la detección de GPS falso real,
 *     imposible desde @capacitor/geolocation porque no entrega el objeto Location de Android.
 *
 * DECISIONES (documentadas en plan/.worker-titan-APP-02.log):
 *  - install_uuid en SharedPreferences MODE_PRIVATE, NO Android Keystore. Ver getOrCreateInstallUuid().
 *  - Ubicación vía LocationManager (framework), NO FusedLocationProviderClient, para no depender
 *    de Google Play Services en equipos de tienda sideloaded.
 */
@CapacitorPlugin(name = "DeviceIdentity")
public class DeviceIdentityPlugin extends Plugin {

    private static final String PREFS = "kyro_device_identity";
    private static final String KEY_UUID = "install_uuid";
    private static final long LOCATION_TIMEOUT_MS = 12000L;

    // ── 1. Huella de dispositivo ────────────────────────────────────────────────

    @PluginMethod
    public void getDeviceFingerprint(PluginCall call) {
        try {
            Context ctx = getContext();

            String androidId = getAndroidId(ctx);
            String buildFingerprint = safe(Build.FINGERPRINT);
            String model = safe(Build.MODEL);
            String installUuid = getOrCreateInstallUuid(ctx);

            // El separador '|' evita colisiones por concatenación ambigua entre campos.
            String raw = androidId + "|" + buildFingerprint + "|" + model + "|" + installUuid;
            String fingerprint = sha256Hex(raw);

            JSObject deviceInfo = new JSObject();
            deviceInfo.put("model", model);
            deviceInfo.put("brand", safe(Build.BRAND));
            deviceInfo.put("androidVersion", safe(Build.VERSION.RELEASE));
            deviceInfo.put("sdkInt", Build.VERSION.SDK_INT);

            JSObject ret = new JSObject();
            ret.put("fingerprint", fingerprint);
            ret.put("deviceInfo", deviceInfo);
            call.resolve(ret);
        } catch (Exception e) {
            call.reject("No se pudo calcular la huella del dispositivo", e);
        }
    }

    private String getAndroidId(Context ctx) {
        String id = android.provider.Settings.Secure.getString(
                ctx.getContentResolver(), android.provider.Settings.Secure.ANDROID_ID);
        return safe(id);
    }

    /**
     * UUID de instalación persistido en SharedPreferences MODE_PRIVATE.
     *
     * LIMITACIÓN REAL (sin adornos): SharedPreferences vive en el almacenamiento privado de la app
     * y SE BORRA cuando el usuario hace "Borrar datos" o desinstala la app — NO sobrevive a limpiar
     * datos. NO es Android Keystore ni almacenamiento a prueba de manipulación. El único componente
     * de la huella que sí sobrevive a "Borrar datos" y solo se pierde con factory reset es
     * ANDROID_ID (estable por dispositivo + firma de la app). Por eso el ancla anti-manipulación
     * real de la huella es ANDROID_ID; el install_uuid solo aporta entropía adicional para
     * distinguir instalaciones sobre el mismo ANDROID_ID. Si se borra la app, el uuid se regenera
     * y la huella cambia (el flujo de re-autorización con PIN del backend cubre ese caso).
     */
    private String getOrCreateInstallUuid(Context ctx) {
        SharedPreferences prefs = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String uuid = prefs.getString(KEY_UUID, null);
        if (uuid == null || uuid.isEmpty()) {
            uuid = UUID.randomUUID().toString();
            prefs.edit().putString(KEY_UUID, uuid).apply();
        }
        return uuid;
    }

    // ── 2. Ubicación nativa + detección de mock ─────────────────────────────────

    @PluginMethod
    public void getCurrentLocation(final PluginCall call) {
        final Context ctx = getContext();
        boolean fine = ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        boolean coarse = ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_COARSE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        if (!fine && !coarse) {
            call.reject("permiso_denegado");
            return;
        }

        final LocationManager lm = (LocationManager) ctx.getSystemService(Context.LOCATION_SERVICE);
        if (lm == null) {
            call.reject("no_disponible");
            return;
        }

        // requestLocationUpdates necesita un Looper: usamos un HandlerThread propio para no bloquear.
        final HandlerThread thread = new HandlerThread("kyro-loc");
        thread.start();
        final Handler handler = new Handler(thread.getLooper());
        final AtomicBoolean done = new AtomicBoolean(false);

        final LocationListener listener = new LocationListener() {
            @Override public void onLocationChanged(Location location) {
                if (done.compareAndSet(false, true)) {
                    resolveLocation(call, location);
                    cleanup(lm, this, thread);
                }
            }
            @Override public void onStatusChanged(String provider, int status, Bundle extras) {}
            @Override public void onProviderEnabled(String provider) {}
            @Override public void onProviderDisabled(String provider) {}
        };

        handler.post(new Runnable() {
            @Override public void run() {
                try {
                    boolean requested = false;
                    if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                        lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 0L, 0f, listener, thread.getLooper());
                        requested = true;
                    }
                    if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                        lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 0L, 0f, listener, thread.getLooper());
                        requested = true;
                    }
                    if (!requested && done.compareAndSet(false, true)) {
                        // Ningún proveedor activo → intenta última conocida, si no falla.
                        Location last = bestLastKnown(lm);
                        if (last != null) resolveLocation(call, last);
                        else call.reject("no_disponible");
                        cleanup(lm, listener, thread);
                    }
                } catch (SecurityException se) {
                    if (done.compareAndSet(false, true)) {
                        call.reject("permiso_denegado");
                        cleanup(lm, listener, thread);
                    }
                }
            }
        });

        // Timeout: si no llega fix fresco, cae a la última conocida; si tampoco hay, 'timeout'.
        handler.postDelayed(new Runnable() {
            @Override public void run() {
                if (done.compareAndSet(false, true)) {
                    Location last = bestLastKnown(lm);
                    if (last != null) resolveLocation(call, last);
                    else call.reject("timeout");
                    cleanup(lm, listener, thread);
                }
            }
        }, LOCATION_TIMEOUT_MS);
    }

    private void resolveLocation(PluginCall call, Location loc) {
        boolean isMock;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            isMock = loc.isMock();
        } else {
            // isFromMockProvider(): API 18+, deprecado en 31 pero funcional; cubre minSdk 24.
            isMock = loc.isFromMockProvider();
        }

        JSObject ret = new JSObject();
        ret.put("lat", loc.getLatitude());
        ret.put("lng", loc.getLongitude());
        ret.put("accuracy", loc.hasAccuracy() ? loc.getAccuracy() : 0.0);
        ret.put("provider", safe(loc.getProvider()));
        ret.put("isMock", isMock);
        ret.put("time", loc.getTime());
        call.resolve(ret);
    }

    private Location bestLastKnown(LocationManager lm) {
        try {
            Location gps = lm.getLastKnownLocation(LocationManager.GPS_PROVIDER);
            Location net = lm.getLastKnownLocation(LocationManager.NETWORK_PROVIDER);
            if (gps == null) return net;
            if (net == null) return gps;
            return gps.getTime() >= net.getTime() ? gps : net;
        } catch (SecurityException e) {
            return null;
        }
    }

    private void cleanup(LocationManager lm, LocationListener listener, HandlerThread thread) {
        try { lm.removeUpdates(listener); } catch (Exception ignored) {}
        try { thread.quitSafely(); } catch (Exception ignored) {}
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────

    private static String safe(String s) {
        return s != null ? s : "";
    }

    private static String sha256Hex(String input) throws Exception {
        MessageDigest md = MessageDigest.getInstance("SHA-256");
        byte[] bytes = md.digest(input.getBytes(StandardCharsets.UTF_8));
        StringBuilder sb = new StringBuilder(bytes.length * 2);
        for (byte b : bytes) {
            sb.append(Character.forDigit((b >> 4) & 0xF, 16));
            sb.append(Character.forDigit(b & 0xF, 16));
        }
        return sb.toString();
    }
}

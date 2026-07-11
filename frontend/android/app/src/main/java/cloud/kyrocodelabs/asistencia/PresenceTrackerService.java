package cloud.kyrocodelabs.asistencia;

import android.Manifest;
import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.content.pm.ServiceInfo;
import android.location.Location;
import android.location.LocationListener;
import android.location.LocationManager;
import android.os.BatteryManager;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.HandlerThread;
import android.os.IBinder;
import android.os.Looper;
import android.os.SystemClock;

import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import java.util.concurrent.TimeUnit;

/**
 * PresenceTrackerService — APP-05. Foreground service que hace un ping de ubicación a
 * POST {baseUrl}/v1/attendance/ping-ubicacion cada 30 minutos EXACTOS mientras el agente
 * tiene turno abierto. Arranca al marcar ENTRADA y se detiene al marcar SALIDA (lo maneja
 * TerminalAsistenciaPage vía PresenceTrackerPlugin).
 *
 * DECISIÓN-APP-01 (confirmada): notificación fija de canal IMPORTANCE_MIN, muda, sin banner —
 * requisito, no opcional. El foreground service + AlarmManager.setExactAndAllowWhileIdle es lo
 * que garantiza los 30 min aunque el equipo entre en Doze (WorkManager los estiraría a 45-60).
 *
 * DECISIONES de implementación (ver plan/.worker-titan-APP-05.log):
 *  - Programación: AlarmManager.setExactAndAllowWhileIdle con PendingIntent que re-entra al
 *    propio servicio (ACTION_PING). En Android 12+ se verifica canScheduleExactAlarms(); si el
 *    permiso de alarma exacta no está concedido se cae a setAndAllowWhileIdle (inexacto en Doze).
 *  - Cola offline: lista JSON en SharedPreferences (no SQLite). El volumen es mínimo (un turno
 *    son ~24 pings máximo; en un corte de red se acumulan unos pocos), no hay consultas ni
 *    relaciones — SharedPreferences append/flush es suficiente y evita el boilerplate de una
 *    base SQLite. Se limita a MAX_QUEUE para no crecer sin control.
 *  - HTTP con HttpURLConnection (sin dependencias nuevas). Se ejecuta en un executor de un solo
 *    hilo; las callbacks de ubicación llegan en un HandlerThread aparte para no bloquear el latch.
 */
public class PresenceTrackerService extends Service {

    public static volatile boolean RUNNING = false;

    static final String ACTION_START = "cloud.kyrocodelabs.asistencia.PRESENCE_START";
    static final String ACTION_STOP = "cloud.kyrocodelabs.asistencia.PRESENCE_STOP";
    static final String ACTION_PING = "cloud.kyrocodelabs.asistencia.PRESENCE_PING";

    static final String EXTRA_BASE_URL = "baseUrl";
    static final String EXTRA_DNI = "dni";
    static final String EXTRA_DEVICE_HASH = "deviceHash";

    private static final String CHANNEL_ID = "kyro_presencia";
    private static final int NOTIF_ID = 4201;
    private static final int ALARM_REQUEST = 4202;

    private static final long PING_INTERVAL_MS = 30L * 60L * 1000L; // 30 min exactos
    private static final long LOCATION_TIMEOUT_MS = 15000L;
    private static final int MAX_QUEUE = 200;

    private static final String PREFS = "kyro_presence";
    private static final String KEY_BASE_URL = "base_url";
    private static final String KEY_DNI = "dni";
    private static final String KEY_DEVICE_HASH = "device_hash";
    private static final String KEY_QUEUE = "queue";

    private String baseUrl;
    private String dni;
    private String deviceHash;

    // Executor para el trabajo de red/ubicación (bloquea con latch → NO puede ser el looper).
    private ExecutorService worker;
    // Looper propio donde llegan las callbacks de LocationManager (no bloquea al worker).
    private HandlerThread locThread;
    // Para postear operaciones de ciclo de vida del FGS al hilo principal desde el worker.
    private Handler mainHandler;

    @Override
    public void onCreate() {
        super.onCreate();
        crearCanal();
        worker = Executors.newSingleThreadExecutor();
        locThread = new HandlerThread("kyro-presence-loc");
        locThread.start();
        mainHandler = new Handler(Looper.getMainLooper());
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String action = intent != null ? intent.getAction() : null;

        if (ACTION_STOP.equals(action)) {
            // startForeground breve antes de parar para no violar el contrato de FGS en O+.
            startForegroundCompat();
            cancelarAlarma();
            RUNNING = false;
            stopForeground(true);
            stopSelf();
            return START_NOT_STICKY;
        }

        // START (con extras) o PING/reinicio (sin extras → cargar de prefs).
        if (intent != null && intent.hasExtra(EXTRA_DEVICE_HASH)) {
            baseUrl = normalizarBase(intent.getStringExtra(EXTRA_BASE_URL));
            dni = intent.getStringExtra(EXTRA_DNI);
            deviceHash = intent.getStringExtra(EXTRA_DEVICE_HASH);
            guardarConfig();
        } else {
            cargarConfig();
        }

        startForegroundCompat();
        RUNNING = true;

        if (deviceHash == null || deviceHash.isEmpty() || dni == null || dni.isEmpty()
                || baseUrl == null || baseUrl.isEmpty()) {
            // Sin configuración utilizable no hay nada que rastrear.
            RUNNING = false;
            stopForeground(true);
            stopSelf();
            return START_NOT_STICKY;
        }

        // Cada entrada (arranque o alarma) dispara un ping y reprograma el siguiente.
        programarSiguientePing();
        worker.execute(this::cicloPing);

        // START_STICKY: si el sistema mata el servicio, lo recrea con intent null y
        // reanudamos desde prefs (config + cola persistidas).
        return START_STICKY;
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onDestroy() {
        RUNNING = false;
        if (locThread != null) locThread.quitSafely();
        if (worker != null) worker.shutdown();
        super.onDestroy();
    }

    // ── Ciclo de ping ────────────────────────────────────────────────────────────

    /** Se ejecuta en el worker: primero reintenta la cola offline, luego manda el ping actual. */
    private void cicloPing() {
        try {
            flushCola();

            LocationSample sample = obtenerUbicacion();
            if (sample == null) {
                // Sin fix esta vez; no encolamos un ping sin coordenadas — el siguiente ciclo reintenta.
                return;
            }

            JSONObject payload = construirPayload(sample);
            int code = postPing(payload.toString());

            if (code >= 200 && code < 300) {
                return; // ok
            }
            if (esTransitorio(code)) {
                encolar(payload.toString());
            } else if (code == 422) {
                // NO_OPEN_SHIFT: el turno ya se cerró en otro lado → dejar de rastrear.
                pararPorTurnoCerrado();
            }
            // Otros 4xx (403 DEVICE_MISMATCH, 428 CONSENT_REQUIRED): reintentar no ayuda → se descarta.
        } catch (Exception ignored) {
            // Nunca dejar caer el servicio por un ciclo fallido.
        }
    }

    private boolean esTransitorio(int code) {
        // Fallo de red local (code 0), timeout (408), rate limit (429) o error de servidor (5xx).
        return code == 0 || code == 408 || code == 429 || code >= 500;
    }

    private void pararPorTurnoCerrado() {
        // Se llama desde el worker; las operaciones de ciclo de vida del FGS van al hilo principal.
        mainHandler.post(() -> {
            cancelarAlarma();
            RUNNING = false;
            stopForeground(true);
            stopSelf();
        });
    }

    private JSONObject construirPayload(LocationSample s) throws Exception {
        JSONObject o = new JSONObject();
        o.put("dni", dni);
        o.put("device_hash", deviceHash);
        o.put("lat", s.lat);
        o.put("lng", s.lng);
        o.put("accuracy", s.accuracy);
        o.put("mock_gps", s.isMock);
        int bat = leerBateria();
        if (bat >= 0) o.put("battery_pct", bat);
        o.put("capturado_en", isoAhora());
        return o;
    }

    // ── Ubicación (mismo criterio de mock que DeviceIdentityPlugin) ────────────────

    private LocationSample obtenerUbicacion() {
        Context ctx = getApplicationContext();
        boolean fine = ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        boolean coarse = ContextCompat.checkSelfPermission(ctx, Manifest.permission.ACCESS_COARSE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
        if (!fine && !coarse) return null;

        final LocationManager lm = (LocationManager) ctx.getSystemService(Context.LOCATION_SERVICE);
        if (lm == null) return null;

        final Location[] box = new Location[1];
        final CountDownLatch latch = new CountDownLatch(1);
        final LocationListener listener = new LocationListener() {
            @Override public void onLocationChanged(Location location) {
                if (location != null && box[0] == null) {
                    box[0] = location;
                    latch.countDown();
                }
            }
            @Override public void onStatusChanged(String provider, int status, Bundle extras) {}
            @Override public void onProviderEnabled(String provider) {}
            @Override public void onProviderDisabled(String provider) {}
        };

        boolean requested = false;
        try {
            if (lm.isProviderEnabled(LocationManager.GPS_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.GPS_PROVIDER, 0L, 0f, listener, locThread.getLooper());
                requested = true;
            }
            if (lm.isProviderEnabled(LocationManager.NETWORK_PROVIDER)) {
                lm.requestLocationUpdates(LocationManager.NETWORK_PROVIDER, 0L, 0f, listener, locThread.getLooper());
                requested = true;
            }
        } catch (SecurityException se) {
            return null;
        }

        try {
            if (requested) latch.await(LOCATION_TIMEOUT_MS, TimeUnit.MILLISECONDS);
        } catch (InterruptedException ignored) {
            Thread.currentThread().interrupt();
        } finally {
            try { lm.removeUpdates(listener); } catch (Exception ignored) {}
        }

        Location loc = box[0] != null ? box[0] : bestLastKnown(lm);
        return loc != null ? new LocationSample(loc) : null;
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

    private static final class LocationSample {
        final double lat;
        final double lng;
        final double accuracy;
        final boolean isMock;

        LocationSample(Location loc) {
            this.lat = loc.getLatitude();
            this.lng = loc.getLongitude();
            this.accuracy = loc.hasAccuracy() ? loc.getAccuracy() : 0.0;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                this.isMock = loc.isMock();
            } else {
                this.isMock = loc.isFromMockProvider();
            }
        }
    }

    private int leerBateria() {
        try {
            BatteryManager bm = (BatteryManager) getSystemService(Context.BATTERY_SERVICE);
            if (bm == null) return -1;
            int pct = bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY);
            return (pct >= 0 && pct <= 100) ? pct : -1;
        } catch (Exception e) {
            return -1;
        }
    }

    // ── HTTP ──────────────────────────────────────────────────────────────────────

    /** @return código HTTP; 0 si falló la conexión (red no disponible). */
    private int postPing(String json) {
        HttpURLConnection conn = null;
        try {
            URL url = new URL(baseUrl + "/v1/attendance/ping-ubicacion");
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("POST");
            conn.setRequestProperty("Content-Type", "application/json");
            conn.setRequestProperty("Accept", "application/json");
            conn.setConnectTimeout(10000);
            conn.setReadTimeout(15000);
            conn.setDoOutput(true);
            byte[] body = json.getBytes(StandardCharsets.UTF_8);
            try (OutputStream os = conn.getOutputStream()) {
                os.write(body);
            }
            return conn.getResponseCode();
        } catch (Exception e) {
            return 0;
        } finally {
            if (conn != null) conn.disconnect();
        }
    }

    // ── Cola offline (SharedPreferences) ──────────────────────────────────────────

    private void flushCola() {
        SharedPreferences prefs = getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        String raw = prefs.getString(KEY_QUEUE, null);
        if (raw == null || raw.isEmpty()) return;
        try {
            JSONArray arr = new JSONArray(raw);
            JSONArray restantes = new JSONArray();
            for (int i = 0; i < arr.length(); i++) {
                String item = arr.optString(i, null);
                if (item == null) continue;
                int code = postPing(item);
                boolean ok = code >= 200 && code < 300;
                boolean descartable = !ok && !esTransitorio(code); // 4xx no reintentable → soltar
                if (!ok && !descartable) {
                    restantes.put(item); // sigue transitorio → conservar para el próximo ciclo
                }
            }
            if (restantes.length() == 0) {
                prefs.edit().remove(KEY_QUEUE).apply();
            } else {
                prefs.edit().putString(KEY_QUEUE, restantes.toString()).apply();
            }
        } catch (Exception e) {
            // Cola corrupta: descartar para no bloquear el servicio.
            prefs.edit().remove(KEY_QUEUE).apply();
        }
    }

    private void encolar(String json) {
        SharedPreferences prefs = getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        try {
            String raw = prefs.getString(KEY_QUEUE, null);
            JSONArray arr = (raw == null || raw.isEmpty()) ? new JSONArray() : new JSONArray(raw);
            arr.put(json);
            // Cap: si excede MAX_QUEUE, descartar los más viejos.
            while (arr.length() > MAX_QUEUE) {
                arr.remove(0);
            }
            prefs.edit().putString(KEY_QUEUE, arr.toString()).apply();
        } catch (Exception ignored) {
        }
    }

    // ── Programación exacta (AlarmManager) ────────────────────────────────────────

    private PendingIntent alarmPendingIntent() {
        Intent i = new Intent(this, PresenceTrackerService.class).setAction(ACTION_PING);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            flags |= PendingIntent.FLAG_IMMUTABLE;
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            return PendingIntent.getForegroundService(this, ALARM_REQUEST, i, flags);
        }
        return PendingIntent.getService(this, ALARM_REQUEST, i, flags);
    }

    private void programarSiguientePing() {
        AlarmManager am = (AlarmManager) getSystemService(Context.ALARM_SERVICE);
        if (am == null) return;
        PendingIntent pi = alarmPendingIntent();
        long triggerAt = SystemClock.elapsedRealtime() + PING_INTERVAL_MS;
        try {
            boolean exacto = true;
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                exacto = am.canScheduleExactAlarms();
            }
            if (exacto) {
                am.setExactAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAt, pi);
            } else {
                // Sin permiso de alarma exacta (Android 12+): inexacta, puede estirarse en Doze.
                am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAt, pi);
            }
        } catch (SecurityException se) {
            am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, triggerAt, pi);
        }
    }

    private void cancelarAlarma() {
        AlarmManager am = (AlarmManager) getSystemService(Context.ALARM_SERVICE);
        if (am != null) am.cancel(alarmPendingIntent());
    }

    // ── Notificación (canal IMPORTANCE_MIN, muda) ─────────────────────────────────

    private void crearCanal() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationManager nm = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
            if (nm == null) return;
            NotificationChannel ch = new NotificationChannel(
                    CHANNEL_ID, "Turno activo", NotificationManager.IMPORTANCE_MIN);
            ch.setDescription("Indica que la app está registrando tu presencia durante el turno.");
            ch.setShowBadge(false);
            ch.setSound(null, null);
            ch.enableVibration(false);
            ch.enableLights(false);
            nm.createNotificationChannel(ch);
        }
    }

    private Notification construirNotificacion() {
        Intent abrir = new Intent(this, MainActivity.class);
        int piFlags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) piFlags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent contentPi = PendingIntent.getActivity(this, 0, abrir, piFlags);

        return new NotificationCompat.Builder(this, CHANNEL_ID)
                .setContentTitle("Turno activo")
                .setContentText("Asistencia registrando tu presencia")
                .setSmallIcon(R.drawable.ic_stat_presence)
                .setOngoing(true)
                .setSilent(true)
                .setPriority(NotificationCompat.PRIORITY_MIN)
                .setCategory(NotificationCompat.CATEGORY_SERVICE)
                .setContentIntent(contentPi)
                .build();
    }

    private void startForegroundCompat() {
        Notification n = construirNotificacion();
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) { // API 34
            startForeground(NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION);
        } else {
            startForeground(NOTIF_ID, n);
        }
    }

    // ── Config persistida ─────────────────────────────────────────────────────────

    private void guardarConfig() {
        getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putString(KEY_BASE_URL, baseUrl)
                .putString(KEY_DNI, dni)
                .putString(KEY_DEVICE_HASH, deviceHash)
                .apply();
    }

    private void cargarConfig() {
        SharedPreferences prefs = getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        baseUrl = prefs.getString(KEY_BASE_URL, null);
        dni = prefs.getString(KEY_DNI, null);
        deviceHash = prefs.getString(KEY_DEVICE_HASH, null);
    }

    // ── Utilidades ──────────────────────────────────────────────────────────────

    private static String normalizarBase(String url) {
        if (url == null) return null;
        String u = url.trim();
        while (u.endsWith("/")) u = u.substring(0, u.length() - 1);
        return u;
    }

    private static String isoAhora() {
        // ISO-8601 con offset local del dispositivo (Perú); Carbon lo parsea con su offset.
        SimpleDateFormat fmt = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", Locale.US);
        return fmt.format(new Date());
    }
}

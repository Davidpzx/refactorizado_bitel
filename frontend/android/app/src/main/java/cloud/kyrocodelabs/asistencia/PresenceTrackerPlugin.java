package cloud.kyrocodelabs.asistencia;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;

import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;

import com.getcapacitor.JSObject;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * PresenceTrackerPlugin — APP-05. Puente JS→nativo para arrancar/parar el foreground service
 * de pings de presencia (PresenceTrackerService).
 *
 *  - startTracking({baseUrl, dni, deviceHash}): lo llama TerminalAsistenciaPage tras marcar
 *    ENTRADA con éxito (y con consentimiento ya aceptado). baseUrl = base de la API (api.defaults
 *    .baseURL), el servicio hace sus propios POST porque corre fuera del WebView.
 *  - stopTracking(): al marcar SALIDA.
 *  - isTracking(): estado del servicio.
 *
 * POST_NOTIFICATIONS (Android 13+) se solicita best-effort aquí: si se deniega, el servicio
 * igual corre, solo que la notificación mínima queda oculta (se pierde transparencia, no función).
 */
@CapacitorPlugin(name = "PresenceTracker")
public class PresenceTrackerPlugin extends Plugin {

    @PluginMethod
    public void startTracking(PluginCall call) {
        String baseUrl = call.getString("baseUrl");
        String dni = call.getString("dni");
        String deviceHash = call.getString("deviceHash");

        if (baseUrl == null || baseUrl.isEmpty() || dni == null || dni.isEmpty()
                || deviceHash == null || deviceHash.isEmpty()) {
            call.reject("Parámetros incompletos (baseUrl, dni, deviceHash).");
            return;
        }

        solicitarNotificacionesSiHaceFalta();

        Intent i = new Intent(getContext(), PresenceTrackerService.class)
                .setAction(PresenceTrackerService.ACTION_START)
                .putExtra(PresenceTrackerService.EXTRA_BASE_URL, baseUrl)
                .putExtra(PresenceTrackerService.EXTRA_DNI, dni)
                .putExtra(PresenceTrackerService.EXTRA_DEVICE_HASH, deviceHash);
        ContextCompat.startForegroundService(getContext(), i);

        call.resolve();
    }

    @PluginMethod
    public void stopTracking(PluginCall call) {
        Intent i = new Intent(getContext(), PresenceTrackerService.class)
                .setAction(PresenceTrackerService.ACTION_STOP);
        // startForegroundService: el servicio hace startForeground breve y luego se detiene solo,
        // así no viola el contrato de FGS si estuviera arrancando en ese instante.
        ContextCompat.startForegroundService(getContext(), i);
        call.resolve();
    }

    @PluginMethod
    public void isTracking(PluginCall call) {
        JSObject ret = new JSObject();
        ret.put("tracking", PresenceTrackerService.RUNNING);
        call.resolve(ret);
    }

    private void solicitarNotificacionesSiHaceFalta() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return; // API 33
        if (getActivity() == null) return;
        boolean granted = ContextCompat.checkSelfPermission(
                getContext(), Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED;
        if (!granted) {
            ActivityCompat.requestPermissions(
                    getActivity(), new String[]{Manifest.permission.POST_NOTIFICATIONS}, 4203);
        }
    }
}

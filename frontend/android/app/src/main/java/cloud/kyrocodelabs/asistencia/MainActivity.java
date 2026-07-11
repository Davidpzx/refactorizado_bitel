package cloud.kyrocodelabs.asistencia;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Registro de los plugins nativos propios. Debe ir ANTES de super.onCreate()
        // para que el bridge de Capacitor los cargue.
        registerPlugin(DeviceIdentityPlugin.class);       // huella real + ubicación/mock-GPS (APP-02)
        registerPlugin(PresenceTrackerPlugin.class);      // foreground service de pings (APP-05)
        super.onCreate(savedInstanceState);
    }
}

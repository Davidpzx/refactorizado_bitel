package cloud.kyrocodelabs.asistencia;

import android.os.Bundle;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {
    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Registro del plugin nativo propio (huella real + ubicación/mock-GPS).
        // Debe ir ANTES de super.onCreate() para que el bridge de Capacitor lo cargue.
        registerPlugin(DeviceIdentityPlugin.class);
        super.onCreate(savedInstanceState);
    }
}

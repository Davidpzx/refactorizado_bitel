import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'cloud.kyrocodelabs.asistencia',
  appName: 'Asistencia Mundo Android',
  webDir: 'dist',
  server: {
    androidScheme: 'https',
  },
};

export default config;

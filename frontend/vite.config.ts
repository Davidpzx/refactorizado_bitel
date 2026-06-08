import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

export default defineConfig({
  plugins: [
    tailwindcss(),
    react(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    host: true,
  },
  build: {
    // Alerta si un chunk supera 500 KB
    chunkSizeWarningLimit: 500,
    rollupOptions: {
      output: {
        // Vendors pesados en chunks separados — el navegador los cachea entre deploys
        manualChunks: {
          'vendor-react':  ['react', 'react-dom', 'react-router-dom'],
          'vendor-query':  ['@tanstack/react-query'],
          'vendor-table':  ['@tanstack/react-table'],
          'vendor-charts': ['recharts'],
          'vendor-forms':  ['react-hook-form', '@hookform/resolvers', 'zod'],
          'vendor-utils':  ['axios', 'date-fns', 'zustand'],
        },
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
    minify: 'esbuild',
    // No exponer source maps en producción
    sourcemap: false,
  },
})

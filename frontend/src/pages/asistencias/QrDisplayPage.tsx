import { useState, useEffect, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { useAuth } from '../../hooks/useAuth'

interface QrData {
  token: string
  tienda_id: string
  expires_in: number
  bloque: number
}

// ── QR renderizado con Canvas ──────────────────────────────────────────────────

function QrCanvas({ token, size = 280 }: { token: string; size?: number }) {
  const [dataUrl, setDataUrl] = useState('')

  useEffect(() => {
    // Genera QR usando la API pública de QR server (no requiere librería)
    const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(token)}&format=png&margin=2`
    setDataUrl(qrUrl)
  }, [token, size])

  if (!dataUrl) {
    return (
      <div
        style={{ width: size, height: size }}
        className="bg-zinc-800 rounded-2xl animate-pulse flex items-center justify-center"
      >
        <span className="text-zinc-500 text-sm">Generando QR…</span>
      </div>
    )
  }

  return (
    <img
      src={dataUrl}
      alt="QR Asistencias"
      width={size}
      height={size}
      className="rounded-2xl border-4 border-white"
    />
  )
}

// ── Barra de tiempo restante ──────────────────────────────────────────────────

function BarraTTL({ ttl, max = 5 }: { ttl: number; max?: number }) {
  const pct = Math.max(0, Math.min(100, (ttl / max) * 100))
  const color = ttl > 2 ? 'bg-green-500' : ttl > 1 ? 'bg-amber-500' : 'bg-red-500'
  return (
    <div className="w-full h-2 bg-zinc-700 rounded-full overflow-hidden">
      <div
        className={`h-full rounded-full transition-all duration-500 ${color}`}
        style={{ width: `${pct}%` }}
      />
    </div>
  )
}

// ── Reloj ──────────────────────────────────────────────────────────────────────

function Reloj() {
  const [time, setTime] = useState(new Date())
  useEffect(() => {
    const t = setInterval(() => setTime(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  return (
    <p className="text-5xl font-mono font-black tabular-nums tracking-widest text-white">
      {time.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })}
    </p>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function QrDisplayPage() {
  const { usuario } = useAuth()
  const tiendaId = usuario?.tienda_id ?? 'DEFAULT'
  const [ttlLocal, setTtlLocal] = useState(5)

  const { data, refetch } = useQuery<QrData>({
    queryKey: ['qr-stream', tiendaId],
    queryFn: () => api.get<QrData>(`/v1/attendance/qr-stream/${tiendaId}`).then(r => r.data),
    refetchInterval: 5000,
    staleTime: 0,
  })

  // Countdown local sincronizado con TTL del servidor
  useEffect(() => {
    if (data?.expires_in != null) setTtlLocal(data.expires_in)
  }, [data?.expires_in, data?.bloque])

  const tick = useCallback(() => {
    setTtlLocal(t => {
      if (t <= 1) { refetch(); return 5 }
      return t - 1
    })
  }, [refetch])

  useEffect(() => {
    const interval = setInterval(tick, 1000)
    return () => clearInterval(interval)
  }, [tick])

  return (
    <div className="min-h-screen bg-zinc-950 text-white flex flex-col items-center justify-center px-6 py-8 gap-8">

      {/* Cabecera */}
      <div className="text-center space-y-1">
        <div className="inline-flex items-center gap-2 bg-red-600/10 border border-red-600/30 rounded-full px-4 py-1.5 mb-2">
          <span className="w-2 h-2 rounded-full bg-red-500 animate-pulse" />
          <span className="text-red-400 text-xs font-bold tracking-wide">QR EN VIVO</span>
        </div>
        <Reloj />
        <p className="text-zinc-400 text-sm mt-1">
          Tienda: <span className="text-white font-semibold">{tiendaId}</span>
        </p>
      </div>

      {/* QR */}
      <div className="flex flex-col items-center gap-4">
        {data?.token ? (
          <QrCanvas token={data.token} size={300} />
        ) : (
          <div className="w-[300px] h-[300px] bg-zinc-800 rounded-2xl animate-pulse" />
        )}
        <div className="w-64 space-y-2">
          <BarraTTL ttl={ttlLocal} />
          <p className="text-center text-xs text-zinc-500">
            Renueva en <span className="text-white font-mono font-bold">{ttlLocal}s</span>
          </p>
        </div>
      </div>

      {/* Instrucciones */}
      <div className="max-w-xs text-center space-y-3">
        <p className="text-sm text-zinc-400 leading-relaxed">
          El agente debe abrir la <span className="text-white font-semibold">Terminal de Asistencias</span> en su celular y escanear este código.
        </p>
        <div className="grid grid-cols-3 gap-3 text-xs">
          {['Entra al DNI', 'Ingresa PIN', 'Escanea QR'].map((s, i) => (
            <div key={i} className="bg-zinc-800 rounded-xl p-3 text-center text-zinc-400">
              <div className="text-xl mb-1">{['1️⃣', '2️⃣', '3️⃣'][i]}</div>
              {s}
            </div>
          ))}
        </div>
      </div>

      {/* URL de la terminal */}
      <div className="bg-zinc-800/60 border border-zinc-700 rounded-xl px-4 py-2 text-center">
        <p className="text-xs text-zinc-500 mb-0.5">Terminal para agentes:</p>
        <p className="text-sm font-mono text-red-400 font-semibold">
          {window.location.origin}/terminal
        </p>
      </div>

    </div>
  )
}

import { useState, useRef, useCallback, useEffect } from 'react'
import { api } from '../../services/api'

// ── Tipos ──────────────────────────────────────────────────────────────────────

type Paso = 'dni' | 'pin' | 'tipo' | 'metodo' | 'gps' | 'qr' | 'foto' | 'ok' | 'error'
type TipoMarcacion = 'entrada' | 'inicio_refrigerio' | 'fin_refrigerio' | 'salida'

interface AgenteStatus {
  agente: { id: number; nombre: string; tienda_id: string | null }
  siguiente_marcacion: TipoMarcacion | 'completado'
  asistencia: Record<string, unknown> | null
  fecha: string
}

// ── Helpers ────────────────────────────────────────────────────────────────────

const TIPO_LABEL: Record<TipoMarcacion, string> = {
  entrada:          'Entrada',
  inicio_refrigerio:'Inicio refrigerio',
  fin_refrigerio:   'Fin refrigerio',
  salida:           'Salida',
}

function formatTime(date: Date) {
  return date.toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

// ── Reloj en tiempo real ───────────────────────────────────────────────────────

function Reloj() {
  const [time, setTime] = useState(new Date())
  useEffect(() => {
    const t = setInterval(() => setTime(new Date()), 1000)
    return () => clearInterval(t)
  }, [])
  return (
    <div className="text-center mb-6">
      <p className="text-4xl font-mono font-bold text-white tabular-nums tracking-wide">
        {formatTime(time)}
      </p>
      <p className="text-sm text-zinc-400 mt-1">
        {time.toLocaleDateString('es-PE', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
      </p>
    </div>
  )
}

// ── Teclado PIN ───────────────────────────────────────────────────────────────

function TecladoPIN({ onSubmit }: { onSubmit: (pin: string) => void }) {
  const [pin, setPin] = useState('')

  function press(d: string) {
    if (pin.length < 4) {
      const next = pin + d
      setPin(next)
      if (next.length === 4) {
        setTimeout(() => onSubmit(next), 150)
      }
    }
  }

  function borrar() { setPin(p => p.slice(0, -1)) }

  return (
    <div className="flex flex-col items-center gap-4">
      <div className="flex gap-3">
        {[0, 1, 2, 3].map(i => (
          <div
            key={i}
            className={`w-4 h-4 rounded-full border-2 transition-all ${pin.length > i ? 'bg-red-500 border-red-500' : 'border-zinc-500'}`}
          />
        ))}
      </div>
      <div className="grid grid-cols-3 gap-3">
        {['1','2','3','4','5','6','7','8','9','','0','⌫'].map((d, i) => (
          <button
            key={i}
            onClick={() => d === '⌫' ? borrar() : d ? press(d) : undefined}
            className={`w-16 h-16 rounded-2xl text-xl font-bold transition-all
              ${d ? 'bg-zinc-700 hover:bg-zinc-600 text-white active:scale-95' : 'pointer-events-none'}
              ${d === '⌫' ? 'text-red-400' : ''}
            `}
          >
            {d}
          </button>
        ))}
      </div>
    </div>
  )
}

// ── Captura de foto (base64) ──────────────────────────────────────────────────

function CapturaFoto({ onCaptura }: { onCaptura: (base64: string) => void }) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const [stream, setStream] = useState<MediaStream | null>(null)
  const [error, setError] = useState('')

  useEffect(() => {
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } })
      .then(s => {
        setStream(s)
        if (videoRef.current) videoRef.current.srcObject = s
      })
      .catch(() => setError('No se pudo acceder a la cámara.'))
    return () => { stream?.getTracks().forEach(t => t.stop()) }
  }, [])

  function capturar() {
    if (!videoRef.current || !canvasRef.current) return
    const v = videoRef.current
    const c = canvasRef.current
    c.width = v.videoWidth
    c.height = v.videoHeight
    c.getContext('2d')?.drawImage(v, 0, 0)
    const b64 = c.toDataURL('image/jpeg', 0.7).split(',')[1]
    onCaptura(b64)
    stream?.getTracks().forEach(t => t.stop())
  }

  if (error) return <p className="text-red-400 text-sm">{error}</p>

  return (
    <div className="flex flex-col items-center gap-4">
      <div className="relative overflow-hidden rounded-2xl border-2 border-zinc-600 w-64 h-64">
        <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />
        <div className="absolute inset-0 border-[3px] border-red-500/60 rounded-2xl pointer-events-none" />
      </div>
      <canvas ref={canvasRef} className="hidden" />
      <button
        onClick={capturar}
        className="bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-2xl text-lg active:scale-95 transition-all"
      >
        Tomar foto
      </button>
    </div>
  )
}

// ── Escáner QR ────────────────────────────────────────────────────────────────

function EscanerQR({ onToken }: { onToken: (token: string) => void }) {
  const [input, setInput] = useState('')

  return (
    <div className="flex flex-col items-center gap-4 w-full max-w-xs">
      <p className="text-sm text-zinc-400 text-center">
        Apunta la cámara al QR del local o ingresa el código manualmente
      </p>
      <input
        autoFocus
        value={input}
        onChange={e => setInput(e.target.value)}
        onKeyDown={e => { if (e.key === 'Enter' && input.trim()) onToken(input.trim()) }}
        placeholder="Pega o escribe el token QR"
        className="w-full bg-zinc-700 text-white border border-zinc-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-500"
      />
      <button
        onClick={() => input.trim() && onToken(input.trim())}
        disabled={!input.trim()}
        className="bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white font-bold px-8 py-3 rounded-2xl text-base active:scale-95 transition-all"
      >
        Verificar QR
      </button>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

export function TerminalAsistenciaPage() {
  const [paso, setPaso] = useState<Paso>('dni')
  const [dni, setDni] = useState('')
  const [dniInput, setDniInput] = useState('')
  const [status, setStatus] = useState<AgenteStatus | null>(null)
  const [tipo, setTipo] = useState<TipoMarcacion>('entrada')
  const [loading, setLoading] = useState(false)
  const [mensaje, setMensaje] = useState('')
  const [subMensaje, setSubMensaje] = useState('')
  const [deviceId] = useState(() => {
    const stored = localStorage.getItem('kyro-device-id')
    if (stored) return stored
    const id = crypto.randomUUID()
    localStorage.setItem('kyro-device-id', id)
    return id
  })

  function reset() {
    setPaso('dni')
    setDni('')
    setDniInput('')
    setStatus(null)
    setMensaje('')
    setSubMensaje('')
    setLoading(false)
  }

  async function buscarDNI(dniVal: string) {
    if (dniVal.length !== 8) return
    setLoading(true)
    try {
      const res = await api.get(`/v1/attendance/status/${dniVal}`)
      const data: AgenteStatus = res.data
      setStatus(data)
      setDni(dniVal)
      if (data.siguiente_marcacion === 'completado') {
        setMensaje(`${data.agente.nombre}, ya completaste todas tus marcaciones de hoy.`)
        setPaso('ok')
      } else {
        setTipo(data.siguiente_marcacion as TipoMarcacion)
        setPaso('pin')
      }
    } catch {
      setMensaje('DNI no registrado en el sistema.')
      setPaso('error')
    } finally {
      setLoading(false)
    }
  }

  async function verificarPin(pin: string) {
    setLoading(true)
    try {
      await api.post('/v1/auth/verify-pin', { dni, pin })
      setPaso('metodo')
    } catch {
      setMensaje('PIN incorrecto. Intenta de nuevo.')
      setPaso('error')
    } finally {
      setLoading(false)
    }
  }

  const marcar = useCallback(async (metodo: 'gps' | 'qr' | 'foto', payload: Record<string, unknown>) => {
    setLoading(true)
    try {
      const endpoint = metodo === 'gps' ? '/v1/attendance/mark' : metodo === 'qr' ? '/v1/attendance/mark-qr' : '/v1/attendance/mark-photo'
      const res = await api.post(endpoint, { dni, tipo, device_id: deviceId, ...payload })
      const data = res.data
      const tardanza = data.tardanza_minutos > 0 ? ` (${data.tardanza_minutos} min tardanza)` : ''
      setMensaje(`¡${status?.agente.nombre}! ${TIPO_LABEL[tipo]} registrada${tardanza}.`)
      setSubMensaje(`Próxima marcación: ${TIPO_LABEL[data.siguiente_marcacion as TipoMarcacion] ?? data.siguiente_marcacion}`)
      setPaso('ok')
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { error?: string } } })?.response?.data?.error ?? 'Error al registrar asistencia.'
      setMensaje(msg)
      setPaso('error')
    } finally {
      setLoading(false)
    }
  }, [dni, tipo, deviceId, status])

  async function marcarGPS() {
    if (!navigator.geolocation) {
      setMensaje('GPS no disponible en este dispositivo.')
      setPaso('error')
      return
    }
    setPaso('gps')
    navigator.geolocation.getCurrentPosition(
      pos => marcar('gps', { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
      () => { setMensaje('No se pudo obtener ubicación GPS.'); setPaso('error') },
      { enableHighAccuracy: true, timeout: 10000 }
    )
  }

  return (
    <div className="min-h-screen bg-zinc-950 text-white flex flex-col items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">

        {/* Logo / título */}
        <div className="text-center mb-8">
          <div className="inline-flex items-center gap-2 bg-red-600/10 border border-red-600/30 rounded-2xl px-5 py-2 mb-3">
            <span className="text-red-500 font-black text-lg tracking-tight">KYRO</span>
            <span className="text-zinc-400 text-xs">Terminal de Asistencias</span>
          </div>
          <Reloj />
        </div>

        {/* ── Paso: DNI ── */}
        {paso === 'dni' && (
          <div className="space-y-4">
            <p className="text-center text-zinc-400 text-sm">Ingresa tu DNI para marcar asistencia</p>
            <input
              autoFocus
              value={dniInput}
              onChange={e => setDniInput(e.target.value.replace(/\D/g, '').slice(0, 8))}
              onKeyDown={e => { if (e.key === 'Enter') buscarDNI(dniInput) }}
              placeholder="Número de DNI (8 dígitos)"
              className="w-full bg-zinc-800 text-white border border-zinc-700 rounded-2xl px-5 py-4 text-xl text-center tracking-widest font-mono focus:outline-none focus:border-red-500"
              inputMode="numeric"
            />
            <button
              onClick={() => buscarDNI(dniInput)}
              disabled={dniInput.length !== 8 || loading}
              className="w-full bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white font-bold py-4 rounded-2xl text-lg active:scale-95 transition-all"
            >
              {loading ? 'Buscando...' : 'Continuar'}
            </button>
          </div>
        )}

        {/* ── Paso: PIN ── */}
        {paso === 'pin' && status && (
          <div className="flex flex-col items-center gap-6">
            <div className="text-center">
              <p className="text-lg font-bold">{status.agente.nombre}</p>
              <p className="text-sm text-zinc-400">Marcación: <span className="text-red-400 font-semibold">{TIPO_LABEL[tipo]}</span></p>
            </div>
            <p className="text-sm text-zinc-400">Ingresa tu PIN de seguridad</p>
            <TecladoPIN onSubmit={verificarPin} />
            {loading && <p className="text-sm text-zinc-400 animate-pulse">Verificando...</p>}
            <button onClick={reset} className="text-xs text-zinc-500 hover:text-zinc-300 mt-2">← Cambiar DNI</button>
          </div>
        )}

        {/* ── Paso: Selección de método ── */}
        {paso === 'metodo' && (
          <div className="flex flex-col items-center gap-4">
            <p className="text-center text-zinc-400 text-sm mb-2">
              ¿Cómo deseas registrar tu {TIPO_LABEL[tipo].toLowerCase()}?
            </p>
            <button
              onClick={marcarGPS}
              className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95"
            >
              <span className="text-2xl">📍</span>
              <div className="text-left">
                <p className="font-bold">GPS</p>
                <p className="text-xs text-zinc-400">Verificación por ubicación</p>
              </div>
            </button>
            <button
              onClick={() => setPaso('qr')}
              className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95"
            >
              <span className="text-2xl">📷</span>
              <div className="text-left">
                <p className="font-bold">Código QR</p>
                <p className="text-xs text-zinc-400">Escanear QR de la tienda</p>
              </div>
            </button>
            <button
              onClick={() => setPaso('foto')}
              className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95"
            >
              <span className="text-2xl">🤳</span>
              <div className="text-left">
                <p className="font-bold">Foto facial</p>
                <p className="text-xs text-zinc-400">Requiere revisión del supervisor</p>
              </div>
            </button>
            <button onClick={reset} className="text-xs text-zinc-500 hover:text-zinc-300 mt-1">← Cancelar</button>
          </div>
        )}

        {/* ── Paso: GPS (espera) ── */}
        {paso === 'gps' && (
          <div className="flex flex-col items-center gap-4 py-8">
            <div className="w-16 h-16 rounded-full border-4 border-red-500 border-t-transparent animate-spin" />
            <p className="text-zinc-400 text-sm">Obteniendo ubicación GPS...</p>
          </div>
        )}

        {/* ── Paso: QR ── */}
        {paso === 'qr' && (
          <div className="flex flex-col items-center gap-4">
            <EscanerQR onToken={token => marcar('qr', { qr_token: token })} />
            <button onClick={() => setPaso('metodo')} className="text-xs text-zinc-500 hover:text-zinc-300">← Volver</button>
          </div>
        )}

        {/* ── Paso: Foto ── */}
        {paso === 'foto' && (
          <div className="flex flex-col items-center gap-4">
            <p className="text-sm text-amber-400 text-center">
              Tu foto será revisada por el supervisor antes de confirmar.
            </p>
            <CapturaFoto onCaptura={b64 => marcar('foto', { foto: b64 })} />
            <button onClick={() => setPaso('metodo')} className="text-xs text-zinc-500 hover:text-zinc-300">← Volver</button>
          </div>
        )}

        {/* ── Paso: Éxito ── */}
        {paso === 'ok' && (
          <div className="flex flex-col items-center gap-5 text-center py-4">
            <div className="w-20 h-20 rounded-full bg-green-500/20 border-2 border-green-500 flex items-center justify-center text-4xl">
              ✓
            </div>
            <p className="text-lg font-bold text-green-400">{mensaje}</p>
            {subMensaje && <p className="text-sm text-zinc-400">{subMensaje}</p>}
            <button
              onClick={reset}
              className="mt-4 bg-zinc-800 hover:bg-zinc-700 text-white font-bold px-8 py-3 rounded-2xl transition-all active:scale-95"
            >
              Nueva marcación
            </button>
          </div>
        )}

        {/* ── Paso: Error ── */}
        {paso === 'error' && (
          <div className="flex flex-col items-center gap-5 text-center py-4">
            <div className="w-20 h-20 rounded-full bg-red-500/20 border-2 border-red-500 flex items-center justify-center text-4xl">
              ✕
            </div>
            <p className="text-base font-semibold text-red-400">{mensaje}</p>
            <button
              onClick={reset}
              className="bg-zinc-800 hover:bg-zinc-700 text-white font-bold px-8 py-3 rounded-2xl transition-all active:scale-95"
            >
              Intentar de nuevo
            </button>
          </div>
        )}

      </div>
    </div>
  )
}

import { useState, useRef, useCallback, useEffect } from 'react'
import { api } from '../../services/api'

// ── Tipos ──────────────────────────────────────────────────────────────────────

type Paso = 'dni' | 'pin' | 'opciones' | 'gps' | 'fallback' | 'qr' | 'foto' | 'token' | 'ok' | 'error'
type TipoMarcacion = 'entrada' | 'inicio_refrigerio' | 'fin_refrigerio' | 'salida'

interface AgenteStatus {
  agente: { id: number; nombre: string; tienda_id: string | null }
  siguiente_marcacion: TipoMarcacion | 'completado'
  device_authorized?: boolean
  asistencia: Record<string, unknown> | null
  fecha: string
}

interface Tienda { id: number; codigo?: string; nombre: string }

// ── Huella de dispositivo: kyro-hw-[40hex]-[dni] (paridad legacy) ────────────────

const KYRO_LS = 'kyro_dispositivo_fijo'
const KYRO_CK = 'kyro_huella'

function generarHuella(dni: string): string {
  let hex = ''
  try {
    const arr = new Uint8Array(20)
    crypto.getRandomValues(arr)
    hex = Array.from(arr, (b) => b.toString(16).padStart(2, '0')).join('')
  } catch {
    for (let i = 0; i < 40; i++) hex += Math.floor(Math.random() * 16).toString(16)
  }
  return `kyro-hw-${hex}-${dni}`
}

function setKyroCookie(val: string) {
  const d = new Date()
  d.setFullYear(d.getFullYear() + 1)
  document.cookie = `${KYRO_CK}=${encodeURIComponent(val)};expires=${d.toUTCString()};path=/;SameSite=Strict`
}

function getKyroCookie(): string | null {
  const prefix = KYRO_CK + '='
  for (const c of document.cookie.split(';')) {
    const t = c.trim()
    if (t.startsWith(prefix)) return decodeURIComponent(t.substring(prefix.length))
  }
  return null
}

function obtenerHuella(): string | null {
  let h: string | null = null
  try { h = localStorage.getItem(KYRO_LS) } catch { /* noop */ }
  if (h && h.startsWith('kyro-hw-')) return h
  const ck = getKyroCookie()
  if (ck && ck.startsWith('kyro-hw-')) {
    try { localStorage.setItem(KYRO_LS, ck) } catch { /* noop */ }
    return ck
  }
  return null
}

function obtenerOCrearHuella(dni: string): string {
  const existente = obtenerHuella()
  if (existente) return existente
  const nueva = generarHuella(dni)
  try { localStorage.setItem(KYRO_LS, nueva) } catch { /* noop */ }
  setKyroCookie(nueva)
  return nueva
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
      <p className="text-4xl font-mono font-bold text-white tabular-nums tracking-wide">{formatTime(time)}</p>
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
      if (next.length === 4) { setTimeout(() => { onSubmit(next); setPin('') }, 150) }
    }
  }
  function borrar() { setPin((p) => p.slice(0, -1)) }
  return (
    <div className="flex flex-col items-center gap-4">
      <div className="flex gap-3">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className={`w-4 h-4 rounded-full border-2 transition-all ${pin.length > i ? 'bg-red-500 border-red-500' : 'border-zinc-500'}`} />
        ))}
      </div>
      <div className="grid grid-cols-3 gap-3">
        {['1','2','3','4','5','6','7','8','9','','0','⌫'].map((d, i) => (
          <button key={i} onClick={() => d === '⌫' ? borrar() : d ? press(d) : undefined}
            className={`w-16 h-16 rounded-2xl text-xl font-bold transition-all
              ${d ? 'bg-zinc-700 hover:bg-zinc-600 text-white active:scale-95' : 'pointer-events-none'}
              ${d === '⌫' ? 'text-red-400' : ''}`}>
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
      .then((s) => { setStream(s); if (videoRef.current) videoRef.current.srcObject = s })
      .catch(() => setError('No se pudo acceder a la cámara.'))
    return () => { stream?.getTracks().forEach((t) => t.stop()) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  function capturar() {
    if (!videoRef.current || !canvasRef.current) return
    const v = videoRef.current, c = canvasRef.current
    c.width = v.videoWidth; c.height = v.videoHeight
    c.getContext('2d')?.drawImage(v, 0, 0)
    const b64 = c.toDataURL('image/jpeg', 0.7).split(',')[1]
    onCaptura(b64)
    stream?.getTracks().forEach((t) => t.stop())
  }
  if (error) return <p className="text-red-400 text-sm">{error}</p>
  return (
    <div className="flex flex-col items-center gap-4">
      <div className="relative overflow-hidden rounded-2xl border-2 border-zinc-600 w-64 h-64">
        <video ref={videoRef} autoPlay muted playsInline className="w-full h-full object-cover" />
        <div className="absolute inset-0 border-[3px] border-red-500/60 rounded-2xl pointer-events-none" />
      </div>
      <canvas ref={canvasRef} className="hidden" />
      <button onClick={capturar} className="bg-red-600 hover:bg-red-700 text-white font-bold px-8 py-3 rounded-2xl text-lg active:scale-95 transition-all">
        Tomar foto
      </button>
    </div>
  )
}

// ── Escáner QR con cuenta regresiva 2 min ───────────────────────────────────────

function EscanerQR({ onToken }: { onToken: (token: string) => void }) {
  const [input, setInput] = useState('')
  const [restante, setRestante] = useState(120)
  useEffect(() => {
    const t = setInterval(() => setRestante((r) => Math.max(0, r - 1)), 1000)
    return () => clearInterval(t)
  }, [])
  const mm = String(Math.floor(restante / 60)).padStart(2, '0')
  const ss = String(restante % 60).padStart(2, '0')
  return (
    <div className="flex flex-col items-center gap-4 w-full max-w-xs">
      <p className="text-sm text-zinc-400 text-center">Apunta al QR del local o ingresa el código manualmente</p>
      <div className={`font-mono text-lg font-bold ${restante <= 20 ? 'text-red-400' : 'text-amber-400'}`}>{mm}:{ss}</div>
      <input autoFocus value={input} onChange={(e) => setInput(e.target.value)}
        onKeyDown={(e) => { if (e.key === 'Enter' && input.trim()) onToken(input.trim()) }}
        placeholder="Pega o escribe el token QR"
        className="w-full bg-zinc-700 text-white border border-zinc-600 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-500" />
      <button onClick={() => input.trim() && onToken(input.trim())} disabled={!input.trim() || restante === 0}
        className="bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white font-bold px-8 py-3 rounded-2xl text-base active:scale-95 transition-all">
        {restante === 0 ? 'QR expirado' : 'Verificar QR'}
      </button>
    </div>
  )
}

// ── Toggle de negocio ───────────────────────────────────────────────────────────

function Toggle({ on, onChange, titulo, desc, color }: { on: boolean; onChange: (v: boolean) => void; titulo: string; desc: string; color: string }) {
  return (
    <button type="button" onClick={() => onChange(!on)}
      className={`w-full flex items-center justify-between gap-3 p-3 rounded-2xl border transition-all
        ${on ? 'bg-zinc-800' : 'bg-zinc-800/40 border-zinc-700'}`}
      style={on ? { borderColor: color, background: `${color}14` } : {}}>
      <div className="text-left">
        <span className="block text-sm font-bold text-white">{titulo}</span>
        <span className="block text-[11px] text-zinc-400">{desc}</span>
      </div>
      <span className="relative w-12 h-7 rounded-full shrink-0 transition-colors" style={{ background: on ? color : 'rgba(255,255,255,0.12)' }}>
        <span className="absolute top-1 w-5 h-5 rounded-full bg-white transition-all" style={{ left: on ? '26px' : '4px' }} />
      </span>
    </button>
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

  const [tiendas, setTiendas] = useState<Tienda[]>([])
  const [tiendaSel, setTiendaSel] = useState<string>('')
  const [omitirRefrigerio, setOmitirRefrigerio] = useState(false)
  const [turnoCompleto, setTurnoCompleto] = useState(false)

  const huellaRef = useRef<string>('')

  useEffect(() => {
    api.get('/v1/postulaciones/tiendas')
      .then((r) => setTiendas(Array.isArray(r.data) ? r.data : (r.data?.data ?? [])))
      .catch(() => setTiendas([]))
  }, [])

  function reset() {
    setPaso('dni'); setDni(''); setDniInput(''); setStatus(null)
    setMensaje(''); setSubMensaje(''); setLoading(false)
    setOmitirRefrigerio(false); setTurnoCompleto(false)
  }

  function fallarA(msg: string) { setMensaje(msg); setPaso('error') }

  // 1) DNI → status (decide bypass de PIN)
  async function buscarDNI(dniVal: string) {
    if (dniVal.length !== 8) return
    setLoading(true)
    const huella = obtenerOCrearHuella(dniVal)
    huellaRef.current = huella
    try {
      const res = await api.get(`/v1/attendance/status/${dniVal}`, { params: { device_id: huella } })
      const data: AgenteStatus = res.data
      setStatus(data); setDni(dniVal)
      setTiendaSel(data.agente.tienda_id ?? '')
      if (data.siguiente_marcacion === 'completado') {
        setMensaje(`${data.agente.nombre}, ya completaste tus marcaciones de hoy.`)
        setPaso('ok'); return
      }
      setTipo(data.siguiente_marcacion as TipoMarcacion)
      if (data.device_authorized) { setPaso('opciones') }
      else { await autorizarDispositivo(dniVal, huella) }
    } catch {
      fallarA('DNI no registrado en el sistema.')
    } finally { setLoading(false) }
  }

  // 2) Autorizar dispositivo (Modo A sin PIN; si require_pin → teclado)
  async function autorizarDispositivo(dniVal: string, huella: string, pin?: string) {
    try {
      const res = await api.post('/v1/autorizar-dispositivo', { dni: dniVal, device_hash: huella, ...(pin ? { pin } : {}) })
      const st = res.data?.status
      if (st === 'ok') { setPaso('opciones') }
      else if (st === 'require_pin') { setPaso('pin') }
      else { fallarA(res.data?.mensaje ?? 'No se pudo validar el dispositivo.') }
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { mensaje?: string } } })?.response?.data?.mensaje ?? 'Error al validar el dispositivo.'
      if (pin) { setMensaje(msg); setPaso('pin') } else { fallarA(msg) }
    }
  }

  // 3) Marcar (GPS / QR / Foto / Token)
  const marcar = useCallback(async (metodo: 'gps' | 'qr' | 'foto' | 'token', payload: Record<string, unknown>) => {
    setLoading(true)
    try {
      const endpoint = metodo === 'qr' ? '/v1/attendance/mark-qr' : metodo === 'foto' ? '/v1/attendance/mark-photo' : '/v1/attendance/mark'
      const res = await api.post(endpoint, {
        dni, tipo,
        device_id: huellaRef.current,
        tienda_id: tiendaSel || undefined,
        omitir_refrigerio: omitirRefrigerio,
        turno_extendido: turnoCompleto,
        ...payload,
      })
      const data = res.data
      const tard = data.tardanza_minutos > 0 ? ` (${data.tardanza_minutos} min tardanza)` : ''
      setMensaje(`¡${status?.agente.nombre}! ${TIPO_LABEL[tipo]} registrada${tard}.`)
      setSubMensaje(data.siguiente_marcacion ? `Próxima: ${TIPO_LABEL[data.siguiente_marcacion as TipoMarcacion] ?? data.siguiente_marcacion}` : '')
      setPaso('ok')
    } catch (err: unknown) {
      const resp = (err as { response?: { data?: { error?: string; code?: string } } })?.response?.data
      // GPS fuera de rango o señal débil → ofrecer alternativas (no es error duro)
      if (metodo === 'gps' && (resp?.code === 'OUT_OF_RANGE' || resp?.code === 'WEAK_GPS')) {
        setMensaje(resp?.error ?? 'No estás dentro del rango de la tienda.')
        setPaso('fallback'); return
      }
      fallarA(resp?.error ?? 'Error al registrar asistencia.')
    } finally { setLoading(false) }
  }, [dni, tipo, tiendaSel, omitirRefrigerio, turnoCompleto, status])

  function marcarGPS() {
    if (!navigator.geolocation) { setMensaje('GPS no disponible. Usa una alternativa.'); setPaso('fallback'); return }
    setPaso('gps')
    navigator.geolocation.getCurrentPosition(
      (pos) => marcar('gps', { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy }),
      () => { setMensaje('No se pudo obtener tu ubicación GPS.'); setPaso('fallback') },
      { enableHighAccuracy: true, timeout: 10000 },
    )
  }

  const nombreTienda = (cod: string) => tiendas.find((t) => (t.codigo ?? String(t.id)) === cod || String(t.id) === cod)?.nombre ?? cod

  return (
    <div className="min-h-screen bg-zinc-950 text-white flex flex-col items-center justify-center px-4 py-10">
      <div className="w-full max-w-sm">
        <div className="text-center mb-8">
          <div className="inline-flex items-center gap-2 bg-red-600/10 border border-red-600/30 rounded-2xl px-5 py-2 mb-3">
            <span className="text-red-500 font-black text-lg tracking-tight">KYRO</span>
            <span className="text-zinc-400 text-xs">Terminal de Asistencias</span>
          </div>
          <Reloj />
        </div>

        {/* ── DNI ── */}
        {paso === 'dni' && (
          <div className="space-y-4">
            <p className="text-center text-zinc-400 text-sm">Ingresa tu DNI para marcar asistencia</p>
            <input autoFocus value={dniInput}
              onChange={(e) => setDniInput(e.target.value.replace(/\D/g, '').slice(0, 8))}
              onKeyDown={(e) => { if (e.key === 'Enter') buscarDNI(dniInput) }}
              placeholder="Número de DNI (8 dígitos)" inputMode="numeric"
              className="w-full bg-zinc-800 text-white border border-zinc-700 rounded-2xl px-5 py-4 text-xl text-center tracking-widest font-mono focus:outline-none focus:border-red-500" />
            <button onClick={() => buscarDNI(dniInput)} disabled={dniInput.length !== 8 || loading}
              className="w-full bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white font-bold py-4 rounded-2xl text-lg active:scale-95 transition-all">
              {loading ? 'Buscando...' : 'Continuar'}
            </button>
          </div>
        )}

        {/* ── PIN (dispositivo nuevo) ── */}
        {paso === 'pin' && status && (
          <div className="flex flex-col items-center gap-6">
            <div className="text-center">
              <p className="text-lg font-bold">{status.agente.nombre}</p>
              <p className="text-xs text-amber-400">Dispositivo nuevo · ingresa tu PIN para autorizarlo</p>
            </div>
            <TecladoPIN onSubmit={(pin) => autorizarDispositivo(dni, huellaRef.current, pin)} />
            {mensaje && <p className="text-xs text-red-400">{mensaje}</p>}
            <button onClick={reset} className="text-xs text-zinc-500 hover:text-zinc-300">← Cambiar DNI</button>
          </div>
        )}

        {/* ── Opciones de marcación ── */}
        {paso === 'opciones' && status && (
          <div className="flex flex-col gap-4">
            <div className="text-center">
              <p className="text-lg font-bold">{status.agente.nombre}</p>
              <p className="text-sm text-zinc-400">Marcación: <span className="text-red-400 font-semibold">{TIPO_LABEL[tipo]}</span></p>
            </div>

            {/* Selector de sede */}
            <div>
              <label className="block text-[11px] uppercase tracking-wider text-zinc-500 mb-1">Sede donde te encuentras</label>
              <select value={tiendaSel} onChange={(e) => setTiendaSel(e.target.value)}
                className="w-full bg-zinc-800 text-white border border-zinc-700 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-500">
                {tiendaSel === '' && <option value="">Selecciona tu tienda</option>}
                {tiendas.map((t) => {
                  const val = t.codigo ?? String(t.id)
                  return <option key={t.id} value={val}>{t.nombre}</option>
                })}
              </select>
            </div>

            {/* Toggles solo en entrada */}
            {tipo === 'entrada' && (
              <div className="space-y-2">
                <Toggle on={omitirRefrigerio} onChange={setOmitirRefrigerio} color="#06b6d4"
                  titulo="Turno de Corrido" desc="Omitir refrigerio hoy (no podrás cambiarlo)" />
                <Toggle on={turnoCompleto} onChange={setTurnoCompleto} color="#22d3ee"
                  titulo="Turno Completo" desc="Medio tiempo que hoy cubre jornada completa" />
              </div>
            )}

            <button onClick={marcarGPS} disabled={!tiendaSel || loading}
              className="w-full bg-red-600 hover:bg-red-700 disabled:opacity-40 text-white font-bold py-4 rounded-2xl text-lg active:scale-95 transition-all">
              {loading ? 'Procesando...' : `MARCAR ${TIPO_LABEL[tipo].toUpperCase()}`}
            </button>
            <button onClick={() => setPaso('fallback')} className="text-xs text-zinc-500 hover:text-amber-400">¿Problemas con el GPS? Usar otra forma</button>
            <button onClick={reset} className="text-xs text-zinc-600 hover:text-zinc-400">← Cancelar</button>
          </div>
        )}

        {/* ── GPS (espera) ── */}
        {paso === 'gps' && (
          <div className="flex flex-col items-center gap-4 py-8">
            <div className="w-16 h-16 rounded-full border-4 border-red-500 border-t-transparent animate-spin" />
            <p className="text-zinc-400 text-sm">Validando tu ubicación en {nombreTienda(tiendaSel)}...</p>
          </div>
        )}

        {/* ── Fallback: elegir alternativa ── */}
        {paso === 'fallback' && (
          <div className="flex flex-col items-center gap-3">
            <p className="text-center text-amber-400 text-sm mb-1">{mensaje || 'Elige una forma alternativa de marcar'}</p>
            <button onClick={() => setPaso('qr')} className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95">
              <span className="text-2xl">🔳</span>
              <div className="text-left"><p className="font-bold">Código QR de la tienda</p><p className="text-xs text-zinc-400">Escanea el QR del local (2 min)</p></div>
            </button>
            <button onClick={() => setPaso('foto')} className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95">
              <span className="text-2xl">🤳</span>
              <div className="text-left"><p className="font-bold">Foto de la fachada</p><p className="text-xs text-zinc-400">Entra a revisión del supervisor</p></div>
            </button>
            <button onClick={() => setPaso('token')} className="w-full flex items-center gap-4 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 rounded-2xl px-5 py-4 transition-all active:scale-95">
              <span className="text-2xl">🔑</span>
              <div className="text-left"><p className="font-bold">Token de emergencia</p><p className="text-xs text-zinc-400">6 dígitos provistos por el administrador</p></div>
            </button>
            <button onClick={() => setPaso('opciones')} className="text-xs text-zinc-500 hover:text-zinc-300 mt-1">← Volver</button>
          </div>
        )}

        {/* ── QR ── */}
        {paso === 'qr' && (
          <div className="flex flex-col items-center gap-4">
            <EscanerQR onToken={(token) => marcar('qr', { qr_token: token })} />
            <button onClick={() => setPaso('fallback')} className="text-xs text-zinc-500 hover:text-zinc-300">← Volver</button>
          </div>
        )}

        {/* ── Foto ── */}
        {paso === 'foto' && (
          <div className="flex flex-col items-center gap-4">
            <p className="text-sm text-amber-400 text-center">Tu foto será revisada por el supervisor antes de confirmar.</p>
            <CapturaFoto onCaptura={(b64) => marcar('foto', { foto: b64 })} />
            <button onClick={() => setPaso('fallback')} className="text-xs text-zinc-500 hover:text-zinc-300">← Volver</button>
          </div>
        )}

        {/* ── Token ── */}
        {paso === 'token' && (
          <div className="flex flex-col items-center gap-4 w-full max-w-xs mx-auto">
            <p className="text-sm text-zinc-400 text-center">Ingresa el token de 6 dígitos que te dio el administrador</p>
            <input autoFocus inputMode="numeric" maxLength={6}
              onChange={(e) => {
                const v = e.target.value.replace(/\D/g, '').slice(0, 6)
                e.target.value = v
                if (v.length === 6) marcar('token', { token: v })
              }}
              placeholder="••••••"
              className="w-full bg-zinc-800 text-amber-300 border border-amber-500/50 rounded-xl px-4 py-3 text-center text-2xl tracking-[0.5em] font-mono focus:outline-none focus:border-amber-400" />
            {loading && <p className="text-xs text-zinc-400 animate-pulse">Validando token...</p>}
            <button onClick={() => setPaso('fallback')} className="text-xs text-zinc-500 hover:text-zinc-300">← Volver</button>
          </div>
        )}

        {/* ── Éxito ── */}
        {paso === 'ok' && (
          <div className="flex flex-col items-center gap-5 text-center py-4">
            <div className="w-20 h-20 rounded-full bg-green-500/20 border-2 border-green-500 flex items-center justify-center text-4xl">✓</div>
            <p className="text-lg font-bold text-green-400">{mensaje}</p>
            {subMensaje && <p className="text-sm text-zinc-400">{subMensaje}</p>}
            <button onClick={reset} className="mt-4 bg-zinc-800 hover:bg-zinc-700 text-white font-bold px-8 py-3 rounded-2xl transition-all active:scale-95">Nueva marcación</button>
          </div>
        )}

        {/* ── Error ── */}
        {paso === 'error' && (
          <div className="flex flex-col items-center gap-5 text-center py-4">
            <div className="w-20 h-20 rounded-full bg-red-500/20 border-2 border-red-500 flex items-center justify-center text-4xl">✕</div>
            <p className="text-base font-semibold text-red-400">{mensaje}</p>
            <button onClick={reset} className="bg-zinc-800 hover:bg-zinc-700 text-white font-bold px-8 py-3 rounded-2xl transition-all active:scale-95">Intentar de nuevo</button>
          </div>
        )}
      </div>
    </div>
  )
}

import { useState, useEffect, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api } from '../../services/api'
import { PageHeader } from '../../components/PageHeader'
import { PageTabs } from '../../components/ui/PageTabs'
import { GlassPanel } from '../../components/ui/GlassPanel'
import { MapPin, CalendarBlank as CalendarDays, Clock, ArrowsClockwise as RefreshCw } from '@phosphor-icons/react'

// ── Tipos ─────────────────────────────────────────────────────────────────────

interface DiaCal {
  fecha: string
  ventas_count: number
  ventas_monto: number
  bipay_neto: number
  bipay_lineas: number
  total_actividad: number
}

interface CalendarioResp {
  year: number
  dias: DiaCal[]
}

interface TiendaGeo {
  codigo: string
  nombre: string
  lat: number
  lng: number
  ventas_count: number
  ventas_monto: number
  bipay_neto: number
  bipay_lineas: number
  valor: number
}

interface GeograficoResp {
  desde: string
  hasta: string
  tiendas: TiendaGeo[]
  max_valor: number
}

interface CeldaHorario {
  hora: number
  dia: number
  cnt: number
  monto_abs: number
}

interface HorarioResp {
  desde: string
  hasta: string
  grid: CeldaHorario[]
  max_cnt: number
  fuente: string
}

// ── Paleta de colores ─────────────────────────────────────────────────────────

function colorCalor(ratio: number): string {
  if (ratio <= 0)   return 'var(--color-kyro-elevated)'
  if (ratio < 0.15) return 'color-mix(in srgb, var(--color-kyro-indigo) 25%, transparent)'
  if (ratio < 0.35) return 'color-mix(in srgb, var(--color-kyro-indigo) 50%, transparent)'
  if (ratio < 0.55) return 'color-mix(in srgb, var(--color-kyro-warning) 55%, transparent)'
  if (ratio < 0.75) return 'color-mix(in srgb, var(--color-kyro-warning) 80%, transparent)'
  return 'color-mix(in srgb, var(--color-kyro-danger) 90%, transparent)'
}

// ── TAB 1: Calendario ─────────────────────────────────────────────────────────

const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
const DIAS_SEMANA = ['L','M','X','J','V','S','D']

function TabCalendario({ year, setYear }: { year: number; setYear: (y: number) => void }) {
  const { data, isFetching, refetch } = useQuery<CalendarioResp>({
    queryKey: ['heatmap-calendario', year],
    queryFn: () => api.get(`/v1/heatmap/calendario?year=${year}`).then(r => r.data),
    staleTime: 5 * 60_000,
  })

  const [metrica, setMetrica] = useState<'total_actividad' | 'ventas_count' | 'bipay_lineas'>('total_actividad')
  const [hover, setHover] = useState<DiaCal | null>(null)

  const diasMap = new Map<string, DiaCal>()
  data?.dias.forEach(d => diasMap.set(d.fecha, d))

  const maxVal = Math.max(1, ...(data?.dias.map(d => d[metrica] as number) ?? []))

  // Construir semanas: el año completo como 52-53 columnas × 7 filas
  const semanas: (string | null)[][] = []
  const inicio = new Date(`${year}-01-01`)
  // ajustar al lunes anterior
  const dowInicio = (inicio.getDay() + 6) % 7 // 0=Lun
  const cursor = new Date(inicio)
  cursor.setDate(cursor.getDate() - dowInicio)

  while (cursor.getFullYear() <= year || cursor <= new Date(`${year}-12-31`)) {
    const semana: (string | null)[] = []
    for (let d = 0; d < 7; d++) {
      const fechaStr = cursor.toISOString().slice(0, 10)
      semana.push(cursor.getFullYear() === year ? fechaStr : null)
      cursor.setDate(cursor.getDate() + 1)
    }
    semanas.push(semana)
    if (cursor.getFullYear() > year && cursor.getMonth() > 0) break
  }

  // Etiquetas de mes: primera semana donde aparece cada mes
  const etiquetasMes: { col: number; mes: number }[] = []
  semanas.forEach((sem, col) => {
    const primerDia = sem.find(d => d !== null)
    if (primerDia) {
      const mes = new Date(primerDia).getMonth()
      if (etiquetasMes.length === 0 || etiquetasMes[etiquetasMes.length - 1].mes !== mes) {
        etiquetasMes.push({ col, mes })
      }
    }
  })

  const CELL = 13
  const GAP  = 2
  const LEFT = 22
  const TOP  = 28

  const svgW = LEFT + semanas.length * (CELL + GAP) + 8
  const svgH = TOP + 7 * (CELL + GAP) + 30

  return (
    <div>
      {/* Controles */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <div className="flex items-center gap-2">
          <button onClick={() => setYear(year - 1)}
            className="flex h-9 w-9 items-center justify-center rounded-[10px] border border-kyro-border bg-kyro-elevated text-kyro-muted transition-colors hover:border-kyro-indigo/40 hover:text-kyro-indigo">‹</button>
          <span className="font-bold text-kyro-text text-lg tabular-nums">{year}</span>
          <button onClick={() => setYear(year + 1)}
            className="flex h-9 w-9 items-center justify-center rounded-[10px] border border-kyro-border bg-kyro-elevated text-kyro-muted transition-colors hover:border-kyro-indigo/40 hover:text-kyro-indigo">›</button>
        </div>

        <div className="flex items-center gap-1 rounded-[10px] border border-kyro-border bg-kyro-elevated p-1">
          {([
            ['total_actividad', 'Total actividad'],
            ['ventas_count',    'Ventas ERP'],
            ['bipay_lineas',    'Líneas Bipay'],
          ] as const).map(([k, lbl]) => (
            <button key={k} onClick={() => setMetrica(k)}
              className={`h-9 rounded-[10px] px-3 text-xs font-medium transition-all ${metrica === k ? 'bg-kyro-indigo text-white' : 'text-kyro-muted hover:bg-kyro-indigo/10 hover:text-kyro-text'}`}>
              {lbl}
            </button>
          ))}
        </div>

        <button onClick={() => refetch()} disabled={isFetching}
          className="ml-auto flex h-9 items-center gap-1.5 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 text-xs font-medium text-kyro-muted transition-all hover:border-kyro-indigo/40 hover:text-kyro-indigo">
          <RefreshCw size={12} className={isFetching ? 'animate-spin' : ''} />
          {isFetching ? 'Cargando…' : 'Actualizar'}
        </button>
      </div>

      {/* Tooltip */}
      {hover && (
        <div className="mb-3 inline-flex gap-4 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 py-2 text-xs tabular-nums shadow-kyro-card">
          <span className="text-kyro-muted">{hover.fecha}</span>
          <span className="text-kyro-text">Ventas: <b>{hover.ventas_count}</b></span>
          <span className="text-kyro-indigo">Bipay líneas: <b>{hover.bipay_lineas}</b></span>
          <span className="text-kyro-info">Bipay neto: <b>S/ {hover.bipay_neto.toFixed(2)}</b></span>
        </div>
      )}

      {/* SVG Grid */}
      <div className="overflow-x-auto">
        <svg width={svgW} height={svgH} style={{ display: 'block', minWidth: svgW }}>
          {/* Etiquetas de mes */}
          {etiquetasMes.map(({ col, mes }) => (
            <text key={mes} x={LEFT + col * (CELL + GAP)} y={14}
              fill="var(--color-kyro-muted)" fontSize={10}>{MESES[mes]}</text>
          ))}
          {/* Etiquetas día semana */}
          {DIAS_SEMANA.map((d, i) => (
            i % 2 === 0 &&
            <text key={i} x={LEFT - 18} y={TOP + i * (CELL + GAP) + CELL - 2}
              fill="var(--color-kyro-muted)" fontSize={9}>{d}</text>
          ))}
          {/* Celdas */}
          {semanas.map((sem, col) =>
            sem.map((fecha, fila) => {
              if (!fecha) return null
              const d = diasMap.get(fecha)
              const val = d ? (d[metrica] as number) : 0
              const ratio = val / maxVal
              return (
                <rect
                  key={fecha}
                  x={LEFT + col * (CELL + GAP)}
                  y={TOP + fila * (CELL + GAP)}
                  width={CELL} height={CELL}
                  rx={2}
                  fill={colorCalor(ratio)}
                  style={{ cursor: 'pointer' }}
                  onMouseEnter={() => setHover(d ?? null)}
                  onMouseLeave={() => setHover(null)}
                />
              )
            })
          )}
        </svg>
      </div>

      {/* Leyenda */}
      <div className="flex items-center gap-2 mt-3">
        <span className="text-[10px] text-kyro-muted">Menos</span>
        {[0, 0.15, 0.35, 0.55, 0.75, 1].map(r => (
          <div key={r} style={{ width: 12, height: 12, borderRadius: 2, background: colorCalor(r) }} />
        ))}
        <span className="text-[10px] text-kyro-muted">Más</span>
      </div>
    </div>
  )
}

// ── TAB 2: Geográfico ─────────────────────────────────────────────────────────

function TabGeografico() {
  const mapRef = useRef<HTMLDivElement>(null)
  const leafletRef = useRef<{ map: unknown; markers: unknown[] } | null>(null)

  const hoy = new Date().toISOString().slice(0, 10)
  const mesAtras = new Date(Date.now() - 30 * 86400_000).toISOString().slice(0, 10)

  const [desde, setDesde] = useState(mesAtras)
  const [hasta, setHasta] = useState(hoy)

  const { data, isFetching, refetch } = useQuery<GeograficoResp>({
    queryKey: ['heatmap-geo', desde, hasta],
    queryFn: () => api.get(`/v1/heatmap/geografico?fecha_desde=${desde}&fecha_hasta=${hasta}`).then(r => r.data),
    staleTime: 5 * 60_000,
  })

  // Inicializar Leaflet una sola vez
  useEffect(() => {
    if (!mapRef.current) return
    if (leafletRef.current) return

    // Cargar CSS de Leaflet dinámicamente
    if (!document.getElementById('leaflet-css')) {
      const link = document.createElement('link')
      link.id   = 'leaflet-css'
      link.rel  = 'stylesheet'
      link.href = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css'
      document.head.appendChild(link)
    }

    const script = document.createElement('script')
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js'
    script.onload = () => {
      const L = (window as unknown as { L: unknown }) as { L: {
        map: (el: HTMLElement, opts: object) => { setView: (ll: [number,number], z: number) => void; fitBounds?: (b: unknown) => void }
        tileLayer: (url: string, opts: object) => { addTo: (m: unknown) => void }
        circleMarker: (ll: [number,number], opts: object) => { addTo: (m: unknown) => { bindPopup: (s: string) => unknown } }
        latLngBounds: (pts: [number,number][]) => unknown
      } }
      if (!mapRef.current) return
      const map = L.L.map(mapRef.current, { zoomControl: true, scrollWheelZoom: true })
      L.L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
        maxZoom: 18,
      }).addTo(map)
      map.setView([-9.189967, -75.015152], 6)
      leafletRef.current = { map, markers: [] }
    }
    document.body.appendChild(script)
    return () => { /* no cleanup: mapa persiste en el DOM */ }
  }, [])

  // Actualizar marcadores cuando cambian los datos
  useEffect(() => {
    if (!leafletRef.current || !data) return
    const L = (window as unknown as { L: { circleMarker: (ll: [number,number], o: object) => { addTo: (m: unknown) => { bindPopup: (s: string) => unknown } }; latLngBounds: (p: [number,number][]) => { isValid?: () => boolean } } }).L
    if (!L) return

    const { map, markers } = leafletRef.current as { map: { fitBounds?: (b: unknown, o?: object) => void }, markers: { remove: () => void }[] }
    markers.forEach(m => m.remove())
    leafletRef.current.markers = []

    const pts: [number, number][] = []
    data.tiendas.forEach(t => {
      if (!t.lat || !t.lng) return
      const ratio = data.max_valor > 0 ? t.valor / data.max_valor : 0
      const r = 8 + ratio * 22
      const color = colorCalor(ratio)
      const m = L.circleMarker([t.lat, t.lng], {
        radius: r,
        fillColor: color,
        fillOpacity: 0.85,
        color: 'var(--color-kyro-border)',
        weight: 1,
      }).addTo(map).bindPopup(
        `<b style="color:var(--color-kyro-indigo)">${t.nombre}</b><br>
         Ventas: ${t.ventas_count}<br>
         Bipay neto: S/ ${t.bipay_neto.toFixed(2)}<br>
         Bipay líneas: ${t.bipay_lineas}`
      )
      ;(leafletRef.current as { markers: unknown[] }).markers.push(m as unknown as { remove: () => void })
      pts.push([t.lat, t.lng])
    })

    if (pts.length > 0 && map.fitBounds) {
      const bounds = L.latLngBounds(pts)
      map.fitBounds(bounds, { padding: [30, 30] } as object)
    }
  }, [data])

  return (
    <div>
      {/* Filtros */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Desde</label>
          <input type="date" value={desde} onChange={e => setDesde(e.target.value)}
            className="kyro-input h-9 rounded-[10px] px-2 text-xs" />
        </div>
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Hasta</label>
          <input type="date" value={hasta} onChange={e => setHasta(e.target.value)}
            className="kyro-input h-9 rounded-[10px] px-2 text-xs" />
        </div>
        <button onClick={() => refetch()} disabled={isFetching}
          className="flex h-9 items-center gap-1.5 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 text-xs font-medium text-kyro-muted transition-all hover:border-kyro-indigo/40 hover:text-kyro-indigo">
          <RefreshCw size={12} className={isFetching ? 'animate-spin' : ''} />
          {isFetching ? 'Cargando…' : 'Actualizar'}
        </button>
        {data && (
          <span className="text-xs text-kyro-muted ml-auto">
            {data.tiendas.length} tiendas con coordenadas
          </span>
        )}
      </div>

      {data?.tiendas.length === 0 && (
        <div className="text-center py-12 text-kyro-muted text-sm">
          <MapPin size={32} className="mx-auto mb-2 opacity-30" />
          Ninguna tienda tiene coordenadas configuradas.
          <br />
          <span className="text-xs">Agrega lat/lon en el módulo Tiendas.</span>
        </div>
      )}

      {/* Mapa Leaflet */}
      <div ref={mapRef} className="h-[480px] overflow-hidden rounded-[18px] border border-kyro-border" />

      {/* Ranking lateral */}
      {data && data.tiendas.length > 0 && (
        <div className="mt-4 grid grid-cols-1 gap-2">
          <p className="text-xs font-semibold text-kyro-muted uppercase tracking-wider">Ranking del período</p>
          {data.tiendas.slice(0, 8).map((t, i) => {
            const ratio = data.max_valor > 0 ? t.valor / data.max_valor : 0
            return (
              <div key={t.codigo} className="flex items-center gap-3 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 py-2">
                <span className="text-[10px] font-mono w-5 text-kyro-muted">{i + 1}</span>
                <div className="flex-1 min-w-0">
                  <p className="text-xs font-semibold text-kyro-text truncate">{t.nombre}</p>
                  <p className="text-[10px] text-kyro-muted">
                    {t.ventas_count} ventas · Bipay S/ {t.bipay_neto.toFixed(0)}
                  </p>
                </div>
                <div className="h-1.5 w-24 overflow-hidden rounded-full bg-kyro-border">
                  <div className="h-full rounded-full" style={{ width: `${ratio * 100}%`, background: colorCalor(ratio) }} />
                </div>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

// ── TAB 3: Horario ────────────────────────────────────────────────────────────

const DIAS_LABEL = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

function TabHorario() {
  const hoy = new Date().toISOString().slice(0, 10)
  const treintaAtras = new Date(Date.now() - 30 * 86400_000).toISOString().slice(0, 10)

  const [desde, setDesde] = useState(treintaAtras)
  const [hasta, setHasta] = useState(hoy)
  const [hover, setHover] = useState<CeldaHorario | null>(null)

  const { data, isFetching, refetch } = useQuery<HorarioResp>({
    queryKey: ['heatmap-horario', desde, hasta],
    queryFn: () => api.get(`/v1/heatmap/horario?fecha_desde=${desde}&fecha_hasta=${hasta}`).then(r => r.data),
    staleTime: 5 * 60_000,
  })

  // Construir grid: 24 horas × 7 días
  const gridMap = new Map<string, CeldaHorario>()
  data?.grid.forEach(c => gridMap.set(`${c.hora}-${c.dia}`, c))

  const maxCnt = data?.max_cnt ?? 1

  const esFallback = data?.fuente === 'reportes_fallback'

  return (
    <div>
      {/* Controles */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Desde</label>
          <input type="date" value={desde} onChange={e => setDesde(e.target.value)}
            className="kyro-input h-9 rounded-[10px] px-2 text-xs" />
        </div>
        <div className="flex items-center gap-2">
          <label className="text-xs text-kyro-muted">Hasta</label>
          <input type="date" value={hasta} onChange={e => setHasta(e.target.value)}
            className="kyro-input h-9 rounded-[10px] px-2 text-xs" />
        </div>
        <button onClick={() => refetch()} disabled={isFetching}
          className="flex h-9 items-center gap-1.5 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 text-xs font-medium text-kyro-muted transition-all hover:border-kyro-indigo/40 hover:text-kyro-indigo">
          <RefreshCw size={12} className={isFetching ? 'animate-spin' : ''} />
          {isFetching ? 'Cargando…' : 'Actualizar'}
        </button>
      </div>

      {esFallback && (
        <p className="mb-3 rounded-[10px] border border-kyro-warning/20 bg-kyro-warning/10 px-3 py-2 text-[11px] text-kyro-warning">
          ℹ️ Tabla <code>bitel_operaciones_detalle</code> no disponible — mostrando distribución por día de semana de reportes ERP.
        </p>
      )}

      {/* Tooltip */}
      {hover && !esFallback && (
        <div className="mb-3 inline-flex gap-4 rounded-[10px] border border-kyro-border bg-kyro-elevated px-3 py-2 text-xs tabular-nums shadow-kyro-card">
          <span className="text-kyro-muted">{DIAS_LABEL[hover.dia]} {String(hover.hora).padStart(2, '0')}:00</span>
          <span className="text-kyro-text">Operaciones: <b>{hover.cnt}</b></span>
          <span className="text-kyro-info">Monto: <b>S/ {hover.monto_abs.toFixed(2)}</b></span>
        </div>
      )}

      {/* Grid hora × día */}
      <div className="overflow-x-auto">
        {esFallback ? (
          // Fallback: solo barra por día de semana
          <div className="flex flex-col gap-2 max-w-sm">
            {DIAS_LABEL.map((dia, dIdx) => {
              const celda = gridMap.get(`0-${dIdx}`)
              const cnt = celda?.cnt ?? 0
              const ratio = maxCnt > 0 ? cnt / maxCnt : 0
              return (
                <div key={dIdx} className="flex items-center gap-3">
                  <span className="text-xs text-kyro-muted w-8">{dia}</span>
                  <div className="relative h-7 flex-1 overflow-hidden rounded-md bg-kyro-elevated">
                    <div className="h-full rounded-md transition-all"
                      style={{ width: `${ratio * 100}%`, background: colorCalor(ratio) }} />
                    <span className="absolute right-2 top-1/2 -translate-y-1/2 text-xs font-semibold text-kyro-text">
                      {cnt}
                    </span>
                  </div>
                </div>
              )
            })}
          </div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: '30px repeat(24, 1fr)', gap: 2, minWidth: 600 }}>
            {/* Header horas */}
            <div />
            {Array.from({ length: 24 }, (_, h) => (
              <div key={h} className="text-center text-[9px] text-kyro-muted pb-1"
                style={{ lineHeight: '1' }}>{String(h).padStart(2,'0')}</div>
            ))}
            {/* Filas por día */}
            {DIAS_LABEL.map((dia, dIdx) => (
              <>
                <div key={`lbl-${dIdx}`} className="text-[10px] text-kyro-muted flex items-center pr-1">{dia}</div>
                {Array.from({ length: 24 }, (_, h) => {
                  const celda = gridMap.get(`${h}-${dIdx}`)
                  const cnt = celda?.cnt ?? 0
                  const ratio = maxCnt > 0 ? cnt / maxCnt : 0
                  return (
                    <div key={`${h}-${dIdx}`}
                      style={{
                        height: 22, borderRadius: 3,
                        background: colorCalor(ratio),
                        cursor: celda ? 'pointer' : 'default',
                      }}
                      onMouseEnter={() => setHover(celda ?? null)}
                      onMouseLeave={() => setHover(null)}
                    />
                  )
                })}
              </>
            ))}
          </div>
        )}
      </div>

      {/* Leyenda */}
      <div className="flex items-center gap-2 mt-4">
        <span className="text-[10px] text-kyro-muted">Menos actividad</span>
        {[0, 0.2, 0.4, 0.6, 0.8, 1].map(r => (
          <div key={r} style={{ width: 16, height: 16, borderRadius: 3, background: colorCalor(r) }} />
        ))}
        <span className="text-[10px] text-kyro-muted">Más actividad</span>
      </div>
    </div>
  )
}

// ── Página principal ──────────────────────────────────────────────────────────

const TABS = [
  { id: 'calendario', label: 'Calendario' },
  { id: 'geografico', label: 'Geográfico' },
  { id: 'horario',    label: 'Por Hora' },
]

export default function MapaCalorPage() {
  const [tab, setTab] = useState('calendario')
  const [year, setYear] = useState(new Date().getFullYear())

  return (
    <div className="mx-auto max-w-5xl space-y-5 px-4 py-6">
      <PageHeader
        title="Mapa de Calor"
        subtitle="Análisis de actividad por tiempo y ubicación"
      />

      <GlassPanel className="kyro-card rounded-[18px] p-5">
        <div className="flex items-center gap-3 mb-5 flex-wrap">
          <PageTabs tabs={TABS} active={tab} onChange={setTab} />
          <div className="ml-auto flex items-center gap-1.5 text-xs text-kyro-muted">
            {tab === 'calendario' && <><CalendarDays size={13} /> Vista anual</>}
            {tab === 'geografico' && <><MapPin size={13} /> Vista geográfica</>}
            {tab === 'horario'    && <><Clock size={13} /> Vista horaria</>}
          </div>
        </div>

        {tab === 'calendario' && <TabCalendario year={year} setYear={setYear} />}
        {tab === 'geografico' && <TabGeografico />}
        {tab === 'horario'    && <TabHorario />}
      </GlassPanel>
    </div>
  )
}

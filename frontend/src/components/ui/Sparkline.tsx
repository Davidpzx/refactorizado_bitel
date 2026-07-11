interface SparklineProps {
  /** Serie de valores en orden cronológico. Con menos de 2 puntos no se renderiza. */
  data: number[]
  /** Color del trazo. Por defecto hereda `currentColor` del contenedor. */
  stroke?: string
  width?: number
  height?: number
  /** Relleno degradado bajo la línea (área). */
  fill?: boolean
  className?: string
}

let gradientSeq = 0

/**
 * Mini-gráfica de tendencia SVG para KPI cards (sin librerías).
 * Curva suavizada Catmull-Rom→Bézier con área degradada opcional,
 * escalada al min/max de la serie con padding vertical del 12%.
 */
export function Sparkline({
  data,
  stroke = 'currentColor',
  width = 96,
  height = 28,
  fill = true,
  className = '',
}: SparklineProps) {
  if (data.length < 2) return null

  const min = Math.min(...data)
  const max = Math.max(...data)
  const range = max - min || 1
  const pad = height * 0.12

  const pts = data.map((v, i) => ({
    x: (i / (data.length - 1)) * width,
    y: height - pad - ((v - min) / range) * (height - pad * 2),
  }))

  // Catmull-Rom → cubic Bézier para una curva suave que pasa por todos los puntos.
  let d = `M ${pts[0].x.toFixed(2)} ${pts[0].y.toFixed(2)}`
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] ?? pts[i]
    const p1 = pts[i]
    const p2 = pts[i + 1]
    const p3 = pts[i + 2] ?? p2
    const c1x = p1.x + (p2.x - p0.x) / 6
    const c1y = p1.y + (p2.y - p0.y) / 6
    const c2x = p2.x - (p3.x - p1.x) / 6
    const c2y = p2.y - (p3.y - p1.y) / 6
    d += ` C ${c1x.toFixed(2)} ${c1y.toFixed(2)}, ${c2x.toFixed(2)} ${c2y.toFixed(2)}, ${p2.x.toFixed(2)} ${p2.y.toFixed(2)}`
  }

  const gradId = `spark-g-${++gradientSeq}`
  const last = pts[pts.length - 1]

  return (
    <svg
      viewBox={`0 0 ${width} ${height}`}
      width={width}
      height={height}
      className={className}
      aria-hidden
      focusable="false"
    >
      {fill && (
        <>
          <defs>
            <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={stroke} stopOpacity="0.22" />
              <stop offset="100%" stopColor={stroke} stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={`${d} L ${width} ${height} L 0 ${height} Z`} fill={`url(#${gradId})`} stroke="none" />
        </>
      )}
      <path d={d} fill="none" stroke={stroke} strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={last.x} cy={last.y} r="2.25" fill={stroke} />
    </svg>
  )
}

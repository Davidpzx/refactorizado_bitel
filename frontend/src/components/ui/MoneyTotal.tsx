export function MoneyTotal({ value, color = '#22d3ee', size = '2rem' }: {
  value: number | string | null | undefined; color?: string; size?: string
}) {
  // La API serializa columnas DECIMAL como string; coaccionar evita "toFixed is not a function".
  const n = typeof value === 'number' ? value : Number(value)
  const safe = Number.isFinite(n) ? n : 0
  return (
    <span style={{ fontFamily: "'Orbitron', monospace", color, fontSize: size, fontWeight: 700 }}>
      S/ {safe.toFixed(2)}
    </span>
  )
}

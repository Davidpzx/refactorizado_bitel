export function MoneyTotal({ value, color = '#22d3ee', size = '2rem' }: {
  value: number; color?: string; size?: string
}) {
  return (
    <span style={{ fontFamily: "'Orbitron', monospace", color, fontSize: size, fontWeight: 700 }}>
      S/ {value.toFixed(2)}
    </span>
  )
}

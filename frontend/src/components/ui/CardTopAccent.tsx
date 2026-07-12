export type TopAccentColor = 'cyan' | 'amber' | 'purple' | 'orange' | 'gold' | 'indigo' | 'green'

const topAccentClasses: Record<TopAccentColor, string> = {
  cyan:   'bg-cyan-400 shadow-[0_0_10px_1px_rgba(34,211,238,0.6)]',
  amber:  'bg-amber-400 shadow-[0_0_10px_1px_rgba(251,191,36,0.6)]',
  purple: 'bg-violet-500 shadow-[0_0_10px_1px_rgba(139,92,246,0.6)]',
  orange: 'bg-orange-500 shadow-[0_0_10px_1px_rgba(249,115,22,0.6)]',
  gold:   'bg-kyro-gold shadow-[0_0_10px_1px_rgba(255,194,0,0.55)]',
  indigo: 'bg-indigo-500 shadow-[0_0_10px_1px_rgba(99,102,241,0.55)]',
  green:  'bg-emerald-400 shadow-[0_0_10px_1px_rgba(34,197,94,0.6)]',
}

/** Hairline superior de color por card. Requiere que el contenedor sea `relative overflow-hidden`. */
export function CardTopAccent({ color = 'indigo' }: { color?: TopAccentColor }) {
  return <div aria-hidden className={`absolute inset-x-0 top-0 z-10 h-[3px] ${topAccentClasses[color]}`} />
}

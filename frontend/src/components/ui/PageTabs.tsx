import type { ComponentType } from 'react'
import { useTheme } from '../../hooks/useTheme'

interface Tab {
  id: string
  label: string
  count?: number
  icon?: ComponentType<{ size?: number | string; className?: string }>
}

interface PageTabsProps {
  tabs: Tab[]
  active: string
  onChange: (id: string) => void
  className?: string
}

export function PageTabs({ tabs, active, onChange, className = '' }: PageTabsProps) {
  const { isDark } = useTheme()

  return (
    <div
      className={`inline-flex items-center gap-1 rounded-lg p-1 ${
        isDark ? 'bg-zinc-800/60' : 'bg-gray-100'
      } ${className}`}
    >
      {tabs.map((tab) => {
        const isActive = tab.id === active
        const Icon = tab.icon
        return (
          <button
            key={tab.id}
            onClick={() => onChange(tab.id)}
            className={[
              'inline-flex items-center px-4 py-1.5 rounded-md text-sm font-medium transition-all',
              isActive
                ? 'bg-[#ffc200] text-[#1a1a1a] font-semibold'
                : isDark
                  ? 'text-zinc-400 hover:text-zinc-200 hover:bg-zinc-700/50'
                  : 'text-gray-500 hover:text-gray-700 hover:bg-gray-200',
            ].join(' ')}
          >
            {Icon && <Icon size={15} className="mr-1.5" />}
            {tab.label}
            {tab.count !== undefined && tab.count > 0 && (
              <span className="ml-1.5 bg-red-500 text-white text-[9px] font-bold rounded-full px-1 min-w-[16px] h-[16px] flex items-center justify-center">
                {tab.count > 99 ? '99+' : tab.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

import type { ReactNode } from 'react'

interface PageHeaderProps {
  title: string
  description?: string
  subtitle?: string
  actions?: ReactNode
  children?: ReactNode
}

export function PageHeader({ title, description, subtitle, actions, children }: PageHeaderProps) {
  const sub = subtitle ?? description
  const slot = children ?? actions
  return (
    <div className="flex items-start justify-between mb-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
        {sub && <p className="mt-1 text-sm text-gray-500">{sub}</p>}
      </div>
      {slot && <div className="flex items-center gap-2">{slot}</div>}
    </div>
  )
}

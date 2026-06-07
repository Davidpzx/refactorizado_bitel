import { useAuth } from '../hooks/useAuth'

export function DashboardPage() {
  const { usuario } = useAuth()

  return (
    <div className="space-y-6">
      <div>
        <h2 className="text-xl font-semibold text-gray-800">
          Bienvenido, {usuario?.nombre ?? '—'}
        </h2>
        <p className="text-sm text-gray-500 mt-1">
          {usuario?.rol} · Tienda {usuario?.tienda_id}
        </p>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {[
          { label: 'Nuevo Reporte', desc: 'Registrar ventas del día', href: '/reportes/nuevo', color: 'blue' },
          { label: 'Inventario',    desc: 'Ver stock de tienda',       href: '/inventario',    color: 'green' },
          { label: 'Planilla',      desc: 'Comisiones y pagos',        href: '/planilla',      color: 'purple' },
        ].map((item) => (
          <a
            key={item.href}
            href={item.href}
            className={`block p-5 bg-white rounded-xl border border-gray-200 hover:border-${item.color}-300 hover:shadow-sm transition-all group`}
          >
            <p className={`text-sm font-semibold text-${item.color}-600 group-hover:text-${item.color}-700`}>
              {item.label}
            </p>
            <p className="text-xs text-gray-500 mt-1">{item.desc}</p>
          </a>
        ))}
      </div>

      <div className="bg-white rounded-xl border border-gray-200 p-5">
        <p className="text-sm font-medium text-gray-700 mb-3">Estado del sistema</p>
        <div className="flex items-center gap-2 text-sm text-green-600">
          <span className="w-2 h-2 bg-green-500 rounded-full inline-block"></span>
          API conectada · BD migracion
        </div>
      </div>
    </div>
  )
}

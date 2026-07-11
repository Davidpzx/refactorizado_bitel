import { useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck, GridFour, Calculator, Camera, MapPinLine } from '@phosphor-icons/react'
import { adminPaginasApi } from '../../services/adminPaginas.api'
import { useAuth } from '../../hooks/useAuth'
import { PageTabs } from '../../components/ui/PageTabs'

const RUTAS = [
  { id: '/asistencias', label: 'Gestión', icon: CalendarCheck },
  { id: '/asistencias/control', label: 'Control mensual', icon: GridFour },
  { id: '/asistencias/liquidacion', label: 'Liquidación', icon: Calculator },
  { id: '/asistencias/presencia', label: 'Presencia', icon: MapPinLine },
  { id: '/revisar-fotos', label: 'Revisar fotos', icon: Camera },
] as const

/** Pestañas de navegación entre las 4 vistas de asistencias, calcando el legacy `panel_asistencias.php`. */
export function AsistenciasTabs() {
  const navigate = useNavigate()
  const location = useLocation()
  const { usuario } = useAuth()

  const { data: fotosPendientes } = useQuery({
    queryKey: ['fotos-pendientes'],
    queryFn: () => adminPaginasApi.fotosPendientes(),
    enabled: usuario?.rol === 'admin',
    staleTime: 30_000,
  })
  const fotosCount = fotosPendientes?.total ?? 0

  const activo = RUTAS.find(r => r.id === location.pathname)?.id ?? RUTAS[0].id

  return (
    <PageTabs
      tabs={RUTAS.map(r => ({
        id: r.id,
        label: r.label,
        icon: r.icon,
        count: r.id === '/revisar-fotos' ? fotosCount : undefined,
      }))}
      active={activo}
      onChange={(id) => navigate(id)}
    />
  )
}

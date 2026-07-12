import { useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { CalendarCheck, GridFour, Calculator, Camera, MapPinLine } from '@phosphor-icons/react'
import { adminPaginasApi } from '../../services/adminPaginas.api'
import { useAuth } from '../../hooks/useAuth'
import { esAdminOGerente } from '../../utils/roles'
import { PageTabs } from '../../components/ui/PageTabs'

// `soloAdmin` marca las pestañas que la matriz (plan/16) reserva a admin/gerente:
// Liquidación = descuentos de nómina (JT ❌) y Revisar fotos = modificar asistencia
// (JT ❌). El jefe de tienda ve Gestión/Control/Presencia en solo lectura.
const RUTAS = [
  { id: '/asistencias', label: 'Gestión', icon: CalendarCheck },
  { id: '/asistencias/control', label: 'Control mensual', icon: GridFour },
  { id: '/asistencias/liquidacion', label: 'Liquidación', icon: Calculator, soloAdmin: true },
  { id: '/asistencias/presencia', label: 'Presencia', icon: MapPinLine },
  { id: '/revisar-fotos', label: 'Revisar fotos', icon: Camera, soloAdmin: true },
] as const

/** Pestañas de navegación entre las 4 vistas de asistencias, calcando el legacy `panel_asistencias.php`. */
export function AsistenciasTabs() {
  const navigate = useNavigate()
  const location = useLocation()
  const { usuario } = useAuth()

  const { data: fotosPendientes } = useQuery({
    queryKey: ['fotos-pendientes'],
    queryFn: () => adminPaginasApi.fotosPendientes(),
    enabled: esAdminOGerente(usuario),
    staleTime: 30_000,
  })
  const fotosCount = fotosPendientes?.total ?? 0

  const puedeModificar = esAdminOGerente(usuario)
  const rutasVisibles = RUTAS.filter(r => !('soloAdmin' in r && r.soloAdmin) || puedeModificar)

  const activo = rutasVisibles.find(r => r.id === location.pathname)?.id ?? rutasVisibles[0].id

  return (
    <PageTabs
      tabs={rutasVisibles.map(r => ({
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

import { useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Package, ArrowsLeftRight as ArrowLeftRight, Scroll as ScrollText, Cpu } from '@phosphor-icons/react'
import { controlCenterApi } from '../../services/controlCenter.api'
import { useAuth } from '../../hooks/useAuth'
import { esAdminOGerente } from '../../utils/roles'
import { PageTabs } from '../../components/ui/PageTabs'

const RUTAS = [
  { id: '/inventario', label: 'Ver Inventario', icon: Package, adminOnly: false },
  { id: '/traslados', label: 'Traslados', icon: ArrowLeftRight, adminOnly: false },
  { id: '/inventario/kardex', label: 'Kardex', icon: ScrollText, adminOnly: true },
  { id: '/chips-gestion', label: 'Gestión Chips', icon: Cpu, adminOnly: false },
] as const

/** Pestañas de navegación entre las 4 vistas de inventario, calcando el legacy `ver_inventario.php`
 *  (allí Traslados/Kardex son modales y Chips es un filtro interno de la misma página). */
export function InventarioTabs() {
  const navigate = useNavigate()
  const location = useLocation()
  const { usuario } = useAuth()

  const { data: cc } = useQuery({
    queryKey: ['control-center'],
    queryFn: () => controlCenterApi.get(),
    staleTime: 25_000,
    enabled: esAdminOGerente(usuario),
  })
  const trasladosCount = cc?.traslados_pendientes.count ?? 0
  const rutasVisibles = RUTAS.filter(r => !r.adminOnly || esAdminOGerente(usuario))

  const activo = rutasVisibles.find(r => r.id === location.pathname)?.id ?? rutasVisibles[0].id

  return (
    <PageTabs
      tabs={rutasVisibles.map(r => ({
        id: r.id,
        label: r.label,
        icon: r.icon,
        count: r.id === '/traslados' ? trasladosCount : undefined,
      }))}
      active={activo}
      onChange={(id) => navigate(id)}
    />
  )
}

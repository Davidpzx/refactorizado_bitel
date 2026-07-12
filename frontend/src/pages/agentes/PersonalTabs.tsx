import { useNavigate, useLocation } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { IdentificationCard as IdCard, UserCheck } from '@phosphor-icons/react'
import { controlCenterApi } from '../../services/controlCenter.api'
import { useAuth } from '../../hooks/useAuth'
import { esAdminOGerente } from '../../utils/roles'
import { PageTabs } from '../../components/ui/PageTabs'

const RUTAS = [
  { id: '/agentes', label: 'Agentes', icon: IdCard },
  { id: '/admin/postulaciones', label: 'Postulantes', icon: UserCheck },
] as const

/** Pestañas de navegación entre Agentes y Postulantes, calcando el legacy `gestionar_agentes.php`
 *  (allí la revisión de postulantes vive dentro de la misma página, vía modales). */
export function PersonalTabs() {
  const navigate = useNavigate()
  const location = useLocation()
  const { usuario } = useAuth()

  const { data: cc } = useQuery({
    queryKey: ['control-center'],
    queryFn: () => controlCenterApi.get(),
    staleTime: 25_000,
    enabled: esAdminOGerente(usuario),
  })
  const postulantesCount = cc?.postulantes_pendientes.count ?? 0

  const activo = RUTAS.find(r => r.id === location.pathname)?.id ?? RUTAS[0].id

  return (
    <PageTabs
      tabs={RUTAS.map(r => ({
        id: r.id,
        label: r.label,
        icon: r.icon,
        count: r.id === '/admin/postulaciones' ? postulantesCount : undefined,
      }))}
      active={activo}
      onChange={(id) => navigate(id)}
    />
  )
}

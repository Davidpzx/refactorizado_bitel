import type { Usuario } from '../../types/auth'
import { normalizarRol } from '../../utils/roles'
import { EstadisticasAdmin } from './estadisticas/EstadisticasAdmin'
import { EstadisticasGerente } from './estadisticas/EstadisticasGerente'
import { EstadisticasJefeTienda } from './estadisticas/EstadisticasJefeTienda'

export function CrmEstadisticasTab({ usuario }: { usuario: Usuario | null }) {
  const rol = normalizarRol(usuario?.rol)

  if (rol === 'gerente') return <EstadisticasGerente />
  if (rol === 'jefe_tienda') return <EstadisticasJefeTienda />

  return <EstadisticasAdmin />
}

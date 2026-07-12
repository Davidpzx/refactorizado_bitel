// Helpers de rol (plan/16-plan-roles.md — modelo de 4 roles).
//
// Migración retrocompatible: el backend puede devolver aún los roles viejos
// ('admin' / 'tienda') para usuarios no migrados. Estos helpers normalizan
// los alias en un solo lugar para que el resto del cliente nunca compare
// contra el string crudo de `usuario.rol`.
//   admin  ≡ administrador
//   tienda ≡ jefe_tienda
import type { Usuario } from '../types/auth'

export type RolCanonico = 'administrador' | 'gerente' | 'jefe_tienda' | 'agente'

const ALIAS_ROL: Record<string, RolCanonico> = {
  admin: 'administrador',
  administrador: 'administrador',
  gerente: 'gerente',
  tienda: 'jefe_tienda',
  jefe_tienda: 'jefe_tienda',
  agente: 'agente',
}

/** Normaliza cualquier valor de rol (nuevo o legacy) a uno de los 4 roles canónicos. */
export function normalizarRol(rol: string | null | undefined): RolCanonico | null {
  if (!rol) return null
  return ALIAS_ROL[rol] ?? null
}

export function esAdministrador(usuario: Pick<Usuario, 'rol'> | null | undefined): boolean {
  return normalizarRol(usuario?.rol) === 'administrador'
}

export function esGerente(usuario: Pick<Usuario, 'rol'> | null | undefined): boolean {
  return normalizarRol(usuario?.rol) === 'gerente'
}

export function esJefeTienda(usuario: Pick<Usuario, 'rol'> | null | undefined): boolean {
  return normalizarRol(usuario?.rol) === 'jefe_tienda'
}

export function esAgente(usuario: Pick<Usuario, 'rol'> | null | undefined): boolean {
  return normalizarRol(usuario?.rol) === 'agente'
}

/** true si el usuario tiene alguno de los roles dados (acepta alias legacy en `roles`). */
export function tieneAlgunRol(usuario: Pick<Usuario, 'rol'> | null | undefined, roles: string[]): boolean {
  const propio = normalizarRol(usuario?.rol)
  if (!propio) return false
  return roles.some((r) => normalizarRol(r) === propio)
}

/** Administrador y Gerente operan el negocio a nivel global: agrupa el check usado en la
 *  mayoría de gates de "acciones sensibles" (asistencias-modificar, aprobar traslados, etc). */
export function esAdminOGerente(usuario: Pick<Usuario, 'rol'> | null | undefined): boolean {
  return esAdministrador(usuario) || esGerente(usuario)
}

export const ROL_LABELS: Record<RolCanonico, string> = {
  administrador: 'Administrador',
  gerente: 'Gerente',
  jefe_tienda: 'Jefe de Tienda',
  agente: 'Agente de Ventas',
}

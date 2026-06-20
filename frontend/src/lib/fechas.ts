// Utilidades de fecha compartidas (SRP): el backend serializa los campos
// `date`/`datetime` de Laravel como ISO completo (p. ej. "2026-01-15T00:00:00.000000Z"),
// no como "YYYY-MM-DD". Si se concatena texto encima a ciegas, el resultado es
// "Invalid Date". Estas funciones extraen siempre los primeros 10 caracteres
// (que son la fecha pura en cualquiera de los dos formatos) antes de usarla.

/** Extrae "YYYY-MM-DD" de cualquier fecha que venga del backend. '' si no hay valor. */
export function soloFecha(valor: string | null | undefined): string {
  if (!valor) return ''
  return valor.slice(0, 10)
}

/** Formatea una fecha del backend para mostrarla en pantalla (es-PE). '—' si no es válida. */
export function formatearFechaCorta(valor: string | null | undefined): string {
  const fecha = soloFecha(valor)
  if (!fecha) return '—'
  const date = new Date(`${fecha}T00:00:00`)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleDateString('es-PE')
}

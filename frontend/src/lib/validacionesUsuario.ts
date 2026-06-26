// Reglas de validación y sanitización para el formulario de usuarios del sistema.
// Responsabilidad única (SRP): definir qué es un valor válido, sin saber nada de UI.

export const LIMITES_USUARIO = {
  nombre: 60,
  email: 50,
} as const

const REGEX_NOMBRE = /^[A-Za-zÁÉÍÓÚáéíóúÑñ\s'-]*$/
const REGEX_EMAIL  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** Filtra caracteres mientras el usuario escribe el nombre: solo letras, espacios, apóstrofes y guiones. */
export function sanitizarNombreUsuario(valor: string): string {
  return valor
    .replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ\s'-]/g, '')
    .slice(0, LIMITES_USUARIO.nombre)
}

export interface FormularioUsuario {
  nombre: string
  email: string
  password: string
  rol: 'admin' | 'tienda'
  tienda_id: string
  agente_id: string
}

export interface ErroresUsuario {
  nombre?: string
  email?: string
  password?: string
  tienda_id?: string
  agente_id?: string
}

/** Valida el formulario completo. `esEdicion` relaja la contraseña (opcional al editar). */
export function validarUsuario(form: FormularioUsuario, esEdicion: boolean): ErroresUsuario {
  const errores: ErroresUsuario = {}

  if (!form.nombre.trim()) {
    errores.nombre = 'El nombre es obligatorio.'
  } else if (!REGEX_NOMBRE.test(form.nombre)) {
    errores.nombre = 'El nombre no debe contener números ni símbolos.'
  } else if (form.nombre.length > LIMITES_USUARIO.nombre) {
    errores.nombre = `Máximo ${LIMITES_USUARIO.nombre} caracteres.`
  }

  if (!form.email.trim()) {
    errores.email = 'El email es obligatorio.'
  } else if (form.email.length > LIMITES_USUARIO.email) {
    errores.email = `Máximo ${LIMITES_USUARIO.email} caracteres.`
  } else if (!REGEX_EMAIL.test(form.email)) {
    errores.email = 'Ingresa un email válido.'
  }

  if (!esEdicion && !form.password.trim()) {
    errores.password = 'La contraseña es obligatoria.'
  } else if (form.password && form.password.length < 6) {
    errores.password = 'Mínimo 6 caracteres.'
  }

  return errores
}

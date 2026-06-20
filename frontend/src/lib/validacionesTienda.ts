// Reglas de validación y sanitización para el formulario de tiendas.
// Responsabilidad única (SRP): definir qué es un valor válido, sin saber nada de UI.
// No toca el backend ni la base de datos — el backend mantiene sus propios límites.

export const LIMITES_TIENDA = {
  codigo: 11,
  nombre: 40,
  direccion: 120,
  telefono: 20,
} as const

const REGEX_CODIGO    = /^[A-Z0-9]*$/
const REGEX_NOMBRE    = /^[A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .-]*$/
const REGEX_DIRECCION = /^[A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .,#-]*$/
const REGEX_TELEFONO  = /^[0-9+() -]*$/

function limitarOcurrencias(texto: string, caracter: string, max: number): string {
  let contador = 0
  return texto
    .split('')
    .filter((c) => {
      if (c !== caracter) return true
      contador += 1
      return contador <= max
    })
    .join('')
}

/** Filtra caracteres mientras el usuario escribe el código (solo letras/números, mayúsculas). */
export function sanitizarCodigo(valor: string): string {
  return valor
    .toUpperCase()
    .replace(/[^A-Z0-9]/g, '')
    .slice(0, LIMITES_TIENDA.codigo)
}

/** Filtra caracteres mientras el usuario escribe el nombre. */
export function sanitizarNombre(valor: string): string {
  return valor
    .replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .-]/g, '')
    .slice(0, LIMITES_TIENDA.nombre)
}

/** Filtra caracteres mientras el usuario escribe la dirección. */
export function sanitizarDireccion(valor: string): string {
  return valor
    .replace(/[^A-Za-zÁÉÍÓÚáéíóúÑñ0-9 .,#-]/g, '')
    .slice(0, LIMITES_TIENDA.direccion)
}

/** Filtra caracteres del teléfono: dígitos, un "+" (solo al inicio), hasta 2 paréntesis y 3 guiones. */
export function sanitizarTelefono(valor: string): string {
  let limpio = valor.replace(/[^0-9+() -]/g, '')

  const teniaPlus = limpio.startsWith('+')
  limpio = limpio.replace(/\+/g, '')
  if (teniaPlus) limpio = `+${limpio}`

  limpio = limitarOcurrencias(limpio, '(', 2)
  limpio = limitarOcurrencias(limpio, ')', 2)
  limpio = limitarOcurrencias(limpio, '-', 3)

  return limpio.slice(0, LIMITES_TIENDA.telefono)
}

export interface ErroresTienda {
  codigo?: string
  nombre?: string
  direccion?: string
  telefono?: string
}

export interface FormularioTienda {
  codigo: string
  nombre: string
  direccion: string
  telefono: string
}

/** Valida el formulario completo. Devuelve un objeto vacío si todo está bien. */
export function validarTienda(form: FormularioTienda): ErroresTienda {
  const errores: ErroresTienda = {}

  if (!form.codigo.trim()) {
    errores.codigo = 'El código es obligatorio.'
  } else if (!REGEX_CODIGO.test(form.codigo)) {
    errores.codigo = 'Solo letras y números, sin espacios ni símbolos.'
  } else if (form.codigo.length > LIMITES_TIENDA.codigo) {
    errores.codigo = `Máximo ${LIMITES_TIENDA.codigo} caracteres.`
  }

  if (!form.nombre.trim()) {
    errores.nombre = 'El nombre es obligatorio.'
  } else if (!REGEX_NOMBRE.test(form.nombre)) {
    errores.nombre = 'Solo letras, números, espacios, "." y "-".'
  } else if (form.nombre.length > LIMITES_TIENDA.nombre) {
    errores.nombre = `Máximo ${LIMITES_TIENDA.nombre} caracteres.`
  }

  if (form.direccion) {
    if (!REGEX_DIRECCION.test(form.direccion)) {
      errores.direccion = 'Contiene caracteres no permitidos.'
    } else if (form.direccion.length > LIMITES_TIENDA.direccion) {
      errores.direccion = `Máximo ${LIMITES_TIENDA.direccion} caracteres.`
    }
  }

  if (form.telefono) {
    if (!REGEX_TELEFONO.test(form.telefono)) {
      errores.telefono = 'Solo números, "+", paréntesis, guiones y espacios.'
    } else if (form.telefono.length > LIMITES_TIENDA.telefono) {
      errores.telefono = `Máximo ${LIMITES_TIENDA.telefono} caracteres.`
    }
  }

  return errores
}

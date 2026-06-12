export const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100

export const COSTO_CHIP_FISICO = 1.0

// ── Cuadre de caja ────────────────────────────────────────────────────────────
// Replica la fórmula del legacy (nuevo_reporte.php → calcular()):
//   total_no_fisico = yape + bipay + transferencia + retiro_bipay
//   efectivo_esperado = total_sistema − total_no_fisico − total_salidas
//   total_en_cajon    = efectivo_esperado + caja_inicial

export interface CuadreInput {
  totalSistema: number
  yape: number
  bipay: number
  transferencia: number
  retiroBipay: number
  totalSalidas: number
  cajaInicial: number
  efectivoEntregado: number
}

export interface CuadreResult {
  totalNoFisico: number
  efectivoEsperado: number
  totalEnCajon: number
  diferencia: number
  requiereAprobacion: boolean
}

export function calcularCuadre(i: CuadreInput): CuadreResult {
  const totalNoFisico = round2(i.yape + i.bipay + i.transferencia + i.retiroBipay)
  const efectivoEsperado = round2(i.totalSistema - totalNoFisico - i.totalSalidas)
  const totalEnCajon = round2(efectivoEsperado + i.cajaInicial)
  const diferencia = round2(i.efectivoEntregado - efectivoEsperado)
  return {
    totalNoFisico,
    efectivoEsperado,
    totalEnCajon,
    diferencia,
    requiereAprobacion: Math.abs(diferencia) > 10,
  }
}

// ── Comisión por línea ────────────────────────────────────────────────────────
// Legacy calcularComision(): base del plan (data-dni normal / data-ext extranjero).
// Upgrade ⇒ 20.00 o 10.00 según diferencia de fee_monto.
// Se resta COSTO_CHIP_FISICO salvo migración | upgrade | esim.

export interface ComisionInput {
  comDni: number
  comExt: number
  esExtranjero: boolean
  esMigracion: boolean
  esUpgrade: boolean
  esEsim: boolean
  feePlanNuevo?: number
  feePlanAnterior?: number
}

export function calcularComision(c: ComisionInput): number {
  let base = c.esExtranjero ? c.comExt : c.comDni
  if (c.esUpgrade) {
    const diff = (c.feePlanNuevo ?? 0) - (c.feePlanAnterior ?? 0)
    base = diff > 0 ? 20.0 : 10.0
  }
  if (!(c.esMigracion || c.esUpgrade || c.esEsim)) base -= COSTO_CHIP_FISICO
  return round2(base)
}

// ── Validación de stock de chips en vivo ──────────────────────────────────────
// Legacy stockActual / errorStock: descuenta consumos del stock inicial por origen
// ('Propio' o código de tienda de apoyo) y marca error si algún restante < 0.

export interface StockChip {
  codigo: string
  disponible: number
}

export interface StockResult {
  restante: Map<string, number>
  hayError: boolean
  negativos: Array<[string, number]>
}

export function validarStock(
  stockInicial: StockChip[],
  consumos: Record<string, number>,
): StockResult {
  const restante = new Map(stockInicial.map((s) => [s.codigo, s.disponible]))
  for (const [cod, qty] of Object.entries(consumos)) {
    restante.set(cod, (restante.get(cod) ?? 0) - qty)
  }
  const negativos = [...restante.entries()].filter(([, v]) => v < 0)
  return { restante, hayError: negativos.length > 0, negativos }
}

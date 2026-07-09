export type EstadoPostulacion = 'PENDIENTE' | 'ENTREVISTA' | 'APROBADO' | 'RECHAZADO'

export interface Postulacion {
  id: number
  dni: string
  nombres: string
  apellidos: string
  telefono: string
  correo: string | null
  fecha_nacimiento: string | null
  lugar_nacimiento: string | null
  direccion: string | null
  tienda_postulada: string | null
  sistema_pension: string | null
  nombre_afp: string | null
  numero_cuspp: string | null
  grupo_sanguineo: string | null
  alergias: string | null
  antecedentes_penales: boolean
  antecedentes_policial: boolean
  antecedentes_judicial: boolean
  carga_familiar: CargaFamiliarItem[] | null
  formacion_academica: FormacionItem[] | null
  experiencia_laboral: ExperienciaItem[] | null
  contacto_nombre_1: string | null
  contacto_parentesco_1: string | null
  contacto_telefono_1: string | null
  contacto_nombre_2: string | null
  contacto_parentesco_2: string | null
  contacto_telefono_2: string | null
  contacto_nombre_3: string | null
  contacto_parentesco_3: string | null
  contacto_telefono_3: string | null
  foto_perfil: string | null
  foto_dni: string | null
  estado: EstadoPostulacion
  notas_admin: string | null
  created_at: string
  updated_at: string
}

export interface CargaFamiliarItem {
  nombre: string
  parentesco: string
  telefono: string
}

export interface FormacionItem {
  institucion: string
  titulo: string
  anio_inicio: string
  anio_fin: string
}

export interface ExperienciaItem {
  empresa: string
  cargo: string
  periodo: string
  funciones: string
}

export interface PostulacionFilters {
  estado?: string
  search?: string
  page?: number
  per_page?: number
}

export interface PostulacionPublicaPayload {
  dni: string
  nombres: string
  apellidos: string
  telefono: string
  correo?: string
  fecha_nacimiento?: string
  lugar_nacimiento?: string
  direccion?: string
  tienda_postulada?: string
  sistema_pension?: string
  nombre_afp?: string
  numero_cuspp?: string
  grupo_sanguineo?: string
  alergias?: string
  antecedentes_penales?: boolean
  antecedentes_policial?: boolean
  antecedentes_judicial?: boolean
  carga_familiar?: CargaFamiliarItem[]
  formacion_academica?: FormacionItem[]
  experiencia_laboral?: ExperienciaItem[]
  contacto_nombre_1?: string
  contacto_parentesco_1?: string
  contacto_telefono_1?: string
  contacto_nombre_2?: string
  contacto_parentesco_2?: string
  contacto_telefono_2?: string
  contacto_nombre_3?: string
  contacto_parentesco_3?: string
  contacto_telefono_3?: string
  foto_perfil?: File
  foto_dni?: File
}

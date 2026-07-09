import { useState } from 'react'
import { Camera, FileText, IdentificationCard as IdCard } from '@phosphor-icons/react'
import { QueryClient, QueryClientProvider, useMutation } from '@tanstack/react-query'
import { postulacionesApi } from '../services/postulaciones.api'
import { apiErrorData, apiErrorStatus } from '../lib/httpError'
import { useTiendasPublicas } from '../hooks/usePostulaciones'
import type { PostulacionPublicaPayload, CargaFamiliarItem, FormacionItem, ExperienciaItem } from '../types/postulacion'

const qc = new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 300_000 } } })

function PostulacionForm() {
  const { data: tiendas = [] } = useTiendasPublicas()

  const [enviado, setEnviado]     = useState(false)
  const [errorDup, setErrorDup]   = useState(false)
  const [errorMsg, setErrorMsg]   = useState('')

  const [dni, setDni]                         = useState('')
  const [nombres, setNombres]                 = useState('')
  const [apellidos, setApellidos]             = useState('')
  const [telefono, setTelefono]               = useState('')
  const [correo, setCorreo]                   = useState('')
  const [fechaNacimiento, setFechaNacimiento] = useState('')
  const [lugarNacimiento, setLugarNacimiento] = useState('')
  const [direccion, setDireccion]             = useState('')
  const [tiendaPostulada, setTiendaPostulada] = useState('')

  const [sistemaPension, setSistemaPension] = useState('NINGUNO')
  const [nombreAfp, setNombreAfp]           = useState('')
  const [numeroCuspp, setNumeroCuspp]       = useState('')

  const [grupoSanguineo, setGrupoSanguineo] = useState('')
  const [alergias, setAlergias]             = useState('')

  const [antPenales, setAntPenales]   = useState(false)
  const [antPolicial, setAntPolicial] = useState(false)
  const [antJudicial, setAntJudicial] = useState(false)

  const [cargaFamiliar, setCargaFamiliar] = useState<CargaFamiliarItem[]>([])
  const [formacion, setFormacion]         = useState<FormacionItem[]>([])
  const [experiencia, setExperiencia]     = useState<ExperienciaItem[]>([])

  const [c1Nombre, setC1Nombre]           = useState('')
  const [c1Parentesco, setC1Parentesco]   = useState('')
  const [c1Telefono, setC1Telefono]       = useState('')
  const [c2Nombre, setC2Nombre]           = useState('')
  const [c2Parentesco, setC2Parentesco]   = useState('')
  const [c2Telefono, setC2Telefono]       = useState('')
  const [c3Nombre, setC3Nombre]           = useState('')
  const [c3Parentesco, setC3Parentesco]   = useState('')
  const [c3Telefono, setC3Telefono]       = useState('')

  const [fotoPerfil, setFotoPerfil]           = useState<File | null>(null)
  const [fotoPerfilPreview, setFotoPerfilPreview] = useState('')
  const [fotoDni, setFotoDni]                 = useState<File | null>(null)
  const [fotoDniPreview, setFotoDniPreview]   = useState('')
  const [fotoDniEsPdf, setFotoDniEsPdf]       = useState(false)

  const enviar = useMutation({
    mutationFn: (payload: PostulacionPublicaPayload) => postulacionesApi.enviarPublica(payload),
    onSuccess: () => { setEnviado(true) },
    onError: (err: unknown) => {
      if (apiErrorStatus(err) === 409) {
        setErrorDup(true)
      } else {
        setErrorMsg(apiErrorData(err).message ?? 'Error al enviar la postulación.')
      }
    },
  })

  const addCarga = () => setCargaFamiliar((p) => [...p, { nombre: '', parentesco: '', telefono: '' }])
  const removeCarga = (i: number) => setCargaFamiliar((p) => p.filter((_, idx) => idx !== i))
  const updateCarga = (i: number, field: keyof CargaFamiliarItem, val: string) =>
    setCargaFamiliar((p) => p.map((row, idx) => idx === i ? { ...row, [field]: val } : row))

  const addFormacion = () => setFormacion((p) => [...p, { institucion: '', titulo: '', anio_inicio: '', anio_fin: '' }])
  const removeFormacion = (i: number) => setFormacion((p) => p.filter((_, idx) => idx !== i))
  const updateFormacion = (i: number, field: keyof FormacionItem, val: string) =>
    setFormacion((p) => p.map((row, idx) => idx === i ? { ...row, [field]: val } : row))

  const addExperiencia = () => setExperiencia((p) => [...p, { empresa: '', cargo: '', periodo: '', funciones: '' }])
  const removeExperiencia = (i: number) => setExperiencia((p) => p.filter((_, idx) => idx !== i))
  const updateExperiencia = (i: number, field: keyof ExperienciaItem, val: string) =>
    setExperiencia((p) => p.map((row, idx) => idx === i ? { ...row, [field]: val } : row))

  const onFotoPerfil = (e: React.ChangeEvent<HTMLInputElement>) => {
    const archivo = e.target.files?.[0] ?? null
    setFotoPerfil(archivo)
    if (!archivo) { setFotoPerfilPreview(''); return }
    const reader = new FileReader()
    reader.onload = (ev) => setFotoPerfilPreview(String(ev.target?.result ?? ''))
    reader.readAsDataURL(archivo)
  }

  const onFotoDni = (e: React.ChangeEvent<HTMLInputElement>) => {
    const archivo = e.target.files?.[0] ?? null
    setFotoDni(archivo)
    if (!archivo) { setFotoDniPreview(''); setFotoDniEsPdf(false); return }
    if (archivo.type === 'application/pdf') {
      setFotoDniEsPdf(true)
      setFotoDniPreview('')
      return
    }
    setFotoDniEsPdf(false)
    const reader = new FileReader()
    reader.onload = (ev) => setFotoDniPreview(String(ev.target?.result ?? ''))
    reader.readAsDataURL(archivo)
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setErrorDup(false)
    setErrorMsg('')
    const payload: PostulacionPublicaPayload = {
      dni,
      nombres,
      apellidos,
      telefono,
      correo:           correo           || undefined,
      fecha_nacimiento: fechaNacimiento  || undefined,
      lugar_nacimiento: lugarNacimiento  || undefined,
      direccion:        direccion        || undefined,
      tienda_postulada: tiendaPostulada  || undefined,
      sistema_pension:  sistemaPension   || undefined,
      nombre_afp:       nombreAfp        || undefined,
      numero_cuspp:     numeroCuspp      || undefined,
      grupo_sanguineo:  grupoSanguineo   || undefined,
      alergias:         alergias         || undefined,
      antecedentes_penales:  antPenales,
      antecedentes_policial: antPolicial,
      antecedentes_judicial: antJudicial,
      carga_familiar:    cargaFamiliar.length    ? cargaFamiliar : undefined,
      formacion_academica: formacion.length      ? formacion     : undefined,
      experiencia_laboral: experiencia.length    ? experiencia   : undefined,
      contacto_nombre_1:     c1Nombre     || undefined,
      contacto_parentesco_1: c1Parentesco || undefined,
      contacto_telefono_1:   c1Telefono   || undefined,
      contacto_nombre_2:     c2Nombre     || undefined,
      contacto_parentesco_2: c2Parentesco || undefined,
      contacto_telefono_2:   c2Telefono   || undefined,
      contacto_nombre_3:     c3Nombre     || undefined,
      contacto_parentesco_3: c3Parentesco || undefined,
      contacto_telefono_3:   c3Telefono   || undefined,
      foto_perfil: fotoPerfil ?? undefined,
      foto_dni:    fotoDni    ?? undefined,
    }
    enviar.mutate(payload)
  }

  if (enviado) {
    return (
      <div className="flex flex-col items-center justify-center py-24 text-center">
        <div className="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mb-4">
          <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <h2 className="text-xl font-semibold text-gray-800 mb-2">Postulación enviada</h2>
        <p className="text-gray-500 max-w-md">
          Tu postulación fue recibida correctamente. Nos pondremos en contacto contigo pronto.
        </p>
      </div>
    )
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5 sm:space-y-6">
      {errorDup && (
        <div className="rounded-md bg-yellow-50 border border-yellow-200 px-4 py-3 text-sm text-yellow-800">
          Ya existe una postulación registrada con este DNI.
        </div>
      )}
      {errorMsg && (
        <div className="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700">
          {errorMsg}
        </div>
      )}

      <Section title="Datos personales">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="DNI *">
            <input
              type="text"
              value={dni}
              onChange={(e) => setDni(e.target.value)}
              required
              maxLength={8}
              pattern="\d{8}"
              placeholder="12345678"
              className={inputCls}
            />
          </Field>
          <Field label="Tienda a postular">
            <select
              value={tiendaPostulada}
              onChange={(e) => setTiendaPostulada(e.target.value)}
              className={inputCls}
            >
              <option value="">Seleccionar tienda</option>
              {tiendas.map((t) => (
                <option key={t.id} value={t.codigo ?? t.nombre}>{t.nombre}</option>
              ))}
            </select>
          </Field>
          <Field label="Nombres *">
            <input type="text" value={nombres} onChange={(e) => setNombres(e.target.value)} required className={inputCls} />
          </Field>
          <Field label="Apellidos *">
            <input type="text" value={apellidos} onChange={(e) => setApellidos(e.target.value)} required className={inputCls} />
          </Field>
          <Field label="Teléfono *">
            <input type="tel" value={telefono} onChange={(e) => setTelefono(e.target.value)} required className={inputCls} />
          </Field>
          <Field label="Correo electrónico">
            <input type="email" value={correo} onChange={(e) => setCorreo(e.target.value)} className={inputCls} />
          </Field>
          <Field label="Fecha de nacimiento">
            <input type="date" value={fechaNacimiento} onChange={(e) => setFechaNacimiento(e.target.value)} className={inputCls} />
          </Field>
          <Field label="Lugar de nacimiento">
            <input type="text" value={lugarNacimiento} onChange={(e) => setLugarNacimiento(e.target.value)} className={inputCls} />
          </Field>
          <Field label="Dirección" className="sm:col-span-2">
            <input type="text" value={direccion} onChange={(e) => setDireccion(e.target.value)} className={inputCls} />
          </Field>
        </div>
      </Section>

      <Section title="Sistema de pensión">
        <div className="flex gap-6 mb-3">
          {(['ONP', 'AFP', 'NINGUNO'] as const).map((op) => (
            <label key={op} className="flex items-center gap-2 text-sm cursor-pointer">
              <input
                type="radio"
                name="sistema_pension"
                value={op}
                checked={sistemaPension === op}
                onChange={() => setSistemaPension(op)}
                className="accent-blue-600"
              />
              {op}
            </label>
          ))}
        </div>
        {sistemaPension === 'AFP' && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field label="Nombre AFP">
              <input type="text" value={nombreAfp} onChange={(e) => setNombreAfp(e.target.value)} className={inputCls} />
            </Field>
            <Field label="Número CUSPP">
              <input type="text" value={numeroCuspp} onChange={(e) => setNumeroCuspp(e.target.value)} className={inputCls} />
            </Field>
          </div>
        )}
      </Section>

      <Section title="Datos de salud">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Grupo sanguíneo">
            <select value={grupoSanguineo} onChange={(e) => setGrupoSanguineo(e.target.value)} className={inputCls}>
              <option value="">Seleccionar</option>
              {['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'].map((g) => (
                <option key={g} value={g}>{g}</option>
              ))}
            </select>
          </Field>
          <Field label="Alergias">
            <input type="text" value={alergias} onChange={(e) => setAlergias(e.target.value)} placeholder="Ninguna" className={inputCls} />
          </Field>
        </div>
      </Section>

      <Section title="Antecedentes">
        <div className="flex flex-col gap-3">
          {[
            { label: 'Antecedentes penales',   val: antPenales,  set: setAntPenales },
            { label: 'Antecedentes policial',   val: antPolicial, set: setAntPolicial },
            { label: 'Antecedentes judiciales', val: antJudicial, set: setAntJudicial },
          ].map(({ label, val, set }) => (
            <label key={label} className="flex items-center gap-3 text-sm cursor-pointer">
              <input
                type="checkbox"
                checked={val}
                onChange={(e) => set(e.target.checked)}
                className="w-4 h-4 accent-blue-600"
              />
              {label}
            </label>
          ))}
        </div>
      </Section>

      <Section
        title="Carga familiar"
        action={
          <button type="button" onClick={addCarga} className="text-xs text-blue-600 hover:underline">
            + Agregar
          </button>
        }
      >
        {cargaFamiliar.length === 0 ? (
          <p className="text-sm text-gray-400">Sin carga familiar registrada.</p>
        ) : (
          <div className="space-y-2">
            {cargaFamiliar.map((row, i) => (
              <div key={i} className="grid grid-cols-1 items-center gap-2 sm:grid-cols-3">
                <input
                  type="text"
                  placeholder="Nombre"
                  value={row.nombre}
                  onChange={(e) => updateCarga(i, 'nombre', e.target.value)}
                  className={inputCls}
                />
                <input
                  type="text"
                  placeholder="Parentesco"
                  value={row.parentesco}
                  onChange={(e) => updateCarga(i, 'parentesco', e.target.value)}
                  className={inputCls}
                />
                <div className="flex gap-2">
                  <input
                    type="tel"
                    placeholder="Teléfono"
                    value={row.telefono}
                    onChange={(e) => updateCarga(i, 'telefono', e.target.value)}
                    className={inputCls + ' flex-1'}
                  />
                  <button
                    type="button"
                    onClick={() => removeCarga(i)}
                    className="text-red-400 hover:text-red-600 text-lg leading-none px-1"
                  >×</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section
        title="Formación académica"
        action={
          <button type="button" onClick={addFormacion} className="text-xs text-blue-600 hover:underline">
            + Agregar
          </button>
        }
      >
        {formacion.length === 0 ? (
          <p className="text-sm text-gray-400">Sin formación registrada.</p>
        ) : (
          <div className="space-y-2">
            {formacion.map((row, i) => (
              <div key={i} className="grid grid-cols-1 items-center gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <input type="text" placeholder="Institución" value={row.institucion} onChange={(e) => updateFormacion(i, 'institucion', e.target.value)} className={inputCls} />
                <input type="text" placeholder="Título / carrera" value={row.titulo} onChange={(e) => updateFormacion(i, 'titulo', e.target.value)} className={inputCls} />
                <input type="text" placeholder="Año inicio" value={row.anio_inicio} onChange={(e) => updateFormacion(i, 'anio_inicio', e.target.value)} className={inputCls} />
                <div className="flex gap-2">
                  <input type="text" placeholder="Año fin" value={row.anio_fin} onChange={(e) => updateFormacion(i, 'anio_fin', e.target.value)} className={inputCls + ' flex-1'} />
                  <button type="button" onClick={() => removeFormacion(i)} className="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section
        title="Experiencia laboral"
        action={
          <button type="button" onClick={addExperiencia} className="text-xs text-blue-600 hover:underline">
            + Agregar
          </button>
        }
      >
        {experiencia.length === 0 ? (
          <p className="text-sm text-gray-400">Sin experiencia registrada.</p>
        ) : (
          <div className="space-y-2">
            {experiencia.map((row, i) => (
              <div key={i} className="grid grid-cols-1 items-center gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <input type="text" placeholder="Empresa" value={row.empresa} onChange={(e) => updateExperiencia(i, 'empresa', e.target.value)} className={inputCls} />
                <input type="text" placeholder="Cargo" value={row.cargo} onChange={(e) => updateExperiencia(i, 'cargo', e.target.value)} className={inputCls} />
                <input type="text" placeholder="Período (ej. 2022-2024)" value={row.periodo} onChange={(e) => updateExperiencia(i, 'periodo', e.target.value)} className={inputCls} />
                <div className="flex gap-2">
                  <input type="text" placeholder="Funciones" value={row.funciones} onChange={(e) => updateExperiencia(i, 'funciones', e.target.value)} className={inputCls + ' flex-1'} />
                  <button type="button" onClick={() => removeExperiencia(i)} className="text-red-400 hover:text-red-600 text-lg leading-none px-1">×</button>
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="Contactos de emergencia">
        <div className="space-y-3">
          {[
            { n: 1, nombre: c1Nombre, parentesco: c1Parentesco, telefono: c1Telefono, setNombre: setC1Nombre, setParentesco: setC1Parentesco, setTelefono: setC1Telefono },
            { n: 2, nombre: c2Nombre, parentesco: c2Parentesco, telefono: c2Telefono, setNombre: setC2Nombre, setParentesco: setC2Parentesco, setTelefono: setC2Telefono },
            { n: 3, nombre: c3Nombre, parentesco: c3Parentesco, telefono: c3Telefono, setNombre: setC3Nombre, setParentesco: setC3Parentesco, setTelefono: setC3Telefono },
          ].map(({ n, nombre, parentesco, telefono: tel, setNombre, setParentesco, setTelefono: setTel }) => (
            <div key={n} className="grid grid-cols-1 items-center gap-3 sm:grid-cols-3">
              <Field label={`Contacto ${n} — Nombre`}>
                <input type="text" value={nombre} onChange={(e) => setNombre(e.target.value)} className={inputCls} />
              </Field>
              <Field label="Parentesco">
                <input type="text" value={parentesco} onChange={(e) => setParentesco(e.target.value)} className={inputCls} />
              </Field>
              <Field label="Teléfono">
                <input type="tel" value={tel} onChange={(e) => setTel(e.target.value)} className={inputCls} />
              </Field>
            </div>
          ))}
        </div>
      </Section>

      <Section title="Documentos requeridos">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <Field label="Foto de perfil * (JPG/PNG/WEBP · máx 5 MB)">
            <label className={inputCls + ' flex cursor-pointer items-center gap-2'}>
              <Camera size={16} className="shrink-0 text-gray-400" />
              <span className="truncate text-gray-500">{fotoPerfil?.name ?? 'Seleccionar archivo...'}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp"
                required
                onChange={onFotoPerfil}
                className="hidden"
              />
            </label>
            {fotoPerfilPreview && (
              <img src={fotoPerfilPreview} alt="Foto de perfil" className="mt-2 max-h-32 rounded-lg border border-gray-200/60 object-cover dark:border-white/10" />
            )}
          </Field>
          <Field label="DNI, imagen o PDF * (máx 10 MB)">
            <label className={inputCls + ' flex cursor-pointer items-center gap-2'}>
              <IdCard size={16} className="shrink-0 text-gray-400" />
              <span className="truncate text-gray-500">{fotoDni?.name ?? 'Seleccionar archivo...'}</span>
              <input
                type="file"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                required
                onChange={onFotoDni}
                className="hidden"
              />
            </label>
            {fotoDniPreview && (
              <img src={fotoDniPreview} alt="DNI" className="mt-2 max-h-32 rounded-lg border border-gray-200/60 object-cover dark:border-white/10" />
            )}
            {fotoDniEsPdf && (
              <div className="mt-2 flex items-center gap-2 text-xs text-indigo-500">
                <FileText size={16} /> PDF seleccionado
              </div>
            )}
          </Field>
        </div>
      </Section>

      <div className="flex justify-end pt-2">
        <button
          type="submit"
          disabled={enviar.isPending}
          className="rounded-xl border border-indigo-700 bg-gradient-to-b from-indigo-500 to-indigo-700 px-8 py-3 text-sm font-semibold text-white shadow-[0_12px_28px_-14px_rgba(79,70,229,0.9)] transition-all hover:-translate-y-px hover:brightness-110 disabled:translate-y-0 disabled:opacity-50"
        >
          {enviar.isPending ? 'Enviando...' : 'Enviar postulación'}
        </button>
      </div>
    </form>
  )
}

const inputCls = 'w-full rounded-lg border border-gray-300/80 bg-white/75 px-3 py-2 text-sm text-gray-800 shadow-sm transition-all placeholder:text-gray-400 hover:border-indigo-300 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-white/10 dark:bg-black/20 dark:text-zinc-100 dark:placeholder:text-zinc-600'

function Section({
  title,
  action,
  children,
}: {
  title: string
  action?: React.ReactNode
  children: React.ReactNode
}) {
  return (
    <section className="rounded-2xl border border-gray-200/75 bg-white/45 p-4 shadow-[0_12px_30px_-26px_rgba(15,23,42,0.45)] backdrop-blur-xl dark:border-white/[0.07] dark:bg-white/[0.025] sm:p-5">
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2 className="flex-1 border-b border-gray-200/80 pb-2 text-xs font-bold uppercase tracking-[0.14em] text-gray-700 dark:border-white/[0.07] dark:text-zinc-200">
          {title}
        </h2>
        {action}
      </div>
      {children}
    </section>
  )
}

function Field({
  label,
  children,
  className,
}: {
  label: string
  children: React.ReactNode
  className?: string
}) {
  return (
    <div className={className}>
      <label className="mb-1.5 block text-xs font-medium text-gray-500 dark:text-zinc-400">{label}</label>
      {children}
    </div>
  )
}

export function PostulacionPublicaPage() {
  return (
    <QueryClientProvider client={qc}>
      <div className="public-premium-shell min-h-screen px-4 py-8 sm:py-12">
        <div className="mx-auto max-w-3xl">
          <div className="mb-8 text-center">
            <div className="mx-auto mb-4 h-1 w-16 rounded-full bg-gradient-to-r from-indigo-500 to-amber-400 shadow-[0_0_18px_rgba(99,102,241,0.35)]" />
            <h1
              className="text-2xl font-bold uppercase tracking-[0.16em] text-gray-900 dark:text-zinc-50"
              style={{ fontFamily: "'Orbitron', sans-serif" }}
            >
              SIS-KYRO
            </h1>
            <p className="mt-2 text-gray-500 text-sm">Formulario de postulación</p>
          </div>
          <div className="public-premium-card rounded-3xl p-5 sm:p-8">
            <PostulacionForm />
          </div>
        </div>
      </div>
    </QueryClientProvider>
  )
}

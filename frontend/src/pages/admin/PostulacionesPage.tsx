import { useState } from 'react'
import type { ColumnDef, PaginationState } from '@tanstack/react-table'
import {
  usePostulaciones,
  usePostulacion,
  useActualizarPostulacion,
  useEliminarPostulacion,
} from '../../hooks/usePostulaciones'
import { DataTable } from '../../components/DataTable'
import { PageHeader } from '../../components/PageHeader'
import { Dialog } from '../../components/ui/dialog'
import { Button } from '../../components/ui/button'
import { Input } from '../../components/ui/input'
import { Badge } from '../../components/ui/badge'
import { Select } from '../../components/ui/select'
import type { Postulacion, EstadoPostulacion } from '../../types/postulacion'

type EstadoVariant = 'default' | 'success' | 'destructive' | 'warning' | 'outline'
const estadoVariant: Record<EstadoPostulacion, EstadoVariant> = {
  PENDIENTE:   'warning',
  ENTREVISTA:  'default',
  APROBADO:    'success',
  RECHAZADO:   'destructive',
}

function getColumns(
  onVer: (p: Postulacion) => void,
  onEliminar: (p: Postulacion) => void,
  eliminando: boolean,
): ColumnDef<Postulacion>[] {
  return [
    { accessorKey: 'dni',       header: 'DNI' },
    { accessorKey: 'nombres',   header: 'Nombres' },
    { accessorKey: 'apellidos', header: 'Apellidos' },
    { accessorKey: 'telefono',  header: 'Teléfono' },
    {
      accessorKey: 'tienda_postulada',
      header: 'Tienda postulada',
      cell: ({ row }) => row.original.tienda_postulada ?? '—',
    },
    {
      accessorKey: 'estado',
      header: 'Estado',
      cell: ({ row }) => (
        <Badge variant={estadoVariant[row.original.estado]}>{row.original.estado}</Badge>
      ),
    },
    {
      accessorKey: 'created_at',
      header: 'Fecha',
      cell: ({ row }) => row.original.created_at?.slice(0, 10) ?? '—',
    },
    {
      id: 'acciones',
      header: 'Acciones',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button size="sm" variant="outline" onClick={() => onVer(row.original)}>
            Ver detalles
          </Button>
          <Button
            size="sm"
            variant="destructive"
            onClick={() => onEliminar(row.original)}
            disabled={eliminando}
          >
            Eliminar
          </Button>
        </div>
      ),
    },
  ]
}

function DetallePostulacion({
  postulacion,
  onClose,
}: {
  postulacion: Postulacion
  onClose: () => void
}) {
  const [estado, setEstado]       = useState<EstadoPostulacion>(postulacion.estado)
  const [notas, setNotas]         = useState(postulacion.notas_admin ?? '')
  const actualizar                = useActualizarPostulacion()

  const { data: detalle, isLoading } = usePostulacion(postulacion.id)
  const p = detalle ?? postulacion

  const handleGuardar = () => {
    actualizar.mutate(
      { id: p.id, data: { estado, notas_admin: notas } },
      { onSuccess: onClose },
    )
  }

  if (isLoading) {
    return <div className="text-sm text-gray-400 py-4 text-center">Cargando...</div>
  }

  return (
    <div className="space-y-5 max-h-[70vh] overflow-y-auto pr-1">
      <section>
        <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Datos personales</h3>
        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
          <LabelVal label="DNI"              val={p.dni} />
          <LabelVal label="Nombres"          val={p.nombres} />
          <LabelVal label="Apellidos"        val={p.apellidos} />
          <LabelVal label="Teléfono"         val={p.telefono} />
          <LabelVal label="Correo"           val={p.correo} />
          <LabelVal label="Fecha nacimiento" val={p.fecha_nacimiento} />
          <LabelVal label="Lugar nacimiento" val={p.lugar_nacimiento} />
          <LabelVal label="Dirección"        val={p.direccion} />
          <LabelVal label="Tienda postulada" val={p.tienda_postulada} />
        </div>
      </section>

      <section>
        <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Sistema de pensión</h3>
        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
          <LabelVal label="Sistema"    val={p.sistema_pension} />
          <LabelVal label="AFP"        val={p.nombre_afp} />
          <LabelVal label="CUSPP"      val={p.numero_cuspp} />
        </div>
      </section>

      <section>
        <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Salud y antecedentes</h3>
        <div className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
          <LabelVal label="Grupo sanguíneo" val={p.grupo_sanguineo} />
          <LabelVal label="Alergias"        val={p.alergias} />
          <LabelVal label="Ant. penales"    val={p.antecedentes_penales ? 'Sí' : 'No'} />
          <LabelVal label="Ant. policial"   val={p.antecedentes_policial ? 'Sí' : 'No'} />
          <LabelVal label="Ant. judicial"   val={p.antecedentes_judicial ? 'Sí' : 'No'} />
        </div>
      </section>

      {p.carga_familiar && p.carga_familiar.length > 0 && (
        <section>
          <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Carga familiar</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-400">
                <th className="pb-1">Nombre</th>
                <th className="pb-1">Parentesco</th>
                <th className="pb-1">Teléfono</th>
              </tr>
            </thead>
            <tbody>
              {p.carga_familiar.map((c, i) => (
                <tr key={i} className="border-t border-gray-100">
                  <td className="py-1">{c.nombre}</td>
                  <td className="py-1">{c.parentesco}</td>
                  <td className="py-1">{c.telefono}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {p.formacion_academica && p.formacion_academica.length > 0 && (
        <section>
          <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Formación académica</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-400">
                <th className="pb-1">Institución</th>
                <th className="pb-1">Título</th>
                <th className="pb-1">Inicio</th>
                <th className="pb-1">Fin</th>
              </tr>
            </thead>
            <tbody>
              {p.formacion_academica.map((f, i) => (
                <tr key={i} className="border-t border-gray-100">
                  <td className="py-1">{f.institucion}</td>
                  <td className="py-1">{f.titulo}</td>
                  <td className="py-1">{f.anio_inicio}</td>
                  <td className="py-1">{f.anio_fin}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {p.experiencia_laboral && p.experiencia_laboral.length > 0 && (
        <section>
          <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Experiencia laboral</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-400">
                <th className="pb-1">Empresa</th>
                <th className="pb-1">Cargo</th>
                <th className="pb-1">Período</th>
                <th className="pb-1">Funciones</th>
              </tr>
            </thead>
            <tbody>
              {p.experiencia_laboral.map((e, i) => (
                <tr key={i} className="border-t border-gray-100">
                  <td className="py-1">{e.empresa}</td>
                  <td className="py-1">{e.cargo}</td>
                  <td className="py-1">{e.periodo}</td>
                  <td className="py-1">{e.funciones}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <section>
        <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-2">Contactos de emergencia</h3>
        <div className="space-y-1 text-sm">
          {[1, 2, 3].map((n) => {
            const nombre     = p[`contacto_nombre_${n}` as keyof Postulacion] as string | null
            const parentesco = p[`contacto_parentesco_${n}` as keyof Postulacion] as string | null
            const telefono   = p[`contacto_telefono_${n}` as keyof Postulacion] as string | null
            if (!nombre) return null
            return (
              <div key={n} className="flex gap-4">
                <span className="text-gray-400 w-4">{n}.</span>
                <span>{nombre}</span>
                <span className="text-gray-400">{parentesco}</span>
                <span>{telefono}</span>
              </div>
            )
          })}
        </div>
      </section>

      <section className="border-t border-gray-100 pt-4">
        <h3 className="text-xs font-semibold uppercase text-gray-400 tracking-wider mb-3">Gestión admin</h3>
        <div className="space-y-3">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Estado</label>
            <Select
              value={estado}
              onChange={(e) => setEstado(e.target.value as EstadoPostulacion)}
              className="w-48"
            >
              <option value="PENDIENTE">PENDIENTE</option>
              <option value="ENTREVISTA">ENTREVISTA</option>
              <option value="APROBADO">APROBADO</option>
              <option value="RECHAZADO">RECHAZADO</option>
            </Select>
          </div>
          <div>
            <label className="block text-xs text-gray-500 mb-1">Notas admin</label>
            <textarea
              value={notas}
              onChange={(e) => setNotas(e.target.value)}
              rows={3}
              className="w-full rounded-md border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
              placeholder="Observaciones internas..."
            />
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>Cancelar</Button>
            <Button onClick={handleGuardar} disabled={actualizar.isPending}>
              {actualizar.isPending ? 'Guardando...' : 'Guardar cambios'}
            </Button>
          </div>
        </div>
      </section>
    </div>
  )
}

function LabelVal({ label, val }: { label: string; val: string | null | undefined }) {
  return (
    <div>
      <span className="text-gray-400">{label}: </span>
      <span className="text-gray-800">{val ?? '—'}</span>
    </div>
  )
}

export function PostulacionesPage() {
  const [search, setSearch]         = useState('')
  const [query, setQuery]           = useState('')
  const [estado, setEstado]         = useState('')
  const [pagination, setPagination] = useState<PaginationState>({ pageIndex: 0, pageSize: 20 })
  const [dialogOpen, setDialogOpen] = useState(false)
  const [viendo, setViendo]         = useState<Postulacion | undefined>()

  const { data, isLoading } = usePostulaciones({
    search: query  || undefined,
    estado: estado || undefined,
    page:     pagination.pageIndex + 1,
    per_page: pagination.pageSize,
  })

  const eliminar = useEliminarPostulacion()

  const abrirDetalle = (p: Postulacion) => { setViendo(p); setDialogOpen(true) }
  const cerrar       = () => setDialogOpen(false)

  const handleEliminar = (p: Postulacion) => {
    if (!confirm(`¿Eliminar postulación de "${p.nombres} ${p.apellidos}" (${p.dni})?`)) return
    eliminar.mutate(p.id)
  }

  const buscar = () => {
    setQuery(search)
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const limpiarFiltros = () => {
    setSearch('')
    setQuery('')
    setEstado('')
    setPagination((p) => ({ ...p, pageIndex: 0 }))
  }

  const hayFiltros = query || estado

  const columns = getColumns(abrirDetalle, handleEliminar, eliminar.isPending)

  return (
    <div>
      <PageHeader
        title="Postulantes"
        description="Gestión de postulaciones recibidas."
      />

      <div className="flex flex-wrap items-center gap-3 mb-4">
        <Input
          placeholder="Buscar por nombre, DNI..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') buscar() }}
          className="max-w-xs"
        />
        <Button variant="outline" onClick={buscar}>Buscar</Button>

        <Select
          value={estado}
          onChange={(e) => {
            setEstado(e.target.value)
            setPagination((p) => ({ ...p, pageIndex: 0 }))
          }}
          className="w-40"
        >
          <option value="">Todos los estados</option>
          <option value="PENDIENTE">Pendiente</option>
          <option value="ENTREVISTA">Entrevista</option>
          <option value="APROBADO">Aprobado</option>
          <option value="RECHAZADO">Rechazado</option>
        </Select>

        {hayFiltros && (
          <Button variant="ghost" onClick={limpiarFiltros}>Limpiar filtros</Button>
        )}
      </div>

      <DataTable
        data={data?.data ?? []}
        columns={columns}
        pageCount={data?.last_page ?? 0}
        pagination={pagination}
        onPaginationChange={setPagination}
        isLoading={isLoading}
        total={data?.total}
      />

      <Dialog
        open={dialogOpen}
        onClose={cerrar}
        title={viendo ? `${viendo.nombres} ${viendo.apellidos} — ${viendo.dni}` : 'Detalle'}
        maxWidth="xl"
      >
        {viendo && <DetallePostulacion postulacion={viendo} onClose={cerrar} />}
      </Dialog>
    </div>
  )
}

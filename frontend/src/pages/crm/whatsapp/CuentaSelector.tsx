import { useState } from 'react'
import { CaretDown, Circle, Plus, Trash } from '@phosphor-icons/react'
import type { WhatsAppCuenta } from '../../../types/whatsapp'
import { useEliminarCuentaWhatsApp } from '../../../hooks/useWhatsApp'

export function CuentaSelector({
  cuentas,
  cuentaActivaId,
  onSeleccionar,
  onAgregarNueva,
  esAdmin,
}: {
  cuentas: WhatsAppCuenta[]
  cuentaActivaId: number | 'todas'
  onSeleccionar: (id: number | 'todas') => void
  onAgregarNueva: () => void
  esAdmin: boolean
}) {
  const [abierto, setAbierto] = useState(false)
  const activa = cuentaActivaId === 'todas' ? null : cuentas.find((cuenta) => cuenta.id === cuentaActivaId)
  const eliminar = useEliminarCuentaWhatsApp()

  const handleEliminar = (cuenta: WhatsAppCuenta) => {
    if (!confirm(`Eliminar la cuenta "${cuenta.nombre}"? Se perdera el vinculo con Evolution.`)) return
    eliminar.mutate(cuenta.id, {
      onSuccess: () => {
        if (cuentaActivaId === cuenta.id) onSeleccionar('todas')
      },
    })
  }

  return (
    <div className="relative">
      <button
        type="button"
        onClick={() => setAbierto((valor) => !valor)}
        className="flex items-center gap-2 rounded-kyro border border-kyro-border bg-kyro-surface px-3 py-2 text-sm font-medium hover:border-kyro-indigo"
      >
        <Circle
          weight="fill"
          size={8}
          className={activa?.estado === 'conectada' ? 'text-kyro-success' : 'text-kyro-muted'}
        />
        {activa ? `${activa.nombre} - ${activa.numero}` : 'Todas las cuentas'}
        <CaretDown size={12} />
      </button>

      {abierto && (
        <div className="absolute left-0 top-full z-20 mt-1 w-72 rounded-kyro border border-kyro-border bg-kyro-surface p-1 shadow-lg">
          <button
            type="button"
            onClick={() => {
              onSeleccionar('todas')
              setAbierto(false)
            }}
            className="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-kyro-border/40"
          >
            Todas las cuentas
          </button>
          {cuentas.map((cuenta) => (
            <div
              key={cuenta.id}
              className="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-sm hover:bg-kyro-border/40"
            >
              <button
                type="button"
                onClick={() => {
                  onSeleccionar(cuenta.id)
                  setAbierto(false)
                }}
                className="flex flex-1 items-center gap-2 text-left"
              >
                <Circle
                  weight="fill"
                  size={8}
                  className={cuenta.estado === 'conectada' ? 'text-kyro-success' : 'text-kyro-muted'}
                />
                {cuenta.nombre}
              </button>
              <span className="text-xs text-kyro-muted">{cuenta.numero}</span>
              {esAdmin && (
                <button
                  type="button"
                  title="Eliminar cuenta"
                  onClick={() => handleEliminar(cuenta)}
                  className="text-kyro-muted hover:text-red-400"
                >
                  <Trash size={14} />
                </button>
              )}
            </div>
          ))}
          {esAdmin && (
            <button
              type="button"
              onClick={() => {
                onAgregarNueva()
                setAbierto(false)
              }}
              className="mt-1 flex w-full items-center gap-2 rounded-md border-t border-kyro-border px-3 py-2 text-left text-sm text-kyro-indigo hover:bg-kyro-border/40"
            >
              <Plus size={14} /> Agregar otro numero
            </button>
          )}
        </div>
      )}
    </div>
  )
}

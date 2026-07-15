import { useState } from 'react'
import { Dialog } from '../../../components/ui/dialog'
import { Button } from '../../../components/ui/button'
import { Input } from '../../../components/ui/input'
import { useCrearCuentaWhatsApp, useQrCuentaWhatsApp } from '../../../hooks/useWhatsApp'

export function ConectarCuentaModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [nombre, setNombre] = useState('')
  const [numero, setNumero] = useState('')
  const [tiendaId, setTiendaId] = useState('')
  const [cuentaCreadaId, setCuentaCreadaId] = useState<number | null>(null)

  const crear = useCrearCuentaWhatsApp()
  const qrQuery = useQrCuentaWhatsApp(cuentaCreadaId)

  const cerrar = () => {
    setNombre('')
    setNumero('')
    setTiendaId('')
    setCuentaCreadaId(null)
    onClose()
  }

  const handleCrear = () => {
    crear.mutate(
      { nombre, numero, tienda_id: tiendaId || undefined },
      { onSuccess: (resultado) => setCuentaCreadaId(resultado.cuenta.id) },
    )
  }

  return (
    <Dialog open={open} onClose={cerrar} title="Agregar numero de WhatsApp" maxWidth="sm">
      {!cuentaCreadaId ? (
        <div className="space-y-3">
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Nombre de la cuenta</label>
            <Input value={nombre} onChange={(event) => setNombre(event.target.value)} placeholder="Tienda Centro" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Numero</label>
            <Input value={numero} onChange={(event) => setNumero(event.target.value)} placeholder="+51999999999" />
          </div>
          <div>
            <label className="mb-1 block text-xs text-kyro-muted">Tienda (opcional, vacio = Central)</label>
            <Input value={tiendaId} onChange={(event) => setTiendaId(event.target.value)} placeholder="T01" />
          </div>
          <Button variant="gold" className="w-full" disabled={!nombre || !numero || crear.isPending} onClick={handleCrear}>
            {crear.isPending ? 'Creando...' : 'Crear y generar QR'}
          </Button>
        </div>
      ) : (
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          {qrQuery.data?.estado === 'conectada' ? (
            <p className="text-sm text-kyro-success">Cuenta conectada correctamente.</p>
          ) : qrQuery.data?.qr ? (
            <>
              <img
                src={`data:image/png;base64,${qrQuery.data.qr}`}
                alt="Codigo QR de WhatsApp"
                className="h-56 w-56 rounded-kyro border border-kyro-border"
              />
              <p className="text-xs text-kyro-muted">Escanea este codigo desde WhatsApp, Dispositivos vinculados.</p>
            </>
          ) : (
            <p className="text-sm text-kyro-muted">Generando codigo QR...</p>
          )}
          <Button variant="outline" onClick={cerrar}>
            Cerrar
          </Button>
        </div>
      )}
    </Dialog>
  )
}

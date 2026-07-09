import { useState } from 'react'
import { Printer, Receipt as FileCheck, X } from '@phosphor-icons/react'
import { api } from '../../../services/api'
import { Input } from '../../../components/ui/input'
import { Label } from '../../../components/ui/label'
import { Button } from '../../../components/ui/button'
import { GlassPanel } from '../../../components/ui/GlassPanel'

interface Props {
  ticketId: number
  ventaId: number | null
  onClose: () => void
}

export function PostVentaModal({ ticketId, ventaId, onClose }: Props) {
  const [tipo, setTipo]               = useState<'03' | '01' | null>(null)
  const [serie, setSerie]             = useState('B001')
  const [loading, setLoading]         = useState(false)
  const [comprobanteOk, setComprobanteOk] = useState(false)
  const [error, setError]             = useState<string | null>(null)

  const handleTipo = (t: '03' | '01') => {
    setTipo(t)
    setSerie(t === '03' ? 'B001' : 'F001')
    setError(null)
  }

  const emitir = async () => {
    if (!ventaId || !tipo) return
    setLoading(true)
    setError(null)
    try {
      await api.post('/v1/comprobantes', {
        venta_id:         ventaId,
        tipo_comprobante: tipo,
        serie,
      })
      setComprobanteOk(true)
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg ?? 'Error al emitir comprobante.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="fixed inset-0 z-[100] flex items-center justify-center p-4">
      <div className="absolute inset-0 bg-black/60" onClick={onClose} />
      <GlassPanel className="kyro-card !bg-kyro-panel !border-kyro-border !shadow-kyro-card relative z-10 w-full max-w-sm p-4 space-y-4">

        {/* Header */}
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-bold text-kyro-body">Venta registrada</h3>
          <Button type="button" variant="ghost" size="iconSm" onClick={onClose}>
            <X size={14} />
          </Button>
        </div>

        {/* Ticket generado */}
        <div className="flex items-center justify-between rounded-kyro border border-kyro-success/40 bg-kyro-success/10 px-3 py-2.5">
          <div>
            <p className="text-xs text-kyro-muted">Ticket generado</p>
            <p className="text-sm font-semibold text-kyro-success">
              #{String(ticketId).padStart(6, '0')}
            </p>
          </div>
          <Button
            type="button"
            variant="glassSuccess"
            size="sm"
            onClick={() => window.open(`/tickets/imprimir/${ticketId}?print=1`, '_blank', 'width=420,height=680')}
          >
            <Printer size={13} className="mr-1" /> Imprimir
          </Button>
        </div>

        {/* Comprobante SUNAT */}
        {ventaId ? (
          comprobanteOk ? (
            <div className="rounded-kyro border border-kyro-info/40 bg-kyro-info/10 px-3 py-2.5 text-sm text-kyro-info">
              ✓ Comprobante enviado a SUNAT
            </div>
          ) : (
            <div className="space-y-3">
              <div>
                <p className="text-xs text-kyro-muted mb-2">¿Emitir comprobante electrónico?</p>
                <div className="flex gap-2">
                  <Button
                    type="button"
                    variant={tipo === '03' ? 'gold' : 'outline'}
                    size="sm"
                    className="flex-1"
                    onClick={() => handleTipo('03')}
                  >
                    Boleta
                  </Button>
                  <Button
                    type="button"
                    variant={tipo === '01' ? 'gold' : 'outline'}
                    size="sm"
                    className="flex-1"
                    onClick={() => handleTipo('01')}
                  >
                    Factura
                  </Button>
                </div>
              </div>

              {tipo && (
                <div className="space-y-2">
                  <div>
                    <Label className="text-[10px] text-kyro-muted">Serie (4 caracteres)</Label>
                    <Input
                      value={serie}
                      onChange={e => setSerie(e.target.value.toUpperCase().slice(0, 4))}
                      placeholder="B001"
                      maxLength={4}
                      className="kyro-input h-8 font-mono text-sm w-24"
                    />
                  </div>
                  <Button
                    type="button"
                    variant="glassInfo"
                    size="sm"
                    className="w-full"
                    onClick={emitir}
                    disabled={loading || serie.length !== 4}
                  >
                    <FileCheck size={13} className="mr-1" />
                    {loading ? 'Enviando a SUNAT...' : 'Emitir comprobante'}
                  </Button>
                </div>
              )}

              {error && <p className="text-xs text-kyro-danger">{error}</p>}

              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="w-full text-kyro-muted text-xs"
                onClick={onClose}
              >
                Omitir comprobante y cerrar
              </Button>
            </div>
          )
        ) : (
          <p className="text-xs text-kyro-muted text-center">
            Comprobante no disponible (venta sin ID).
          </p>
        )}

        {comprobanteOk && (
          <Button type="button" variant="outline" size="sm" className="w-full" onClick={onClose}>
            Cerrar
          </Button>
        )}
      </GlassPanel>
    </div>
  )
}

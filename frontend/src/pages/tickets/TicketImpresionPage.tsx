import { useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/services/api'
import { useAuth } from '../../hooks/useAuth'

interface Ticket {
  id: number
  tienda_id: string
  vendedor: string
  cajero_nombres: string
  nombre_cliente: string
  dni_cliente: string
  descripcion: string
  monto: number
  efectivo: number
  yape: number
  bipay: number
  transferencia: number
  vuelto: number
  forma_pago: string
  creado_en: string
}

export function TicketImpresionPage() {
  const { id } = useParams<{ id: string }>()
  const { usuario } = useAuth()
  const formato58 = usuario?.formato_ticket === '58'

  const { data: ticket, isLoading, isError } = useQuery<Ticket>({
    queryKey: ['ticket', id],
    queryFn: async () => {
      const r = await api.get(`/v1/tickets/${id}`)
      return r.data
    },
    enabled: !!id,
  })

  useEffect(() => {
    if (ticket) {
      const params = new URLSearchParams(window.location.search)
      if (params.get('print') === '1') {
        setTimeout(() => window.print(), 400)
      }
    }
  }, [ticket])

  if (isLoading) return (
    <div style={{ fontFamily: 'sans-serif', padding: 20, textAlign: 'center' }}>
      Cargando ticket...
    </div>
  )

  if (isError || !ticket) return (
    <div style={{ fontFamily: 'sans-serif', padding: 20 }}>
      Ticket no encontrado.
    </div>
  )

  const fecha      = new Date(ticket.creado_en).toLocaleDateString('es-PE')
  const hora       = new Date(ticket.creado_en).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' })
  const ticketNum  = `Ticket #${String(ticket.id).padStart(6, '0')}`
  const cajero     = (ticket.cajero_nombres || ticket.vendedor || '').split(' ').slice(0, 3).join(' ')

  const pef = Number(ticket.efectivo      ?? 0)
  const pya = Number(ticket.yape          ?? 0)
  const pbi = Number(ticket.bipay         ?? 0)
  const ppl = Number(ticket.transferencia ?? 0)
  const pvl = Number(ticket.vuelto        ?? 0)

  return (
    <>
      <style>{`
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Courier New',monospace; color:#000; }
        .controles {
          position:sticky; top:0; z-index:100;
          display:flex; gap:8px; justify-content:center; align-items:center;
          padding:10px 12px; background:#1c1c1c; font-family:sans-serif;
        }
        .btn-print { padding:7px 20px; font-size:13px; font-weight:bold; border:none;
                     border-radius:4px; cursor:pointer; background:#22c55e; color:#fff; }
        .btn-close { padding:7px 20px; font-size:13px; font-weight:bold; border:none;
                     border-radius:4px; cursor:pointer; background:#555; color:#fff; }
        .ticket { background:#fff; }
        .cabecera { display:flex; align-items:center; justify-content:center; gap:8px; padding:8px 0 3px; }
        .marca { font-weight:900; letter-spacing:1px; }
        .razon-social { text-align:center; font-weight:bold; margin:1px 0; }
        .sep { border:none; border-top:1px dashed #000; margin:6px 0; }
        .fila { display:flex; align-items:center; gap:6px; margin:4px 0; }
        .lbl { white-space:nowrap; }
        .val { text-align:right; flex:1; word-break:break-word; }
        .lbl-bold { font-weight:bold; }
        .desc { margin:4px 0 6px; word-break:break-word; }
        .monto-box { text-align:center; font-weight:bold; border:2px solid #000;
                     padding:8px 4px; letter-spacing:2px; margin:8px 0; }
        .ticket-num { text-align:center; font-weight:bold; margin:4px 0 6px; }
        .footer { text-align:center; color:#555; margin-top:10px; }

        @media screen {
          body { background:#e5e7eb; min-height:100vh; }
          .ticket { width:400px; margin:0 auto; padding:18px 22px; font-size:14px;
                    box-shadow:0 4px 20px rgba(0,0,0,.25); }
          .marca { font-size:20px; }
          .razon-social { font-size:11px; }
          .monto-box { font-size:22px; padding:12px 4px; }
          .footer { font-size:11px; padding-bottom:16px; }
        }

        @media print {
          .controles { display:none; }
          @page { size:${formato58 ? '58' : '80'}mm auto; margin:3mm 2mm; }
          body { background:#fff; }
          .ticket { width:${formato58 ? '54' : '76'}mm; margin:0; padding:0; font-size:${formato58 ? '8' : '9'}pt; }
          .cabecera { padding:2px 0 2px; }
          .marca { font-size:${formato58 ? '11' : '13'}pt; }
          .razon-social { font-size:7pt; }
          .fila { margin:2px 0; }
          .desc { font-size:8.5pt; }
          .monto-box { font-size:13pt; padding:4px 2px; }
          .footer { font-size:7pt; }
          .ticket-num { font-size:9pt; margin:2px 0 4px; }
          * { -webkit-font-smoothing:none !important; text-rendering:crispEdges !important; }
          .ticket, .ticket * { color:#000 !important; background:transparent !important;
            opacity:1 !important; text-shadow:none !important; box-shadow:none !important;
            font-family:'Courier New',Courier,monospace !important; font-weight:800 !important; }
        }
      `}</style>

      <div className="controles">
        <button className="btn-print" onClick={() => window.print()}>Imprimir</button>
        <button className="btn-close" onClick={() => window.close()}>✕ Cerrar</button>
      </div>

      <div className="ticket">
        <div className="cabecera">
          <span className="marca">BITEL</span>
        </div>
        <div className="razon-social">BITEL TELECOM</div>
        <div className="ticket-num">{ticketNum}</div>

        <div className="sep" />

        <div className="fila">
          <span className="lbl">Fecha:</span>
          <span className="val">{fecha} {hora}</span>
        </div>
        <div className="fila">
          <span className="lbl">Cajero:</span>
          <span className="val">{cajero}</span>
        </div>
        {ticket.nombre_cliente && (
          <div className="fila">
            <span className="lbl">Cliente:</span>
            <span className="val">{ticket.nombre_cliente}</span>
          </div>
        )}
        {ticket.dni_cliente && (
          <div className="fila">
            <span className="lbl">DNI/Cel:</span>
            <span className="val">{ticket.dni_cliente}</span>
          </div>
        )}

        <div className="sep" />

        <div className="fila"><span className="lbl-bold">Concepto:</span></div>
        <div className="desc">{ticket.descripcion}</div>

        <div className="sep" />

        <div className="monto-box">TOTAL &nbsp; S/ {Number(ticket.monto).toFixed(2)}</div>

        {(pef > 0 || pya > 0 || pbi > 0 || ppl > 0) && (
          <>
            <div className="sep" />
            {pef > 0 && (
              <div className="fila">
                <span className="lbl">Efectivo{pvl > 0 ? ' entregado' : ''}:</span>
                <span className="val">S/ {(pef + pvl).toFixed(2)}</span>
              </div>
            )}
            {pya > 0 && <div className="fila"><span className="lbl">Yape:</span><span className="val">S/ {pya.toFixed(2)}</span></div>}
            {pbi > 0 && <div className="fila"><span className="lbl">Bipay:</span><span className="val">S/ {pbi.toFixed(2)}</span></div>}
            {ppl > 0 && <div className="fila"><span className="lbl">Plin/Transf:</span><span className="val">S/ {ppl.toFixed(2)}</span></div>}
            {pvl > 0 && <div className="fila"><span className="lbl lbl-bold">Vuelto:</span><span className="val lbl-bold">S/ {pvl.toFixed(2)}</span></div>}
          </>
        )}

        <div className="footer">Gracias por su pago<br />Conserve este comprobante</div>
      </div>
    </>
  )
}

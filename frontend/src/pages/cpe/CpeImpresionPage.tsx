import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import { ArrowLeft, Printer } from '@phosphor-icons/react'

const FORMATOS_VALIDOS = ['A4', 'a5', '80mm', 'ticket'] as const
type Formato = (typeof FORMATOS_VALIDOS)[number]

const FORMATO_LABEL: Record<Formato, string> = {
  A4: 'A4',
  a5: 'A5',
  '80mm': '80mm',
  ticket: 'Ticket',
}

const apiBase = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://localhost:8000/api'

/**
 * Vista de impresión pública de un comprobante en los 4 formatos del legacy
 * (A4/a5/80mm/ticket). El layout del PDF lo genera la API externa de
 * facturación (Greenter); esta página solo embebe ese PDF y ofrece el
 * selector de formato + disparo de impresión, igual que `TicketImpresionPage`.
 */
export function CpeImpresionPage() {
  const { id } = useParams<{ id: string }>()
  const [params, setParams] = useSearchParams()
  const iframeRef = useRef<HTMLIFrameElement>(null)
  const [loadedUrl, setLoadedUrl] = useState<string | null>(null)

  const exp   = params.get('exp') ?? ''
  const firma = params.get('firma') ?? ''
  const formatoParam = params.get('formato') ?? 'A4'
  const formato: Formato = (FORMATOS_VALIDOS as readonly string[]).includes(formatoParam)
    ? (formatoParam as Formato)
    : 'A4'

  const enlaceInvalido = !id || !exp || !firma

  const pdfUrl = useMemo(() => {
    if (enlaceInvalido) return null
    const qs = new URLSearchParams({ exp, firma, formato })
    return `${apiBase}/v1/cpe/${id}/descargar/pdf?${qs.toString()}`
  }, [enlaceInvalido, id, exp, firma, formato])

  const cargando = loadedUrl !== pdfUrl

  useEffect(() => {
    if (params.get('print') !== '1' || cargando) return
    const timer = setTimeout(() => {
      try {
        iframeRef.current?.contentWindow?.print()
      } catch {
        // Cross-origin en desarrollo: el usuario imprime con los controles nativos del visor de PDF.
      }
    }, 500)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [cargando])

  const cambiarFormato = (f: Formato) => {
    const next = new URLSearchParams(params)
    next.set('formato', f)
    setParams(next, { replace: true })
  }

  return (
    <div style={{ display: 'flex', flexDirection: 'column', height: '100vh', background: '#e5e7eb' }}>
      <style>{`
        @media print {
          .controles { display: none !important; }
          html, body, #root { height: 100%; margin: 0; padding: 0; }
        }
      `}</style>

      <div
        className="controles"
        style={{
          position: 'sticky', top: 0, zIndex: 100, display: 'flex', gap: 8, alignItems: 'center',
          justifyContent: 'space-between', padding: '10px 14px', background: '#1c1c1c', fontFamily: 'sans-serif',
        }}
      >
        <button
          onClick={() => window.history.back()}
          style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '7px 14px', fontSize: 13, fontWeight: 'bold', border: 'none', borderRadius: 4, cursor: 'pointer', background: '#555', color: '#fff' }}
        >
          <ArrowLeft size={14} /> Volver
        </button>

        <div style={{ display: 'flex', gap: 6 }}>
          {FORMATOS_VALIDOS.map((f) => (
            <button
              key={f}
              onClick={() => cambiarFormato(f)}
              style={{
                padding: '6px 12px', fontSize: 12, fontWeight: 600, borderRadius: 999, cursor: 'pointer',
                border: f === formato ? '1px solid #6366f1' : '1px solid #444',
                background: f === formato ? 'rgba(99,102,241,.25)' : 'transparent',
                color: f === formato ? '#c7d2fe' : '#ccc',
              }}
            >
              {FORMATO_LABEL[f]}
            </button>
          ))}
        </div>

        <button
          onClick={() => iframeRef.current?.contentWindow?.print()}
          style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '7px 16px', fontSize: 13, fontWeight: 'bold', border: 'none', borderRadius: 4, cursor: 'pointer', background: '#22c55e', color: '#fff' }}
        >
          <Printer size={14} weight="bold" /> Imprimir
        </button>
      </div>

      {enlaceInvalido ? (
        <div style={{ fontFamily: 'sans-serif', padding: 24, textAlign: 'center', color: '#374151' }}>
          Enlace inválido o incompleto.
        </div>
      ) : (
        <iframe
          ref={iframeRef}
          src={pdfUrl ?? undefined}
          title={`Comprobante ${id} (${FORMATO_LABEL[formato]})`}
          onLoad={() => setLoadedUrl(pdfUrl)}
          style={{ flex: 1, border: 'none', width: '100%' }}
        />
      )}
    </div>
  )
}

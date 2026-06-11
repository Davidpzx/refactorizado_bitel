import { useEffect, useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { bipayCajeroApi } from '../services/bipayCajero.api'
import { Card } from './ui/card'
import { Button } from './ui/button'
import { Input } from './ui/input'

function fmt(n: number | null | undefined): string {
  if (n === null || n === undefined) return '—'
  return `S/ ${Number(n).toFixed(2)}`
}

function mmss(segs: number): string {
  const m = Math.floor(segs / 60)
  const s = segs % 60
  return `${m}:${String(s).padStart(2, '0')}`
}

export function BipayConsole() {
  const qc = useQueryClient()
  const [bipay, setBipay] = useState('')
  const [anypay, setAnypay] = useState('')
  const [cierreBipay, setCierreBipay] = useState('')
  const [cierreAnypay, setCierreAnypay] = useState('')
  const [cooldown, setCooldown] = useState(0)
  const [msg, setMsg] = useState<{ tipo: 'ok' | 'err'; texto: string } | null>(null)

  const { data, isError } = useQuery({
    queryKey: ['bipay-cajero-estado'],
    queryFn: () => bipayCajeroApi.estado(),
    refetchInterval: 15_000,
    retry: false,
  })

  // Sincronizar cooldown desde el backend y descontar localmente
  useEffect(() => {
    if (data?.cooldown_segs !== undefined) setCooldown(data.cooldown_segs)
  }, [data?.cooldown_segs])

  useEffect(() => {
    if (cooldown <= 0) return
    const t = setInterval(() => setCooldown((c) => Math.max(0, c - 1)), 1000)
    return () => clearInterval(t)
  }, [cooldown])

  const invalidate = () => qc.invalidateQueries({ queryKey: ['bipay-cajero-estado'] })

  const tramo = useMutation({
    mutationFn: () => bipayCajeroApi.tramo(
      bipay !== '' ? Number(bipay) : undefined,
      anypay !== '' ? Number(anypay) : undefined,
    ),
    onSuccess: (r: { ok: boolean; msg?: string; cooldown_segs?: number }) => {
      if (r.ok) { setMsg({ tipo: 'ok', texto: 'Tramo registrado.' }); setBipay(''); setAnypay(''); if (r.cooldown_segs) setCooldown(r.cooldown_segs); invalidate() }
      else { setMsg({ tipo: 'err', texto: r.msg ?? 'No se pudo registrar.' }); if (r.cooldown_segs) setCooldown(r.cooldown_segs) }
    },
    onError: () => setMsg({ tipo: 'err', texto: 'Error al registrar el tramo.' }),
  })

  const cierre = useMutation({
    mutationFn: () => bipayCajeroApi.cierre(
      cierreBipay !== '' ? Number(cierreBipay) : undefined,
      cierreAnypay !== '' ? Number(cierreAnypay) : undefined,
    ),
    onSuccess: (r: { ok: boolean; msg?: string }) => {
      if (r.ok) { setMsg({ tipo: 'ok', texto: 'Jornada cerrada.' }); invalidate() }
      else setMsg({ tipo: 'err', texto: r.msg ?? 'No se pudo cerrar.' })
    },
    onError: () => setMsg({ tipo: 'err', texto: 'Error al cerrar la jornada.' }),
  })

  // La tienda no tiene cuenta Bipay → no mostrar la consola
  if (isError || (data && !data.ok)) return null
  if (!data) return null

  const enCooldown = cooldown > 0
  const sumaActual = (data.bipay_actual ?? 0) + (data.anypay_actual ?? 0)
  const bajoUmbral = data.umbral > 0 && sumaActual > 0 && sumaActual <= data.umbral

  return (
    <Card className="p-4">
      <div className="flex items-center justify-between mb-3">
        <h3 className="font-bold text-sm flex items-center gap-2">
          💳 Consola Bipay / Anypay
          {data.cerrado && <span className="text-[10px] bg-zinc-200 dark:bg-zinc-700 px-2 py-0.5 rounded-full">Cerrado hoy</span>}
        </h3>
        {bajoUmbral && (
          <span className="text-[11px] font-bold text-red-600 dark:text-red-400 badge-pulse">
            ⚠ Saldo bajo (umbral {fmt(data.umbral)})
          </span>
        )}
      </div>

      {/* Saldos */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3 text-center">
        <div className="rounded-lg bg-zinc-100 dark:bg-zinc-800/60 p-2">
          <p className="text-[10px] uppercase tracking-wider text-zinc-500">Bipay actual</p>
          <p className="font-bold text-sm tabular-nums">{fmt(data.bipay_actual)}</p>
        </div>
        <div className="rounded-lg bg-zinc-100 dark:bg-zinc-800/60 p-2">
          <p className="text-[10px] uppercase tracking-wider text-zinc-500">Anypay actual</p>
          <p className="font-bold text-sm tabular-nums">{fmt(data.anypay_actual)}</p>
        </div>
        <div className="rounded-lg bg-zinc-100 dark:bg-zinc-800/60 p-2">
          <p className="text-[10px] uppercase tracking-wider text-zinc-500">Bipay (red)</p>
          <p className="font-semibold text-sm tabular-nums text-zinc-500">{fmt(data.bipay_live)}</p>
        </div>
        <div className="rounded-lg bg-zinc-100 dark:bg-zinc-800/60 p-2">
          <p className="text-[10px] uppercase tracking-wider text-zinc-500">Anypay (red)</p>
          <p className="font-semibold text-sm tabular-nums text-zinc-500">{fmt(data.anypay_live)}</p>
        </div>
      </div>

      {msg && (
        <p className={`text-xs mb-2 ${msg.tipo === 'ok' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'}`}>{msg.texto}</p>
      )}

      {!data.cerrado && (
        <>
          {/* Tramo */}
          <div className="flex flex-wrap items-end gap-2 mb-2">
            <div className="flex-1 min-w-[110px]">
              <label className="text-[10px] uppercase tracking-wider text-zinc-500">Saldo Bipay</label>
              <Input type="number" step="0.01" min="0" value={bipay} onChange={(e) => setBipay(e.target.value)} placeholder="0.00" />
            </div>
            <div className="flex-1 min-w-[110px]">
              <label className="text-[10px] uppercase tracking-wider text-zinc-500">Saldo Anypay</label>
              <Input type="number" step="0.01" min="0" value={anypay} onChange={(e) => setAnypay(e.target.value)} placeholder="0.00" />
            </div>
            <Button type="button" disabled={enCooldown || tramo.isPending || (bipay === '' && anypay === '')}
              onClick={() => { setMsg(null); tramo.mutate() }}>
              {enCooldown ? `Espera ${mmss(cooldown)}` : tramo.isPending ? 'Registrando…' : 'Registrar tramo'}
            </Button>
          </div>

          {/* Cierre */}
          <details className="mt-2">
            <summary className="text-xs text-zinc-500 cursor-pointer hover:text-zinc-700 dark:hover:text-zinc-300">Cerrar jornada Bipay/Anypay</summary>
            <div className="flex flex-wrap items-end gap-2 mt-2">
              <div className="flex-1 min-w-[110px]">
                <label className="text-[10px] uppercase tracking-wider text-zinc-500">Cierre Bipay</label>
                <Input type="number" step="0.01" min="0" value={cierreBipay} onChange={(e) => setCierreBipay(e.target.value)} placeholder="0.00" />
              </div>
              <div className="flex-1 min-w-[110px]">
                <label className="text-[10px] uppercase tracking-wider text-zinc-500">Cierre Anypay</label>
                <Input type="number" step="0.01" min="0" value={cierreAnypay} onChange={(e) => setCierreAnypay(e.target.value)} placeholder="0.00" />
              </div>
              <Button type="button" variant="outline" disabled={cierre.isPending || (cierreBipay === '' && cierreAnypay === '')}
                onClick={() => { setMsg(null); cierre.mutate() }}>
                {cierre.isPending ? 'Cerrando…' : 'Cerrar jornada'}
              </Button>
            </div>
          </details>
        </>
      )}

      {/* Estado de otras tiendas de la razón social */}
      {data.tiendas_estado.length > 1 && (
        <div className="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
          <p className="text-[10px] uppercase tracking-wider text-zinc-500 mb-1">Otras tiendas de tu razón social</p>
          <div className="flex flex-wrap gap-1.5">
            {data.tiendas_estado.map((t) => (
              <span key={t.codigo} className="text-[11px] px-2 py-0.5 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center gap-1">
                <span className="font-semibold">{t.codigo}</span>
                {t.cooldown_segs > 0
                  ? <span className="text-amber-600 dark:text-amber-400">{mmss(t.cooldown_segs)}</span>
                  : <span className="text-green-600 dark:text-green-400">libre</span>}
              </span>
            ))}
          </div>
        </div>
      )}
    </Card>
  )
}

import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PageHeader } from '../../components/PageHeader'
import { integradorApi, type CredencialTienda } from '../../services/integradorBitel.api'
import { apiErrorData } from '../../lib/httpError'
import { Plugs as Plug, Keyhole as KeyRound } from '@phosphor-icons/react'
import { useConfirmDialog } from '../../components/ui/confirm-dialog'

function EstadoAgente({ estado }: { estado: CredencialTienda['estado_agente'] }) {
  const map = {
    EN_LINEA:     ['En línea', 'border-kyro-success/30 bg-kyro-success/10 text-kyro-success'],
    CAIDO:        [`Caído hace ${estado.minutos} min`, 'border-kyro-danger/30 bg-kyro-danger/10 text-kyro-danger'],
    SIN_CONTACTO: ['Sin contacto', 'border-kyro-warning/30 bg-kyro-warning/10 text-kyro-warning'],
    DESACTIVADO:  ['Desactivado', 'border-kyro-border bg-kyro-elevated/40 text-kyro-muted'],
  } as const
  const [label, cls] = map[estado.estado]
  return <span className={`inline-flex rounded-full border px-2.5 py-0.5 text-[0.68rem] font-bold ${cls}`}>{label}</span>
}

function FilaTienda({ fila }: { fila: CredencialTienda }) {
  const qc = useQueryClient()
  const [username, setUsername]   = useState(fila.bitel_username ?? '')
  const [password, setPassword]   = useState('')
  const [intervalo, setIntervalo] = useState(fila.sync_intervalo_min ?? 15)
  const [token, setToken]         = useState<string | null>(null)
  const [msg, setMsg]             = useState<string | null>(null)
  const [err, setErr]             = useState<string | null>(null)

  const invalidar = () => qc.invalidateQueries({ queryKey: ['integrador-credenciales'] })
  const onOk = (r: { message: string; token?: string }) => {
    setMsg(r.message); setErr(null); setToken(r.token ?? null); setPassword(''); invalidar()
  }
  const onErr = (e: unknown) => {
    const d = apiErrorData(e)
    setErr(d.error ?? d.message ?? 'Error inesperado'); setMsg(null)
  }

  const guardar = useMutation({
    mutationFn: () => integradorApi.guardarCredenciales({
      tienda_codigo: fila.codigo, bitel_username: username,
      bitel_password: password || undefined, sync_intervalo_min: intervalo,
    }),
    onSuccess: onOk, onError: onErr,
  })
  const regenerar = useMutation({
    mutationFn: () => integradorApi.regenerarToken(fila.codigo),
    onSuccess: onOk, onError: onErr,
  })
  const toggle = useMutation({
    mutationFn: () => integradorApi.toggleActivo(fila.codigo),
    onSuccess: invalidar, onError: onErr,
  })

  const confirmDialog = useConfirmDialog()
  const confirmarRegenerar = async () => {
    const ok = await confirmDialog({
      title: '¿Regenerar token?',
      description: 'El agente actual dejará de sincronizar hasta actualizar su config.php.',
      intent: 'indigo',
      icon: KeyRound,
      confirmLabel: 'Regenerar',
    })
    if (ok) regenerar.mutate()
  }

  return (
    <div className="kyro-card flex flex-col gap-4 rounded-[18px] p-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <span className="font-mono font-bold text-kyro-indigo">{fila.codigo}</span>
          <span className="ml-2 text-xs text-kyro-muted">{fila.nombre}</span>
        </div>
        <div className="flex items-center gap-2 text-[0.68rem] text-kyro-muted">
          <EstadoAgente estado={fila.estado_agente} />
          {fila.configurada === 1 && <span>Últ. contacto: {fila.last_config_fetch ?? '—'}</span>}
        </div>
      </div>

      {msg && <p className="text-xs text-kyro-success">{msg}</p>}
      {err && <p className="text-xs text-kyro-danger">{err}</p>}

      {token && (
        <div className="rounded-[10px] border border-kyro-indigo/30 bg-kyro-indigo/10 p-3 text-xs">
          <p className="mb-1 font-bold text-kyro-indigo">Token del agente — cópialo AHORA (no se volverá a mostrar):</p>
          <code className="block break-all select-all font-mono text-kyro-body">{token}</code>
          <a
            className="mt-2 inline-block rounded-[10px] border border-kyro-indigo/30 px-2.5 py-1 font-semibold text-kyro-indigo"
            href={integradorApi.urlDescargaAgente(fila.codigo, token)}
          >
            Descargar paquete de instalación (ZIP)
          </a>
        </div>
      )}

      <div className="flex flex-wrap items-end gap-3">
        <label className="flex flex-col text-[0.65rem] text-kyro-muted">
          Usuario Bitel
          <input value={username} onChange={(e) => setUsername(e.target.value)} maxLength={80}
            className="mt-1 h-10 w-44 rounded-[10px] border border-kyro-border bg-transparent px-3 font-mono text-xs text-kyro-body" />
        </label>
        <label className="flex flex-col text-[0.65rem] text-kyro-muted">
          Contraseña Bitel {fila.configurada === 1 && '(vacío = no cambiar)'}
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password"
            placeholder={fila.configurada === 1 ? '••••••••  (configurada)' : 'Obligatoria'}
            className="mt-1 h-10 w-44 rounded-[10px] border border-kyro-border bg-transparent px-3 font-mono text-xs text-kyro-body" />
        </label>
        <label className="flex flex-col text-[0.65rem] text-kyro-muted">
          Intervalo (min)
          <input type="number" min={5} max={240} value={intervalo}
            onChange={(e) => setIntervalo(Number(e.target.value))}
            className="mt-1 h-10 w-24 rounded-[10px] border border-kyro-border bg-transparent px-3 font-mono text-xs tabular-nums text-kyro-body" />
        </label>
        <button onClick={() => guardar.mutate()} disabled={!username.trim() || guardar.isPending}
          className="h-9 rounded-[10px] bg-kyro-indigo px-3 text-xs font-bold text-white disabled:opacity-50">
          Guardar
        </button>
        {fila.configurada === 1 && (
          <>
            <button
              onClick={confirmarRegenerar}
              className="h-9 rounded-[10px] border border-kyro-warning/30 bg-kyro-warning/10 px-3 text-xs font-semibold text-kyro-warning">
              Regenerar token
            </button>
            <button onClick={() => toggle.mutate()}
              className={`h-9 rounded-[10px] border px-3 text-xs font-semibold ${fila.activo ? 'border-kyro-danger/30 bg-kyro-danger/10 text-kyro-danger' : 'border-kyro-success/30 bg-kyro-success/10 text-kyro-success'}`}>
              {fila.activo ? 'Desactivar' : 'Activar'}
            </button>
          </>
        )}
      </div>
    </div>
  )
}

export function IntegradorPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['integrador-credenciales'],
    queryFn:  () => integradorApi.credenciales(),
  })

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title="Integrador Bipay — Credenciales Bitel"
        subtitle="Cada tienda gestiona el acceso de su agente de sincronización (extractor local)"
        Icon={Plug}
      />
      {isLoading && <p className="py-10 text-center text-sm text-kyro-muted">Cargando...</p>}
      <div className="space-y-4">
        {(data ?? []).map((fila) => <FilaTienda key={fila.codigo} fila={fila} />)}
      </div>
    </div>
  )
}

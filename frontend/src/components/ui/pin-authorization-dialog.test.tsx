import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { PinAuthorizationDialog } from './pin-authorization-dialog'
import { api } from '../../services/api'

vi.mock('../../services/api', () => ({
  api: { post: vi.fn() },
}))

const postMock = vi.mocked(api.post)

describe('PinAuthorizationDialog', () => {
  it('autoriza cuando el DNI y PIN son correctos', async () => {
    postMock.mockResolvedValueOnce({
      data: { valid: true, rol: 'agente', agente_id: 7, nombre: 'Juan Pérez' },
    } as never)

    const onAuthorized = vi.fn()
    const onCancel = vi.fn()
    const user = userEvent.setup()

    render(<PinAuthorizationDialog onAuthorized={onAuthorized} onCancel={onCancel} />)

    await user.type(screen.getByLabelText(/dni del agente/i), '12345678')
    await user.type(screen.getByLabelText(/pin de seguridad/i), '2468')
    await user.click(screen.getByRole('button', { name: /autorizar/i }))

    await waitFor(() =>
      expect(onAuthorized).toHaveBeenCalledWith({ rol: 'agente', agenteId: 7, nombre: 'Juan Pérez' })
    )
    expect(onCancel).not.toHaveBeenCalled()
    expect(postMock).toHaveBeenCalledWith('/v1/auth/verify-pin', { dni: '12345678', pin: '2468' })
  })

  it('muestra error sin cerrar cuando el PIN es incorrecto', async () => {
    postMock.mockRejectedValueOnce({
      isAxiosError: true,
      response: { status: 422, data: { valid: false, error: 'DNI o PIN incorrecto' } },
    })

    const onAuthorized = vi.fn()
    const onCancel = vi.fn()
    const user = userEvent.setup()

    render(<PinAuthorizationDialog onAuthorized={onAuthorized} onCancel={onCancel} />)

    await user.type(screen.getByLabelText(/dni del agente/i), '12345678')
    await user.type(screen.getByLabelText(/pin de seguridad/i), '0000')
    await user.click(screen.getByRole('button', { name: /autorizar/i }))

    expect(await screen.findByRole('alert')).toHaveTextContent('DNI o PIN incorrecto')
    expect(onAuthorized).not.toHaveBeenCalled()
    expect(onCancel).not.toHaveBeenCalled()
    // El PIN se limpia para reintentar, el diálogo permanece montado
    expect(screen.getByLabelText(/pin de seguridad/i)).toHaveValue('')
  })
})

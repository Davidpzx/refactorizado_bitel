import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { ThemeProvider } from './context/ThemeContext'
import { ConfirmDialogProvider } from './components/ui/confirm-dialog'
import { PinAuthorizationProvider } from './components/ui/pin-authorization-dialog'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <ConfirmDialogProvider>
        <PinAuthorizationProvider>
          <App />
        </PinAuthorizationProvider>
      </ConfirmDialogProvider>
    </ThemeProvider>
  </StrictMode>,
)

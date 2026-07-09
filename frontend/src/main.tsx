import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { ThemeProvider } from './context/ThemeContext'
import { ConfirmDialogProvider } from './components/ui/confirm-dialog'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ThemeProvider>
      <ConfirmDialogProvider>
        <App />
      </ConfirmDialogProvider>
    </ThemeProvider>
  </StrictMode>,
)

import { ChatCircleDots } from '@phosphor-icons/react'

export function CrmWhatsAppTab() {
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-24 text-center text-kyro-muted">
      <ChatCircleDots size={40} />
      <p className="text-sm">El inbox de WhatsApp llega en la fase F3.</p>
    </div>
  )
}

export interface StoreSettings {
  phone: string | null
  whatsapp: string | null
  email: string | null
  address: string | null
  working_hours: string | null
  instagram_url: string | null
  telegram_url: string | null
}

export interface StoreSettingsResponse {
  data: StoreSettings
}

export function whatsappHref(value: string | null): string | null {
  if (!value?.trim()) return null

  const contact = value.trim()
  if (/^(https?:\/\/|whatsapp:\/\/)/i.test(contact)) return contact

  const digits = contact.replace(/\D/g, '')
  return digits ? `https://wa.me/${digits}` : null
}

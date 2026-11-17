import { useEffect, useState, type FormEvent } from 'react'
import { ChartNoAxesColumn, Save } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'
import type { StoreSettings, StoreSettingsResponse } from '@/lib/store'

const emptySettings: StoreSettings = {
  phone: null,
  whatsapp: null,
  email: null,
  address: null,
  working_hours: null,
  instagram_url: null,
  telegram_url: null,
}

const fields: { key: keyof StoreSettings; label: string; type?: string; hint?: string }[] = [
  { key: 'phone', label: 'Телефон магазина', type: 'tel' },
  { key: 'whatsapp', label: 'WhatsApp', hint: 'Укажите номер с кодом страны или ссылку wa.me.' },
  { key: 'email', label: 'Электронная почта', type: 'email' },
  { key: 'address', label: 'Адрес' },
  { key: 'working_hours', label: 'Время работы' },
  { key: 'instagram_url', label: 'Instagram', type: 'url' },
  { key: 'telegram_url', label: 'Telegram', type: 'url' },
]

export function StoreSettingsPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [settings, setSettings] = useState<StoreSettings>(emptySettings)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const canManageSettings = user !== null && ['administrator', 'super_administrator'].includes(user.role)

  useEffect(() => {
    if (!canManageSettings) {
      setLoading(false)
      return
    }

    let active = true
    void apiRequest<StoreSettingsResponse>('/admin/store-settings')
      .then((response) => {
        if (active) setSettings(response.data)
      })
      .catch((requestError: unknown) => {
        if (active) setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить настройки магазина.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [canManageSettings])

  const updateField = (key: keyof StoreSettings, value: string) => {
    setSettings((current) => ({ ...current, [key]: value }))
  }

  const save = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setMessage(null)

    try {
      const body = Object.fromEntries(
        Object.entries(settings).map(([key, value]) => [key, value?.trim() || null]),
      )
      const response = await apiRequest<StoreSettingsResponse & { message: string }>('/admin/store-settings', {
        method: 'PUT',
        body: JSON.stringify(body),
      })
      setSettings(response.data)
      setMessage(response.message)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось сохранить настройки магазина.')
    } finally {
      setBusy(false)
    }
  }

  if (!canManageSettings) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">
          {sessionError ?? (user ? 'Настраивать контакты может администратор.' : 'Войдите под аккаунтом администратора.')}
        </p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
        <header className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Aluxtorg</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Контакты магазина</h1>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
              Эти данные показываются в подвале сайта и используются для связи с покупателями. Не заполняйте поля демонстрационными контактами.
            </p>
          </div>
          <a className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" href="/admin/analytics">
            <ChartNoAxesColumn aria-hidden="true" className="size-4" /> Статистика
          </a>
        </header>

        {(error || sessionError || message) && (
          <div className={`mb-5 rounded-xl border px-4 py-3 text-sm ${error || sessionError ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`} role={error || sessionError ? 'alert' : 'status'}>
            {error ?? sessionError ?? message}
          </div>
        )}

        <form className="rounded-2xl border bg-card p-4 sm:p-6" onSubmit={(event) => void save(event)}>
          {loading ? (
            <p className="py-10 text-center text-sm text-muted-foreground" role="status">Загружаем настройки…</p>
          ) : (
            <>
              <div className="grid gap-5 sm:grid-cols-2">
                {fields.map(({ key, label, type = 'text', hint }) => (
                  <label className="block" key={key}>
                    <span className="mb-2 block text-sm font-medium">{label}</span>
                    <input
                      autoComplete="off"
                      className="h-11 w-full rounded-xl border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                      maxLength={key === 'phone' ? 32 : key === 'whatsapp' ? 128 : key.endsWith('_url') ? 2048 : key === 'email' ? 254 : 255}
                      onChange={(event) => updateField(key, event.target.value)}
                      type={type}
                      value={settings[key] ?? ''}
                    />
                    {hint && <span className="mt-1.5 block text-xs leading-5 text-muted-foreground">{hint}</span>}
                  </label>
                ))}
              </div>
              <div className="mt-6 flex justify-end border-t pt-5">
                <button className="inline-flex h-11 items-center gap-2 rounded-xl bg-primary px-5 text-sm font-medium !text-white hover:opacity-90 disabled:opacity-50" disabled={busy} type="submit">
                  <Save aria-hidden="true" className="size-4" /> {busy ? 'Сохраняем…' : 'Сохранить контакты'}
                </button>
              </div>
            </>
          )}
        </form>
      </div>
    </main>
  )
}

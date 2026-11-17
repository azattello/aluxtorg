import { useEffect, useState, type FormEvent } from 'react'
import { ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'
import { ViewModeToggle, type ViewMode } from '@/components/ViewModeToggle'

interface Collection<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

interface ReturnItem {
  order_item_id: number
  product_name: string
  variant_name: string | null
  sku: string
  sale_unit: string | null
  quantity: number
}

interface ReturnStatusHistory {
  from_status: string | null
  to_status: string
  note: string | null
  created_at: string | null
}

interface ReturnRequest {
  id: number
  status: string
  reason: string
  order: {
    id: number
    number: string
    customer_name: string
    customer_phone: string
    customer_email: string | null
  }
  items: ReturnItem[]
  status_history: ReturnStatusHistory[]
  created_at: string | null
}

interface ReturnRequestResponse {
  data: ReturnRequest
}

const returnStatuses = [
  ['submitted', 'Заявка получена'],
  ['reviewing', 'На рассмотрении'],
  ['approved', 'Одобрена'],
  ['rejected', 'Отклонена'],
  ['awaiting_handover', 'Ожидает передачи товара'],
  ['received', 'Товар принят и проверен'],
  ['refund_processed', 'Возврат денег обработан'],
  ['closed', 'Закрыта'],
] as const

const statusLabels = Object.fromEntries(returnStatuses)
const transitions: Record<string, string[]> = {
  submitted: ['reviewing', 'rejected'],
  reviewing: ['approved', 'rejected'],
  approved: ['awaiting_handover', 'rejected'],
  rejected: [],
  awaiting_handover: ['received', 'rejected'],
  received: ['refund_processed', 'closed', 'rejected'],
  refund_processed: ['closed'],
  closed: [],
}

function formattedDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-KZ') : '—'
}

function whatsappUrl(phone: string): string | null {
  const digits = phone.replace(/\D/g, '')
  return digits.length >= 7 ? `https://wa.me/${digits}` : null
}

export function ReturnManagementPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [requests, setRequests] = useState<ReturnRequest[]>([])
  const [selected, setSelected] = useState<ReturnRequest | null>(null)
  const [statusFilter, setStatusFilter] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [sort, setSort] = useState('newest')
  const [viewMode, setViewMode] = useState<ViewMode>('cards')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [nextStatus, setNextStatus] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const canManageReturns = user && ['manager', 'administrator', 'super_administrator'].includes(user.role)

  const loadRequests = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams({ page: String(page), per_page: '25' })
      if (query) params.set('search', query)
      if (statusFilter) params.set('status', statusFilter)
      params.set('sort', sort)
      const response = await apiRequest<Collection<ReturnRequest>>(`/admin/returns?${params.toString()}`)
      setRequests(response.data)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
      if (selected) {
        const fresh = response.data.find((item) => item.id === selected.id)
        if (fresh) setSelected((current) => current ? { ...current, ...fresh } : fresh)
      }
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить заявки на возврат.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManageReturns) void loadRequests()
  }, [canManageReturns, page, query, statusFilter, sort])

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setPage(1)
    setQuery(search.trim())
  }

  const selectRequest = async (item: ReturnRequest) => {
    setSelected(item)
    setNextStatus('')
    setNote('')
    setError(null)
    try {
      const response = await apiRequest<ReturnRequestResponse>(`/admin/returns/${item.id}`)
      setSelected(response.data)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось открыть заявку.')
    }
  }

  const updateStatus = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!selected || !nextStatus) return

    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const response = await apiRequest<ReturnRequestResponse>(`/admin/returns/${selected.id}/status`, {
        method: 'PATCH',
        body: JSON.stringify({ status: nextStatus, note: note.trim() || null }),
      })
      setSelected(response.data)
      setRequests((current) => current.map((item) => item.id === response.data.id ? response.data : item))
      setNextStatus('')
      setNote('')
      setMessage('Статус возврата обновлён. Покупатель увидит изменение в истории заказа.')
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось изменить статус возврата.')
    } finally {
      setBusy(false)
    }
  }

  if (!canManageReturns) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">
          {sessionError ?? (user ? 'Управлять возвратами может только сотрудник CRM.' : 'Войдите под аккаунтом сотрудника CRM.')}
        </p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Рабочее пространство</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Заявки на возврат</h1>
            <p className="mt-2 text-sm text-muted-foreground">Свяжитесь с покупателем через WhatsApp, проверьте целостность упаковки и фиксируйте этапы возврата.</p>
          </div>
          <button className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" disabled={loading} onClick={() => void loadRequests()} type="button">
            <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} /> Обновить
          </button>
        </div>

        {(error || sessionError || message) && (
          <div className={`mb-5 rounded-xl border px-4 py-3 text-sm ${error || sessionError ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`} role={error || sessionError ? 'alert' : 'status'}>
            {error ?? sessionError ?? message}
          </div>
        )}

        <div className="grid items-start gap-6 lg:grid-cols-[minmax(280px,0.8fr)_minmax(0,1.5fr)]">
          <section className="space-y-4">
            <form className="grid gap-3 rounded-2xl border bg-card p-4" onSubmit={submitSearch}>
              <label className="text-sm font-medium" htmlFor="return-search">Поиск по номеру заказа, имени или телефону</label>
              <input autoComplete="off" className="h-10 rounded-xl border bg-background px-3 text-sm" id="return-search" maxLength={120} onChange={(event) => setSearch(event.target.value)} value={search} />
              <label className="text-sm font-medium" htmlFor="return-status-filter">Статус</label>
              <select className="h-10 rounded-xl border bg-background px-3 text-sm" id="return-status-filter" onChange={(event) => { setPage(1); setStatusFilter(event.target.value) }} value={statusFilter}>
                <option value="">Все статусы</option>
                {returnStatuses.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
              </select>
              <label className="text-sm font-medium" htmlFor="return-sort">Сортировка</label>
              <select className="h-10 rounded-xl border bg-background px-3 text-sm" id="return-sort" onChange={(event) => { setPage(1); setSort(event.target.value) }} value={sort}>
                <option value="newest">Сначала новые</option>
                <option value="oldest">Сначала старые</option>
              </select>
              <button className="h-10 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground" type="submit">Найти</button>
              <p className="text-xs text-muted-foreground">Заявок: {total}</p>
            </form>

            <div className="flex justify-end">
              <ViewModeToggle onChange={setViewMode} value={viewMode} />
            </div>
            <div className="space-y-2" aria-live="polite">
              {!loading && requests.length === 0 && <p className="rounded-xl border bg-card p-6 text-center text-sm text-muted-foreground">Заявок не найдено.</p>}
              {viewMode === 'cards' && requests.map((item) => (
                <button
                  aria-current={selected?.id === item.id ? 'true' : undefined}
                  className={`w-full rounded-xl border p-4 text-left transition hover:bg-card ${selected?.id === item.id ? 'border-primary bg-card' : 'bg-background'}`}
                  key={item.id}
                  onClick={() => void selectRequest(item)}
                  type="button"
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="font-medium">Заявка №{item.id}</span>
                    <span className="rounded-full bg-secondary px-2 py-1 text-[11px]">{statusLabels[item.status] ?? item.status}</span>
                  </span>
                  <span className="mt-2 block text-sm">{item.order.number} · {item.order.customer_name}</span>
                  <span className="mt-1 block text-xs text-muted-foreground">{formattedDate(item.created_at)}</span>
                </button>
              ))}
              {viewMode === 'table' && requests.length > 0 && (
                <div className="overflow-x-auto rounded-xl border bg-card">
                  <table className="w-full min-w-[700px] text-left text-sm">
                    <thead className="border-b bg-muted/40 text-muted-foreground">
                      <tr><th className="px-3 py-3 font-medium">Заявка</th><th className="px-3 py-3 font-medium">Заказ</th><th className="px-3 py-3 font-medium">Покупатель</th><th className="px-3 py-3 font-medium">Статус</th><th className="px-3 py-3 font-medium">Дата</th><th aria-label="Действие" className="px-3 py-3 font-medium" /></tr>
                    </thead>
                    <tbody className="divide-y">
                      {requests.map((item) => (
                        <tr className={selected?.id === item.id ? 'bg-primary/5' : ''} key={item.id}>
                          <td className="px-3 py-3 font-medium">№{item.id}</td>
                          <td className="px-3 py-3">{item.order.number}</td>
                          <td className="px-3 py-3">{item.order.customer_name}<span className="block text-xs text-muted-foreground">{item.order.customer_phone}</span></td>
                          <td className="px-3 py-3">{statusLabels[item.status] ?? item.status}</td>
                          <td className="px-3 py-3 whitespace-nowrap">{formattedDate(item.created_at)}</td>
                          <td className="px-3 py-3 text-right"><button aria-label={`Открыть заявку ${item.id}`} className="rounded-lg border px-3 py-1.5 hover:bg-muted" onClick={() => void selectRequest(item)} type="button">Открыть</button></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>

            <nav aria-label="Страницы заявок" className="flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Страница {page} из {lastPage}</span>
              <div className="flex gap-2">
                <button aria-label="Предыдущая страница" className="rounded-lg border p-2 disabled:opacity-50" disabled={loading || page <= 1} onClick={() => setPage((current) => current - 1)} type="button"><ChevronLeft className="size-4" /></button>
                <button aria-label="Следующая страница" className="rounded-lg border p-2 disabled:opacity-50" disabled={loading || page >= lastPage} onClick={() => setPage((current) => current + 1)} type="button"><ChevronRight className="size-4" /></button>
              </div>
            </nav>
          </section>

          <section className="min-w-0 rounded-2xl border bg-card p-5 sm:p-6">
            {!selected ? (
              <p className="py-12 text-center text-sm text-muted-foreground">Выберите заявку, чтобы связаться с покупателем и обработать возврат.</p>
            ) : (
              <div className="space-y-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <p className="text-sm text-muted-foreground">Заказ {selected.order.number}</p>
                    <h2 className="mt-1 text-xl font-semibold">Заявка №{selected.id}</h2>
                    <p className="mt-1 text-xs text-muted-foreground">Создана {formattedDate(selected.created_at)}</p>
                  </div>
                  <span className="rounded-full bg-secondary px-3 py-1.5 text-xs font-medium">{statusLabels[selected.status] ?? selected.status}</span>
                </div>

                <section className="rounded-xl border p-4">
                  <h3 className="text-sm font-semibold">Покупатель</h3>
                  <p className="mt-2 text-sm">{selected.order.customer_name}</p>
                  <p className="mt-1 text-sm text-muted-foreground">{selected.order.customer_phone}</p>
                  {selected.order.customer_email && <p className="mt-1 text-sm text-muted-foreground">{selected.order.customer_email}</p>}
                  {whatsappUrl(selected.order.customer_phone) && (
                    <a className="mt-3 inline-flex h-10 items-center rounded-lg bg-emerald-700 px-4 text-sm font-medium text-white hover:bg-emerald-800" href={whatsappUrl(selected.order.customer_phone) ?? undefined} rel="noreferrer" target="_blank">
                      Написать в WhatsApp
                    </a>
                  )}
                </section>

                <section>
                  <h3 className="text-sm font-semibold">Причина возврата</h3>
                  <p className="mt-2 whitespace-pre-wrap text-sm leading-6 text-muted-foreground">{selected.reason}</p>
                  <h3 className="mt-4 text-sm font-semibold">Товары и упаковки</h3>
                  <ul className="mt-2 divide-y rounded-xl border">
                    {selected.items.map((item) => (
                      <li className="flex flex-wrap justify-between gap-2 p-3 text-sm" key={item.order_item_id}>
                        <span>{item.product_name}{item.variant_name ? ` — ${item.variant_name}` : ''}<span className="block text-xs text-muted-foreground">SKU {item.sku}</span></span>
                        <span className="font-medium">{item.quantity} {item.sale_unit ?? 'упак.'}</span>
                      </li>
                    ))}
                  </ul>
                </section>

                <section>
                  <h3 className="text-sm font-semibold">История статусов</h3>
                  <ol className="mt-3 space-y-3">
                    {selected.status_history.map((entry, index) => (
                      <li className="border-l-2 border-primary/30 pl-3" key={`${entry.created_at}-${index}`}>
                        <p className="text-sm">{statusLabels[entry.to_status] ?? entry.to_status}</p>
                        <p className="mt-1 text-xs text-muted-foreground">{formattedDate(entry.created_at)}{entry.note ? ` · ${entry.note}` : ''}</p>
                      </li>
                    ))}
                  </ol>
                </section>

                {(transitions[selected.status] ?? []).length > 0 && (
                  <form className="space-y-3 border-t pt-5" onSubmit={(event) => void updateStatus(event)}>
                    <h3 className="font-semibold">Обновить статус</h3>
                    <label className="block text-sm font-medium">Новый статус
                      <select className="mt-2 h-11 w-full rounded-xl border bg-background px-3" onChange={(event) => setNextStatus(event.target.value)} required value={nextStatus}>
                        <option value="">Выберите статус</option>
                        {transitions[selected.status].map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                      </select>
                    </label>
                    <label className="block text-sm font-medium">Внутренняя заметка <span className="font-normal text-muted-foreground">(необязательно)</span>
                      <textarea className="mt-2 min-h-20 w-full rounded-xl border bg-background px-3 py-2 font-normal" maxLength={1000} onChange={(event) => setNote(event.target.value)} value={note} />
                    </label>
                    <p className="text-xs leading-5 text-muted-foreground">Статус «Возврат денег обработан» только фиксирует ручную обработку менеджером — платежный шлюз не подключён.</p>
                    <button className="h-11 w-full rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy || !nextStatus} type="submit">{busy ? 'Сохраняем…' : 'Сохранить статус'}</button>
                  </form>
                )}
              </div>
            )}
          </section>
        </div>
      </div>
    </main>
  )
}

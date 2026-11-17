import { useEffect, useState, type FormEvent } from 'react'
import { RefreshCw } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'
import { ViewModeToggle, type ViewMode } from '@/components/ViewModeToggle'

interface Collection<T> {
  data: T[]
  meta?: { current_page: number; last_page: number }
}

interface StaffMember {
  id: number
  name: string
  role: string
}

interface OrderHistoryEntry {
  from_status: string | null
  to_status: string
  amount_kzt?: number | null
  note: string | null
  actor: { id: number; name: string } | null
  created_at: string | null
}

interface InternalNote {
  id: number
  body: string
  author: { id: number; name: string } | null
  created_at: string | null
}

interface ManagerOrder {
  id: number
  number: string
  assigned_to: { id: number; name: string } | null
  order_status: string
  payment_status: string
  fulfillment_method: 'delivery' | 'pickup'
  customer_name: string
  customer_phone: string
  customer_email: string | null
  delivery_city: string | null
  delivery_street: string | null
  delivery_building: string | null
  delivery_apartment: string | null
  delivery_instructions: string | null
  subtotal_kzt: number
  delivery_fee_kzt: number | null
  total_kzt: number | null
  created_at: string | null
  items: { id: number; product_name: string; variant_name: string | null; sku: string; quantity: number; line_total_kzt: number }[]
  status_history?: OrderHistoryEntry[]
  payment_status_history?: OrderHistoryEntry[]
  internal_notes?: InternalNote[]
}

const orderStatuses = [
  ['new', 'Новый'],
  ['confirmed', 'Подтверждён'],
  ['preparing', 'Собирается'],
  ['ready_for_pickup', 'Готов к самовывозу'],
  ['shipped', 'Отправлен'],
  ['completed', 'Завершён'],
  ['cancelled', 'Отменён'],
] as const

const paymentStatuses = [
  ['unpaid', 'Не оплачен'],
  ['paid', 'Оплачен'],
  ['partially_refunded', 'Частичный возврат'],
  ['refunded', 'Возвращён'],
] as const

const orderStatusLabels = Object.fromEntries(orderStatuses)
const paymentStatusLabels = Object.fromEntries(paymentStatuses)
const money = new Intl.NumberFormat('ru-KZ', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 })

function formattedDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-KZ') : '—'
}

export function ManagerOrdersPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const initialSearch = new URLSearchParams(window.location.search).get('search')?.trim() ?? ''
  const [orders, setOrders] = useState<ManagerOrder[]>([])
  const [staff, setStaff] = useState<StaffMember[]>([])
  const [selected, setSelected] = useState<ManagerOrder | null>(null)
  const [statusFilter, setStatusFilter] = useState('')
  const [assignmentFilter, setAssignmentFilter] = useState('')
  const [search, setSearch] = useState(initialSearch)
  const [query, setQuery] = useState(initialSearch)
  const [sort, setSort] = useState('newest')
  const [viewMode, setViewMode] = useState<ViewMode>('cards')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [noteDraft, setNoteDraft] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const canManageCrm = user && ['manager', 'administrator', 'super_administrator'].includes(user.role)

  const loadOrders = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams({ per_page: '50', page: String(page) })
      if (statusFilter) params.set('status', statusFilter)
      if (assignmentFilter) params.set('assigned_to', assignmentFilter)
      if (query.trim()) params.set('search', query.trim())
      params.set('sort', sort)
      const response = await apiRequest<Collection<ManagerOrder>>(`/admin/orders?${params.toString()}`)
      setOrders(response.data)
      setLastPage(response.meta?.last_page ?? 1)
      if (selected) {
        const fresh = response.data.find((order) => order.id === selected.id)
        if (fresh) setSelected((current) => current ? { ...current, ...fresh } : fresh)
      }
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить заказы.')
    } finally {
      setLoading(false)
    }
  }

  const loadStaff = async () => {
    try {
      const response = await apiRequest<Collection<StaffMember>>('/admin/orders/assignees')
      setStaff(response.data)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить список сотрудников.')
    }
  }

  useEffect(() => {
    if (canManageCrm) {
      void loadOrders()
      void loadStaff()
    }
  }, [canManageCrm, statusFilter, assignmentFilter, query, page, sort])

  const openOrder = async (order: ManagerOrder) => {
    setError(null)
    try {
      const response = await apiRequest<{ data: ManagerOrder }>(`/admin/orders/${order.id}`)
      setSelected(response.data)
      setNoteDraft('')
      setMessage(null)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось открыть заказ.')
    }
  }

  const saveOrder = async (path: string, payload: Record<string, unknown>, successMessage: string) => {
    if (!selected) return
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const response = await apiRequest<{ data: ManagerOrder }>(`/admin/orders/${selected.id}${path}`, {
        method: path === '/notes' ? 'POST' : 'PATCH',
        body: JSON.stringify(payload),
      })
      setSelected(response.data)
      if (path === '/notes') setNoteDraft('')
      setMessage(successMessage)
      await loadOrders()
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось сохранить изменения.')
    } finally {
      setBusy(false)
    }
  }

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setPage(1)
    setQuery(search.trim())
  }

  const submitNote = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const body = String(values.get('body') ?? '').trim()
    if (!body) return
    void saveOrder('/notes', { body }, 'Внутренняя заметка добавлена.')
  }

  const submitStatus = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    void saveOrder('/status', {
      order_status: String(values.get('order_status')),
      note: String(values.get('note') ?? '').trim() || null,
    }, 'Статус заказа обновлён.')
  }

  const submitPayment = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const amount = String(values.get('amount_kzt') ?? '')
    void saveOrder('/payment-status', {
      payment_status: String(values.get('payment_status')),
      ...(amount ? { amount_kzt: Number(amount) } : {}),
      note: String(values.get('payment_note') ?? '').trim() || null,
    }, 'Статус оплаты обновлён.')
  }

  if (!canManageCrm) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">Войдите под аккаунтом с правами управления заказами.</p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  const allowedNextStatuses: Record<string, string[]> = {
    new: ['confirmed', 'cancelled'],
    confirmed: ['preparing', 'cancelled'],
    preparing: ['ready_for_pickup', 'shipped', 'cancelled'],
    ready_for_pickup: ['completed', 'cancelled'],
    shipped: ['completed', 'cancelled'],
    completed: [],
    cancelled: [],
  }
  const allowedPaymentStatuses: Record<string, string[]> = {
    unpaid: ['paid'],
    paid: ['partially_refunded', 'refunded'],
    partially_refunded: ['refunded'],
    refunded: [],
  }

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Рабочее пространство</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Заказы и CRM</h1>
            <p className="mt-2 text-sm text-muted-foreground">Обработка заказов, оплаты, доставки и внутренних заметок.</p>
          </div>
          <button className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" disabled={loading} onClick={() => void loadOrders()} type="button">
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
              <label className="text-sm font-medium">Поиск по номеру, имени или телефону
                <input className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" onChange={(event) => setSearch(event.target.value)} value={search} />
              </label>
              <label className="text-sm font-medium">Статус
                <select className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" onChange={(event) => { setPage(1); setStatusFilter(event.target.value) }} value={statusFilter}>
                  <option value="">Все статусы</option>
                  {orderStatuses.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </label>
              <label className="text-sm font-medium">Ответственный
                <select className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" onChange={(event) => { setPage(1); setAssignmentFilter(event.target.value) }} value={assignmentFilter}>
                  <option value="">Все сотрудники</option>
                  <option value="unassigned">Не назначен</option>
                  {staff.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
                </select>
              </label>
              <label className="text-sm font-medium">Сортировка
                <select className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" onChange={(event) => { setPage(1); setSort(event.target.value) }} value={sort}>
                  <option value="newest">Сначала новые</option>
                  <option value="oldest">Сначала старые</option>
                  <option value="amount_desc">Сначала дороже</option>
                  <option value="amount_asc">Сначала дешевле</option>
                  <option value="customer_asc">По имени покупателя</option>
                </select>
              </label>
              <button className="h-10 rounded-lg bg-primary px-3 text-sm font-medium text-primary-foreground" type="submit">Найти заказы</button>
            </form>

            <div className="mb-3 flex justify-end">
              <ViewModeToggle onChange={setViewMode} value={viewMode} />
            </div>
            <div className="space-y-2" aria-live="polite">
              {loading ? <p className="py-8 text-center text-sm text-muted-foreground">Загружаем заказы…</p> : orders.length === 0 ? (
                <p className="rounded-2xl border bg-card px-4 py-8 text-center text-sm text-muted-foreground">Заказы не найдены.</p>
              ) : viewMode === 'cards' ? orders.map((order) => (
                <button aria-pressed={selected?.id === order.id} className={`w-full rounded-2xl border p-4 text-left transition hover:border-primary/50 ${selected?.id === order.id ? 'border-primary bg-primary/5' : 'bg-card'}`} key={order.id} onClick={() => void openOrder(order)} type="button">
                  <div className="flex items-start justify-between gap-3">
                    <span className="font-semibold">{order.number}</span>
                    <span className="rounded-full bg-muted px-2.5 py-1 text-xs">{orderStatusLabels[order.order_status] ?? order.order_status}</span>
                  </div>
                  <p className="mt-2 text-sm">{order.customer_name}</p>
                  <p className="mt-1 text-xs text-muted-foreground">{order.customer_phone} · {formattedDate(order.created_at)}</p>
                  <p className="mt-2 text-sm font-medium">{order.total_kzt === null ? `Товары: ${money.format(order.subtotal_kzt)} · доставка не согласована` : money.format(order.total_kzt)}</p>
                </button>
              )) : (
                <div className="overflow-x-auto rounded-xl border bg-card">
                  <table className="w-full min-w-[760px] text-left text-sm">
                    <thead className="border-b bg-muted/40 text-muted-foreground">
                      <tr><th className="px-3 py-3 font-medium">Заказ</th><th className="px-3 py-3 font-medium">Покупатель</th><th className="px-3 py-3 font-medium">Статус</th><th className="px-3 py-3 font-medium">Дата</th><th className="px-3 py-3 font-medium">Сумма</th><th aria-label="Действие" className="px-3 py-3 font-medium" /></tr>
                    </thead>
                    <tbody className="divide-y">
                      {orders.map((order) => (
                        <tr className={selected?.id === order.id ? 'bg-primary/5' : ''} key={order.id}>
                          <td className="px-3 py-3 font-medium">{order.number}</td>
                          <td className="px-3 py-3">{order.customer_name}<span className="block text-xs text-muted-foreground">{order.customer_phone}</span></td>
                          <td className="px-3 py-3">{orderStatusLabels[order.order_status] ?? order.order_status}</td>
                          <td className="px-3 py-3 whitespace-nowrap">{formattedDate(order.created_at)}</td>
                          <td className="px-3 py-3 whitespace-nowrap">{order.total_kzt === null ? `${money.format(order.subtotal_kzt)} + доставка` : money.format(order.total_kzt)}</td>
                          <td className="px-3 py-3 text-right"><button aria-label={`Открыть заказ ${order.number}`} className="rounded-lg border px-3 py-1.5 hover:bg-muted" onClick={() => void openOrder(order)} type="button">Открыть</button></td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              {lastPage > 1 && (
                <div className="flex items-center justify-between rounded-xl border bg-card px-3 py-2 text-sm">
                  <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={page <= 1 || loading} onClick={() => setPage((current) => current - 1)} type="button">Назад</button>
                  <span className="text-muted-foreground">Страница {page} из {lastPage}</span>
                  <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={page >= lastPage || loading} onClick={() => setPage((current) => current + 1)} type="button">Далее</button>
                </div>
              )}
            </div>
          </section>

          <section className="min-w-0">
            {!selected ? (
              <div className="flex min-h-64 items-center justify-center rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">Выберите заказ, чтобы просмотреть детали и обработать его.</div>
            ) : (
              <div className="space-y-5">
                <article className="space-y-5 rounded-2xl border bg-card p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <h2 className="text-xl font-semibold">{selected.number}</h2>
                      <p className="mt-1 text-sm text-muted-foreground">Создан: {formattedDate(selected.created_at)}</p>
                    </div>
                    <div className="text-right text-sm">
                      <p>{orderStatusLabels[selected.order_status] ?? selected.order_status}</p>
                      <p className="mt-1 text-muted-foreground">{paymentStatusLabels[selected.payment_status] ?? selected.payment_status}</p>
                    </div>
                  </div>

                  <div className="grid gap-4 border-y py-4 sm:grid-cols-2">
                    <div>
                      <h3 className="text-sm font-semibold">Покупатель</h3>
                      <p className="mt-2 text-sm">{selected.customer_name}</p>
                      <a className="mt-1 block text-sm text-primary underline" href={`tel:${selected.customer_phone}`}>{selected.customer_phone}</a>
                      {selected.customer_email && <p className="mt-1 text-sm text-muted-foreground">{selected.customer_email}</p>}
                    </div>
                    <div>
                      <h3 className="text-sm font-semibold">{selected.fulfillment_method === 'delivery' ? 'Доставка' : 'Самовывоз'}</h3>
                      {selected.fulfillment_method === 'delivery' ? (
                        <p className="mt-2 text-sm">
                          {[selected.delivery_city, selected.delivery_street, selected.delivery_building, selected.delivery_apartment && `кв. ${selected.delivery_apartment}`].filter(Boolean).join(', ')}
                          {selected.delivery_instructions && <span className="mt-1 block text-muted-foreground">{selected.delivery_instructions}</span>}
                        </p>
                      ) : <p className="mt-2 text-sm text-muted-foreground">Детали самовывоза согласовать с покупателем.</p>}
                    </div>
                  </div>

                  <div>
                    <h3 className="text-sm font-semibold">Состав заказа</h3>
                    <div className="mt-2 divide-y rounded-xl border">
                      {selected.items.map((item) => (
                        <div className="flex items-start justify-between gap-3 p-3 text-sm" key={item.id}>
                          <div><p className="font-medium">{item.product_name}</p><p className="mt-1 text-xs text-muted-foreground">{item.variant_name} · {item.sku} · {item.quantity} шт.</p></div>
                          <span className="shrink-0">{money.format(item.line_total_kzt)}</span>
                        </div>
                      ))}
                    </div>
                    <div className="mt-3 space-y-1 text-right text-sm">
                      <p>Товары: {money.format(selected.subtotal_kzt)}</p>
                      <p>Доставка: {selected.delivery_fee_kzt === null ? 'не согласована' : money.format(selected.delivery_fee_kzt)}</p>
                      <p className="font-semibold">Итого: {selected.total_kzt === null ? 'не согласовано' : money.format(selected.total_kzt)}</p>
                    </div>
                  </div>

                  <label className="block text-sm font-medium">Ответственный
                    <select className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" disabled={busy} onChange={(event) => void saveOrder('/assignment', { assigned_to_id: event.target.value ? Number(event.target.value) : null }, 'Ответственный обновлён.')} value={selected.assigned_to?.id ?? ''}>
                      <option value="">Не назначен</option>
                      {staff.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
                    </select>
                  </label>

                  {selected.fulfillment_method === 'delivery' && ['new', 'confirmed'].includes(selected.order_status) && (
                    <form className="grid gap-3 rounded-xl border p-4 sm:grid-cols-[1fr_auto]" onSubmit={(event) => {
                      event.preventDefault()
                      const amount = new FormData(event.currentTarget).get('delivery_fee_kzt')
                      void saveOrder('/delivery-fee', { delivery_fee_kzt: Number(amount) }, 'Стоимость доставки и итог заказа сохранены.')
                    }}>
                      <label className="text-sm font-medium">Согласованная стоимость доставки, ₸
                        <input className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" defaultValue={selected.delivery_fee_kzt ?? ''} min="0" name="delivery_fee_kzt" required type="number" />
                      </label>
                      <button className="self-end rounded-lg border px-4 py-2.5 text-sm hover:bg-muted disabled:opacity-50" disabled={busy} type="submit">Сохранить</button>
                      <p className="text-xs text-muted-foreground sm:col-span-2">Сроки и детали доставки/самовывоза можно зафиксировать во внутренней заметке.</p>
                    </form>
                  )}

                  {allowedNextStatuses[selected.order_status]?.length > 0 && (
                    <form className="grid gap-3 rounded-xl border p-4" key={selected.order_status} onSubmit={submitStatus}>
                      <h3 className="text-sm font-semibold">Изменить статус заказа</h3>
                      <select className="h-10 rounded-lg border bg-background px-3 text-sm" defaultValue="" name="order_status" required>
                        <option disabled value="">Выберите следующий статус</option>
                        {allowedNextStatuses[selected.order_status].map((status) => <option key={status} value={status}>{orderStatusLabels[status]}</option>)}
                      </select>
                      <input className="h-10 rounded-lg border bg-background px-3 text-sm" name="note" placeholder="Комментарий к переходу (необязательно)" />
                      <button className="h-10 rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy} type="submit">Сохранить статус</button>
                    </form>
                  )}

                  {allowedPaymentStatuses[selected.payment_status]?.length > 0 && (
                    <form className="grid gap-3 rounded-xl border p-4" key={selected.payment_status} onSubmit={submitPayment}>
                      <h3 className="text-sm font-semibold">Обновить статус оплаты</h3>
                      <select className="h-10 rounded-lg border bg-background px-3 text-sm" defaultValue="" name="payment_status" required>
                        <option disabled value="">Выберите статус оплаты</option>
                        {allowedPaymentStatuses[selected.payment_status].map((status) => <option key={status} value={status}>{paymentStatusLabels[status]}</option>)}
                      </select>
                      <input className="h-10 rounded-lg border bg-background px-3 text-sm" min="1" name="amount_kzt" placeholder="Сумма оплаты/возврата, ₸ (частичный возврат обязателен)" type="number" />
                      <input className="h-10 rounded-lg border bg-background px-3 text-sm" name="payment_note" placeholder="Комментарий, например способ/дата проверки" />
                      <button className="h-10 rounded-lg border px-4 text-sm font-medium hover:bg-muted disabled:opacity-50" disabled={busy} type="submit">Сохранить оплату</button>
                    </form>
                  )}
                </article>

                <section className="rounded-2xl border bg-card p-5">
                  <h3 className="font-semibold">Внутренние заметки</h3>
                  <p className="mt-1 text-xs text-muted-foreground">Покупатель не видит эти записи. Используйте их для договорённостей и передачи заказа.</p>
                  <div className="mt-4 space-y-3">
                    {(selected.internal_notes ?? []).map((note) => (
                      <article className="rounded-xl bg-muted/50 p-3" key={note.id}>
                        <p className="whitespace-pre-wrap text-sm">{note.body}</p>
                        <p className="mt-2 text-xs text-muted-foreground">{note.author?.name ?? 'Сотрудник'} · {formattedDate(note.created_at)}</p>
                      </article>
                    ))}
                    {(selected.internal_notes ?? []).length === 0 && <p className="text-sm text-muted-foreground">Заметок пока нет.</p>}
                  </div>
                  <form className="mt-4 space-y-3" onSubmit={submitNote}>
                    <textarea className="min-h-24 w-full rounded-xl border bg-background px-3 py-2 text-sm" maxLength={5000} name="body" onChange={(event) => setNoteDraft(event.target.value)} placeholder="Например: доставка согласована на завтра, стоимость 1 500 ₸" required value={noteDraft} />
                    <button className="rounded-lg border px-4 py-2.5 text-sm font-medium hover:bg-muted disabled:opacity-50" disabled={busy} type="submit">Добавить заметку</button>
                  </form>
                </section>

                <section className="grid gap-4 sm:grid-cols-2">
                  <History title="История заказа" entries={selected.status_history ?? []} labels={orderStatusLabels} />
                  <History title="История оплаты" entries={selected.payment_status_history ?? []} labels={paymentStatusLabels} />
                </section>
              </div>
            )}
          </section>
        </div>
      </div>
    </main>
  )
}

function History({ title, entries, labels }: { title: string; entries: OrderHistoryEntry[]; labels: Record<string, string> }) {
  return (
    <section className="rounded-2xl border bg-card p-5">
      <h3 className="font-semibold">{title}</h3>
      {entries.length === 0 ? <p className="mt-3 text-sm text-muted-foreground">Записей пока нет.</p> : (
        <ol className="mt-3 space-y-3">
          {entries.map((entry, index) => (
            <li className="border-l-2 border-primary/30 pl-3 text-sm" key={`${entry.created_at}-${index}`}>
              <p className="font-medium">{labels[entry.to_status] ?? entry.to_status}</p>
              <p className="mt-1 text-xs text-muted-foreground">{entry.actor?.name ?? 'Система'} · {formattedDate(entry.created_at)}</p>
              {entry.amount_kzt !== null && entry.amount_kzt !== undefined && <p className="mt-1 text-xs">{money.format(entry.amount_kzt)}</p>}
              {entry.note && <p className="mt-1 whitespace-pre-wrap text-xs">{entry.note}</p>}
            </li>
          ))}
        </ol>
      )}
    </section>
  )
}

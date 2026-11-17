import { useEffect, useState, type FormEvent } from 'react'
import { ArrowLeft } from 'lucide-react'
import type { AuthUser } from '@/components/AuthPage'
import { ApiError, apiRequest } from '@/lib/api'

interface CustomerOrder {
  id: number
  number: string
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
  can_request_return: boolean
  return_deadline_at: string | null
  return_requests: ReturnRequestSummary[]
  items: {
    id: number
    product_name: string
    variant_name: string | null
    sku: string
    sale_unit: string | null
    is_returnable: boolean
    remaining_returnable_quantity: number
    quantity: number
    unit_price_kzt: number
    line_total_kzt: number
  }[]
  status_history: { from_status: string | null; to_status: string; created_at: string | null }[]
}

interface ReturnRequestSummary {
  id: number
  status: string
  reason: string
  items: { order_item_id: number; product_name: string; variant_name: string | null; sale_unit: string | null; quantity: number }[]
  status_history: { from_status: string | null; to_status: string; created_at: string | null }[]
  created_at: string
}

interface ReturnRequestResponse {
  data: ReturnRequestSummary
}

const orderStatusLabels: Record<string, string> = {
  new: 'Новый',
  confirmed: 'Подтверждён',
  preparing: 'Собирается',
  ready_for_pickup: 'Готов к самовывозу',
  shipped: 'Отправлен',
  completed: 'Завершён',
  cancelled: 'Отменён',
}
const paymentStatusLabels: Record<string, string> = {
  unpaid: 'Не оплачен',
  paid: 'Оплачен',
  partially_refunded: 'Возвращена часть оплаты',
  refunded: 'Оплата возвращена',
}
const returnStatusLabels: Record<string, string> = {
  submitted: 'Заявка получена',
  reviewing: 'На рассмотрении',
  approved: 'Одобрена',
  rejected: 'Отклонена',
  awaiting_handover: 'Ожидает передачи товара',
  received: 'Товар принят и проверен',
  refund_processed: 'Возврат денег обработан менеджером',
  closed: 'Закрыта',
}
const money = new Intl.NumberFormat('ru-KZ', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 })

function formattedDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-KZ') : '—'
}

export function CustomerOrderPage({ user }: { user: AuthUser | null }) {
  const [order, setOrder] = useState<CustomerOrder | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [returnReason, setReturnReason] = useState('')
  const [returnQuantities, setReturnQuantities] = useState<Record<number, string>>({})
  const [conditionConfirmed, setConditionConfirmed] = useState(false)
  const [returnBusy, setReturnBusy] = useState(false)
  const [returnError, setReturnError] = useState<string | null>(null)
  const [returnMessage, setReturnMessage] = useState<string | null>(null)
  const orderId = window.location.pathname.replace(/\/+$/, '').match(/^\/account\/orders\/(\d+)$/)?.[1]

  const loadOrder = async () => {
    if (!user || !orderId) return
    const response = await apiRequest<{ data: CustomerOrder }>(`/orders/${orderId}`)
    setOrder(response.data)
  }

  useEffect(() => {
    let active = true
    if (!user || !orderId) {
      setLoading(false)
      setError(user ? 'Неверный адрес заказа.' : 'Войдите в аккаунт, чтобы открыть заказ.')
      return () => {
        active = false
      }
    }

    loadOrder()
      .then(() => undefined)
      .catch((requestError: unknown) => {
        if (active) {
          setError(requestError instanceof ApiError && requestError.status === 404
            ? 'Заказ не найден или у вас нет к нему доступа.'
            : requestError instanceof Error ? requestError.message : 'Не удалось загрузить заказ.')
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [user?.id, orderId])

  const submitReturnRequest = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (!order) return

    const items = Object.entries(returnQuantities)
      .map(([orderItemId, quantity]) => ({ order_item_id: Number(orderItemId), quantity: Number(quantity) }))
      .filter((item) => Number.isInteger(item.quantity) && item.quantity > 0)
    if (items.length === 0) {
      setReturnError('Укажите количество целых упаковок для возврата.')
      return
    }

    setReturnBusy(true)
    setReturnError(null)
    setReturnMessage(null)
    try {
      const response = await apiRequest<ReturnRequestResponse>(`/orders/${order.id}/returns`, {
        method: 'POST',
        body: JSON.stringify({
          reason: returnReason.trim(),
          condition_confirmed: conditionConfirmed,
          items,
        }),
      })
      setOrder((current) => current
        ? { ...current, return_requests: [...current.return_requests, response.data] }
        : current)
      setReturnReason('')
      setReturnQuantities({})
      setConditionConfirmed(false)
      setReturnMessage('Заявка отправлена. Менеджер свяжется с вами в WhatsApp.')
      await loadOrder()
    } catch (requestError) {
      setReturnError(requestError instanceof Error ? requestError.message : 'Не удалось отправить заявку на возврат.')
    } finally {
      setReturnBusy(false)
    }
  }

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0">
      <header className="border-b bg-card">
        <div className="mx-auto max-w-4xl px-4 py-4 sm:px-6 lg:px-8">
          <a className="inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground" href="/account"><ArrowLeft className="size-4" /> В аккаунт</a>
        </div>
      </header>
      <div className="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8">
        <p className="text-sm font-medium text-primary">Личный кабинет</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Детали заказа</h1>
        {loading ? <p className="mt-6 text-sm text-muted-foreground" role="status">Загружаем заказ…</p> : error ? (
          <div className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive" role="alert">
            <p>{error}</p>
            {!user && <a className="mt-3 inline-flex underline" href={`/account?next=${encodeURIComponent(window.location.pathname)}`}>Войти в аккаунт</a>}
          </div>
        ) : order && (
          <div className="mt-6 space-y-5">
            <section className="rounded-2xl border bg-card p-5 sm:p-6">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="text-xl font-semibold">{order.number}</h2>
                  <p className="mt-1 text-sm text-muted-foreground">Создан: {formattedDate(order.created_at)}</p>
                </div>
                <div className="text-right text-sm">
                  <p className="font-medium">{orderStatusLabels[order.order_status] ?? order.order_status}</p>
                  <p className="mt-1 text-muted-foreground">{paymentStatusLabels[order.payment_status] ?? order.payment_status}</p>
                </div>
              </div>
              <div className="mt-5 grid gap-4 border-t pt-4 sm:grid-cols-2">
                <div>
                  <h3 className="text-sm font-semibold">Контакт</h3>
                  <p className="mt-2 text-sm">{order.customer_name}</p>
                  <p className="mt-1 text-sm">{order.customer_phone}</p>
                  {order.customer_email && <p className="mt-1 text-sm text-muted-foreground">{order.customer_email}</p>}
                </div>
                <div>
                  <h3 className="text-sm font-semibold">{order.fulfillment_method === 'delivery' ? 'Адрес доставки' : 'Самовывоз'}</h3>
                  {order.fulfillment_method === 'delivery' ? (
                    <p className="mt-2 text-sm">
                      {[order.delivery_city, order.delivery_street, order.delivery_building, order.delivery_apartment && `кв. ${order.delivery_apartment}`].filter(Boolean).join(', ')}
                      {order.delivery_instructions && <span className="mt-1 block text-muted-foreground">{order.delivery_instructions}</span>}
                    </p>
                  ) : <p className="mt-2 text-sm text-muted-foreground">Время и место самовывоза согласуются с менеджером.</p>}
                </div>
              </div>
            </section>

            <section className="rounded-2xl border bg-card p-5 sm:p-6">
              <h2 className="font-semibold">Заявки на возврат</h2>
              <p className="mt-2 text-sm leading-6 text-muted-foreground">
                Заявку можно подать в течение 14 дней после получения заказа. Возвращаются только целые, нераспечатанные упаковки; отрезанный или использованный материал возврату по этой процедуре не подлежит. Менеджер свяжется с вами через WhatsApp; возврат денег отмечается вручную.
              </p>
              {order.return_deadline_at && (
                <p className="mt-2 text-xs text-muted-foreground">Срок подачи заявки до {new Date(order.return_deadline_at).toLocaleDateString('ru-KZ')}.</p>
              )}
              {order.return_requests.length > 0 && (
                <div className="mt-4 space-y-3">
                  {order.return_requests.map((request) => (
                    <article className="rounded-xl border p-4" key={request.id}>
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">Заявка №{request.id}</h3>
                        <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-medium">{returnStatusLabels[request.status] ?? request.status}</span>
                      </div>
                      <p className="mt-2 text-sm text-muted-foreground">{request.reason}</p>
                      <ul className="mt-3 space-y-1 text-sm">
                        {request.items.map((item) => (
                          <li key={item.order_item_id}>{item.product_name}{item.variant_name ? ` — ${item.variant_name}` : ''}: {item.quantity} {item.sale_unit ?? 'упак.'}</li>
                        ))}
                      </ul>
                      <ol className="mt-3 space-y-2 border-t pt-3">
                        {request.status_history.map((entry, index) => (
                          <li className="text-xs text-muted-foreground" key={`${entry.created_at}-${index}`}>
                            {returnStatusLabels[entry.to_status] ?? entry.to_status} · {formattedDate(entry.created_at)}
                          </li>
                        ))}
                      </ol>
                    </article>
                  ))}
                </div>
              )}
              {order.can_request_return && (
                <form className="mt-5 space-y-4 border-t pt-5" onSubmit={(event) => void submitReturnRequest(event)}>
                  <h3 className="font-medium">Оформить заявку</h3>
                  <div className="space-y-3">
                    {order.items.filter((item) => item.remaining_returnable_quantity > 0).map((item) => (
                      <label className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-3 text-sm" key={item.id}>
                        <span className="min-w-0 flex-1">
                          <span className="block font-medium">{item.product_name}{item.variant_name ? ` — ${item.variant_name}` : ''}</span>
                          <span className="text-xs text-muted-foreground">Целых упаковок доступно: {item.remaining_returnable_quantity} {item.sale_unit ?? ''}</span>
                        </span>
                        <input
                          aria-label={`Количество упаковок для возврата: ${item.product_name}`}
                          className="h-10 w-24 rounded-lg border bg-background px-2 text-center"
                          max={item.remaining_returnable_quantity}
                          min="0"
                          onChange={(event) => setReturnQuantities((current) => ({ ...current, [item.id]: event.target.value }))}
                          placeholder="0"
                          type="number"
                          value={returnQuantities[item.id] ?? ''}
                        />
                      </label>
                    ))}
                  </div>
                  <label className="block text-sm font-medium">Причина возврата
                    <textarea className="mt-2 min-h-24 w-full rounded-xl border bg-background px-3 py-2 font-normal" maxLength={2000} onChange={(event) => setReturnReason(event.target.value)} required value={returnReason} />
                  </label>
                  <label className="flex items-start gap-3 text-sm leading-6">
                    <input checked={conditionConfirmed} className="mt-1 accent-primary" onChange={(event) => setConditionConfirmed(event.target.checked)} required type="checkbox" />
                    <span>Подтверждаю: товар не использован, оригинальная упаковка целая и не вскрыта; материал не отрезался.</span>
                  </label>
                  {(returnError || returnMessage) && (
                    <p className={`text-sm ${returnError ? 'text-destructive' : 'text-emerald-700'}`} role={returnError ? 'alert' : 'status'}>{returnError ?? returnMessage}</p>
                  )}
                  <button className="h-11 w-full rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={returnBusy} type="submit">
                    {returnBusy ? 'Отправляем заявку…' : 'Отправить заявку на возврат'}
                  </button>
                </form>
              )}
              {!order.can_request_return && order.return_requests.length === 0 && (
                <p className="mt-4 text-sm text-muted-foreground">Для этого заказа сейчас нет доступных к возврату целых упаковок либо срок подачи заявки истёк.</p>
              )}
              {(returnError || returnMessage) && !order.can_request_return && (
                <p className={`mt-3 text-sm ${returnError ? 'text-destructive' : 'text-emerald-700'}`} role={returnError ? 'alert' : 'status'}>{returnError ?? returnMessage}</p>
              )}
            </section>

            <section className="rounded-2xl border bg-card p-5 sm:p-6">
              <h2 className="font-semibold">Товары</h2>
              <div className="mt-3 divide-y rounded-xl border">
                {order.items.map((item) => (
                  <div className="flex flex-wrap items-start justify-between gap-3 p-3 text-sm" key={item.id}>
                    <div><p className="font-medium">{item.product_name}</p><p className="mt-1 text-xs text-muted-foreground">{item.variant_name} · {item.sku} · {item.quantity} шт. × {money.format(item.unit_price_kzt)}</p></div>
                    <span className="font-medium">{money.format(item.line_total_kzt)}</span>
                  </div>
                ))}
              </div>
              <div className="mt-4 space-y-1 text-right text-sm">
                <p>Товары: {money.format(order.subtotal_kzt)}</p>
                <p>Доставка: {order.delivery_fee_kzt === null ? 'согласуется с менеджером' : money.format(order.delivery_fee_kzt)}</p>
                <p className="font-semibold">Итого: {order.total_kzt === null ? 'будет сообщено менеджером' : money.format(order.total_kzt)}</p>
              </div>
              <p className="mt-4 text-xs leading-5 text-muted-foreground">Онлайн-оплата не подключена. Менеджер сообщит дальнейшие шаги по телефону/WhatsApp.</p>
            </section>

            <section className="rounded-2xl border bg-card p-5 sm:p-6">
              <h2 className="font-semibold">История заказа</h2>
              <ol className="mt-4 space-y-4">
                {order.status_history.map((entry, index) => (
                  <li className="border-l-2 border-primary/30 pl-4" key={`${entry.created_at}-${index}`}>
                    <p className="text-sm font-medium">{orderStatusLabels[entry.to_status] ?? entry.to_status}</p>
                    <p className="mt-1 text-xs text-muted-foreground">{formattedDate(entry.created_at)}</p>
                  </li>
                ))}
              </ol>
              {order.status_history.length === 0 && <p className="mt-3 text-sm text-muted-foreground">История пока не сформирована.</p>}
            </section>
          </div>
        )}
      </div>
    </main>
  )
}

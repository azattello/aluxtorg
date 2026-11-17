import { useEffect, useState, type FormEvent } from 'react'
import { ArrowLeft, ArrowRight, ShieldCheck } from 'lucide-react'
import { ApiError, apiRequest, prepareSanctumSession } from '@/lib/api'
import { BrandMark } from '@/components/BrandMark'
import {
  clearCart,
  formatKzt,
  loadCurrentCart,
  type CurrentCartEntry,
} from '@/lib/cart'
import type { AuthUser } from '@/components/AuthPage'

interface CreatedOrder {
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
  items: { product_name: string; variant_name: string; quantity: number; line_total_kzt: number }[]
}

interface OrderResponse {
  data: CreatedOrder
}

const pendingRequestKey = 'ecommerce.checkout.pending'
const confirmationKey = 'ecommerce.checkout.confirmation'

function getIdempotencyKey(payload: string): string {
  let saved: { payload: string; key: string } | null = null
  try {
    const serialized = sessionStorage.getItem(pendingRequestKey)
    if (serialized) {
      const parsed: unknown = JSON.parse(serialized)
      if (
        typeof parsed === 'object'
        && parsed !== null
        && 'payload' in parsed
        && typeof parsed.payload === 'string'
        && 'key' in parsed
        && typeof parsed.key === 'string'
      ) {
        saved = { payload: parsed.payload, key: parsed.key }
      }
    }
  } catch {
    throw new Error('Не удалось сохранить состояние оформления в этой вкладке.')
  }

  if (saved?.payload === payload) return saved.key

  const key = crypto.randomUUID()
  try {
    sessionStorage.setItem(pendingRequestKey, JSON.stringify({ payload, key }))
  } catch {
    throw new Error('Не удалось сохранить защитный ключ заказа. Разрешите хранилище вкладки и повторите.')
  }

  return key
}

export function CheckoutPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [lines, setLines] = useState<CurrentCartEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [fulfillmentMethod, setFulfillmentMethod] = useState<'delivery' | 'pickup'>('delivery')
  const [name, setName] = useState(user?.name ?? '')
  const [phone, setPhone] = useState(user?.phone ?? '')
  const [email, setEmail] = useState(user?.email ?? '')
  const [statusEmailOptIn, setStatusEmailOptIn] = useState(false)

  useEffect(() => {
    document.title = 'Оформление заказа'
    if (sessionError) setError(sessionError)
  }, [sessionError])

  useEffect(() => {
    void loadCurrentCart()
      .then(setLines)
      .catch((requestError: unknown) => setError(
        requestError instanceof ApiError || requestError instanceof Error
          ? requestError.message
          : 'Не удалось загрузить корзину.',
      ))
      .finally(() => setLoading(false))
  }, [])

  useEffect(() => {
    if (!user) return
    setName((current) => current || user.name)
    setPhone((current) => current || user.phone || '')
    setEmail((current) => current || user.email || '')
  }, [user])

  const unavailable = lines.some((line) => !line.variant?.available)
  const subtotal = lines.reduce(
    (sum, line) => sum + (line.variant?.price_kzt ?? 0) * line.entry.quantity,
    0,
  )

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    if (lines.length === 0 || unavailable) {
      setError('Проверьте товары в корзине: недоступные позиции нельзя заказать.')
      return
    }

    const formData = new FormData(event.currentTarget)
    const body = {
      fulfillment_method: fulfillmentMethod,
      customer_name: name.trim(),
      customer_phone: phone.trim(),
      customer_email: email.trim() || null,
      customer_notifications_opted_in: Boolean(email.trim()) && statusEmailOptIn,
      delivery_city: fulfillmentMethod === 'delivery' ? String(formData.get('delivery_city') ?? '').trim() : null,
      delivery_street: fulfillmentMethod === 'delivery' ? String(formData.get('delivery_street') ?? '').trim() : null,
      delivery_building: fulfillmentMethod === 'delivery' ? String(formData.get('delivery_building') ?? '').trim() : null,
      delivery_apartment: fulfillmentMethod === 'delivery' ? String(formData.get('delivery_apartment') ?? '').trim() || null : null,
      delivery_instructions: String(formData.get('delivery_instructions') ?? '').trim() || null,
      items: lines.map(({ entry }) => ({ variant_id: entry.variantId, quantity: entry.quantity })),
    }

    setBusy(true)
    setError(null)
    try {
      const payload = JSON.stringify(body)
      const idempotencyKey = getIdempotencyKey(payload)
      await prepareSanctumSession()
      const response = await apiRequest<OrderResponse>('/orders', {
        method: 'POST',
        headers: { 'Idempotency-Key': idempotencyKey },
        body: payload,
      })

      const order = response.data
      sessionStorage.setItem(confirmationKey, JSON.stringify({
        number: order.number,
        fulfillment_method: order.fulfillment_method,
        subtotal_kzt: order.subtotal_kzt,
        delivery_fee_kzt: order.delivery_fee_kzt,
        total_kzt: order.total_kzt,
      }))
      clearCart()
      sessionStorage.removeItem(pendingRequestKey)
      window.location.href = `/order-confirmation/${encodeURIComponent(order.number)}`
    } catch (requestError) {
      setError(requestError instanceof ApiError || requestError instanceof Error
        ? requestError.message
        : 'Не удалось оформить заказ. Проверьте данные и повторите попытку.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <main className="min-h-screen bg-background px-4 py-8 pb-24 text-foreground sm:px-6 md:pb-8 lg:px-8">
      <div className="mx-auto max-w-5xl">
        <header className="flex flex-wrap items-center justify-between gap-4 border-b pb-5">
          <a className="inline-flex items-center gap-2 text-sm font-semibold" href="/" aria-label="Aluxtorg — на главную">
            <BrandMark className="h-8 w-[2.6rem]" />
            <span>Aluxtorg</span>
          </a>
          <a className="text-sm text-muted-foreground hover:text-foreground" href="/account">Личный кабинет</a>
        </header>
        <a className="mt-7 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground" href="/cart">
          <ArrowLeft size={15} /> Вернуться в корзину
        </a>
        <h1 className="mt-5 text-3xl font-semibold tracking-tight">Оформление заказа</h1>
        <p className="mt-2 text-sm text-muted-foreground">Войдите или создайте аккаунт, чтобы оформить заказ. Онлайн-оплата не требуется.</p>

        {error && <p className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive" role="alert">{error}</p>}

        {!user ? (
          <section className="mt-8 rounded-2xl border bg-card p-8 text-center">
            <h2 className="font-semibold">Войдите, чтобы продолжить</h2>
            <p className="mt-2 text-sm text-muted-foreground">Заказ будет привязан к вашему аккаунту и появится в истории заказов.</p>
            <a className="mt-5 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account?next=%2Fcheckout">Войти или создать аккаунт</a>
          </section>
        ) : loading ? (
          <p className="py-16 text-center text-sm text-muted-foreground" role="status">Проверяем корзину…</p>
        ) : lines.length === 0 ? (
          <section className="mt-8 rounded-2xl border bg-card p-8 text-center">
            <h2 className="font-semibold">В корзине нет товаров</h2>
            <a className="mt-4 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/">Вернуться в каталог</a>
          </section>
        ) : (
          <form className="mt-8 grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_320px]" onSubmit={(event) => void submit(event)}>
            <section className="space-y-7">
              <fieldset className="space-y-3">
                <legend className="mb-3 text-lg font-semibold">Получение заказа</legend>
                <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-card p-4">
                  <input checked={fulfillmentMethod === 'delivery'} className="mt-1 accent-primary" name="fulfillment_method" onChange={() => setFulfillmentMethod('delivery')} type="radio" value="delivery" />
                  <span>
                    <span className="block font-medium">Доставка по Казахстану</span>
                    <span className="mt-1 block text-sm leading-6 text-muted-foreground">Менеджер согласует срок и стоимость с вами в WhatsApp.</span>
                  </span>
                </label>
                <label className="flex cursor-pointer items-start gap-3 rounded-xl border bg-card p-4">
                  <input checked={fulfillmentMethod === 'pickup'} className="mt-1 accent-primary" name="fulfillment_method" onChange={() => setFulfillmentMethod('pickup')} type="radio" value="pickup" />
                  <span>
                    <span className="block font-medium">Самовывоз</span>
                    <span className="mt-1 block text-sm leading-6 text-muted-foreground">Фиксированной точки нет — детали согласует менеджер в WhatsApp.</span>
                  </span>
                </label>
              </fieldset>

              <section className="space-y-4">
                <h2 className="text-lg font-semibold">Контактные данные</h2>
                <label className="block text-sm font-medium">Имя
                  <input autoComplete="name" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={160} onChange={(event) => setName(event.target.value)} required value={name} />
                </label>
                <label className="block text-sm font-medium">Телефон
                  <input autoComplete="tel" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={32} onChange={(event) => setPhone(event.target.value)} required type="tel" value={phone} />
                </label>
                <label className="block text-sm font-medium">Электронная почта <span className="font-normal text-muted-foreground">(необязательно)</span>
                  <input autoComplete="email" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={254} onChange={(event) => setEmail(event.target.value)} type="email" value={email} />
                </label>
                {email.trim() && <p className="-mt-2 text-xs leading-5 text-muted-foreground">На этот адрес придёт письмо с номером заказа.</p>}
                <label className="flex items-start gap-3 text-sm leading-6">
                  <input
                    checked={statusEmailOptIn}
                    className="mt-1 accent-primary"
                    disabled={!email.trim()}
                    onChange={(event) => setStatusEmailOptIn(event.target.checked)}
                    type="checkbox"
                  />
                  <span>Согласен получать письма об изменении статуса этого заказа. Это не рекламная рассылка; отписаться можно по ссылке в письмах.</span>
                </label>
              </section>

              {fulfillmentMethod === 'delivery' && (
                <section className="space-y-4">
                  <h2 className="text-lg font-semibold">Адрес доставки</h2>
                  <label className="block text-sm font-medium">Город
                    <input autoComplete="address-level2" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={120} name="delivery_city" required />
                  </label>
                  <label className="block text-sm font-medium">Улица
                    <input autoComplete="address-line1" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={180} name="delivery_street" required />
                  </label>
                  <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block text-sm font-medium">Дом
                      <input className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={80} name="delivery_building" required />
                    </label>
                    <label className="block text-sm font-medium">Квартира <span className="font-normal text-muted-foreground">(необязательно)</span>
                      <input autoComplete="address-line2" className="mt-2 h-11 w-full rounded-xl border bg-card px-3 font-normal" maxLength={80} name="delivery_apartment" />
                    </label>
                  </div>
                </section>
              )}

              <label className="block text-sm font-medium">Комментарий для менеджера <span className="font-normal text-muted-foreground">(необязательно)</span>
                <textarea className="mt-2 min-h-24 w-full rounded-xl border bg-card px-3 py-2 font-normal" maxLength={2000} name="delivery_instructions" />
              </label>
            </section>

            <aside className="rounded-2xl border bg-card p-5">
              <h2 className="text-lg font-semibold">Ваш заказ</h2>
              <div className="mt-4 space-y-3 border-t py-4">
                {lines.map(({ entry, product, variant }) => (
                  <div className="flex justify-between gap-3 text-sm" key={entry.variantId}>
                    <span className="min-w-0">{product?.name ?? entry.productName} × {entry.quantity}</span>
                    <span className="shrink-0 font-medium tabular-nums">{variant ? formatKzt(variant.price_kzt * entry.quantity) : '—'}</span>
                  </div>
                ))}
              </div>
              <div className="flex justify-between gap-4 border-t pt-4 text-sm font-semibold">
                <span>Товары</span>
                <span className="tabular-nums">{formatKzt(subtotal)}</span>
              </div>
              <p className="mt-3 text-xs leading-5 text-muted-foreground">
                {fulfillmentMethod === 'delivery'
                  ? 'Стоимость доставки не включена. Менеджер согласует её с вами в WhatsApp.'
                  : 'Детали самовывоза согласуются с менеджером в WhatsApp.'}
              </p>
              {unavailable && (
                <p className="mt-3 text-sm text-destructive" role="status">
                  Один или несколько товаров больше недоступны. Вернитесь в корзину, чтобы удалить их.
                </p>
              )}
              <p className="mt-3 flex items-start gap-2 text-xs leading-5 text-muted-foreground">
                <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                Оплата не списывается. Заказ будет создан со статусом «Новый», без онлайн-оплаты.
              </p>
              <button
                className="mt-5 flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground disabled:cursor-not-allowed disabled:opacity-50"
                disabled={busy || unavailable || lines.length === 0}
                type="submit"
              >
                {busy ? 'Отправляем заказ…' : 'Подтвердить заказ'} {!busy && <ArrowRight size={16} />}
              </button>
            </aside>
          </form>
        )}
      </div>
    </main>
  )
}

export function OrderConfirmationPage() {
  const [confirmation, setConfirmation] = useState<{
    number: string
    fulfillment_method: 'delivery' | 'pickup'
    subtotal_kzt: number
    delivery_fee_kzt: number | null
    total_kzt: number | null
  } | null>(null)
  const orderNumber = decodeURIComponent(window.location.pathname.split('/').pop() ?? '')

  useEffect(() => {
    document.title = 'Заказ оформлен'
    try {
      const serialized = sessionStorage.getItem(confirmationKey)
      if (!serialized) return
      const saved = JSON.parse(serialized) as typeof confirmation
      if (saved?.number === orderNumber) setConfirmation(saved)
    } catch {
      setConfirmation(null)
    }
  }, [orderNumber])

  return (
    <main className="flex min-h-screen items-center justify-center bg-background px-4 py-10 pb-24 text-foreground md:pb-10">
      <section className="w-full max-w-lg rounded-3xl border bg-card p-7 text-center shadow-sm sm:p-10">
        <div className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-accent text-accent-foreground">
          <ShieldCheck size={26} />
        </div>
        {confirmation ? (
          <>
            <p className="mt-6 text-sm font-medium text-primary">Заказ создан</p>
            <h1 className="mt-2 text-3xl font-semibold">Спасибо за заказ!</h1>
            <p className="mt-3 text-sm text-muted-foreground">Номер заказа: <strong className="text-foreground">{confirmation.number}</strong></p>
            <p className="mt-4 text-sm leading-6 text-muted-foreground">
              {confirmation.fulfillment_method === 'delivery'
                ? 'Менеджер свяжется с вами в WhatsApp, чтобы согласовать срок и стоимость доставки.'
                : 'Менеджер свяжется с вами в WhatsApp, чтобы согласовать детали самовывоза.'}
              {' '}Оплата онлайн не производилась.
            </p>
            <div className="mt-6 rounded-xl bg-muted/60 p-4 text-sm">
              <div className="flex justify-between gap-4"><span>Товары</span><strong>{formatKzt(confirmation.subtotal_kzt)}</strong></div>
              {confirmation.delivery_fee_kzt === null ? (
                <p className="mt-2 text-left text-xs text-muted-foreground">Стоимость доставки будет согласована отдельно.</p>
              ) : (
                <div className="mt-2 flex justify-between gap-4"><span>Итого</span><strong>{formatKzt(confirmation.total_kzt ?? confirmation.subtotal_kzt)}</strong></div>
              )}
            </div>
          </>
        ) : (
          <>
            <h1 className="mt-6 text-2xl font-semibold">Информация о заказе недоступна</h1>
            <p className="mt-3 text-sm leading-6 text-muted-foreground">Откройте страницу из подтверждения заказа или проверьте историю в личном кабинете.</p>
          </>
        )}
        <div className="mt-7 flex flex-wrap justify-center gap-3">
          <a className="inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/">Вернуться в каталог <ArrowRight size={15} /></a>
          <a className="inline-flex items-center rounded-xl border px-4 py-2.5 text-sm font-medium" href="/account">Личный кабинет</a>
        </div>
      </section>
    </main>
  )
}

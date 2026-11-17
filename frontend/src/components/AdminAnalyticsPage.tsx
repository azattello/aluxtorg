import { useEffect, useState, type FormEvent } from 'react'
import { RefreshCw, Settings2 } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'

interface DailyOrderStat {
  date: string
  orders_count: number
  value_kzt: number
}

interface DailyViewStat {
  date: string
  views: number
}

interface AnalyticsResponse {
  data: {
    from: string
    to: string
    summary: {
      orders_count: number
      order_value_kzt: number
      paid_orders_count: number
      buyers_count: number
      page_views: number
    }
    orders_by_day: DailyOrderStat[]
    orders_by_status: { order_status: string; orders_count: number }[]
    orders_by_city: { city: string; orders_count: number }[]
    views_by_day: DailyViewStat[]
    views_by_type: { page_type: string; views: number }[]
    popular_pages: { page_type: string; resource_key: string; title: string; views: number }[]
    recent_orders: {
      id: number
      number: string
      customer_name: string
      customer_phone: string
      delivery_city: string | null
      fulfillment_method: 'delivery' | 'pickup'
      order_status: string
      payment_status: string
      subtotal_kzt: number
      total_kzt: number | null
      created_at: string
    }[]
  }
}

interface BarPoint {
  label: string
  value: number
  detail: string
}

const money = new Intl.NumberFormat('ru-KZ', { style: 'currency', currency: 'KZT', maximumFractionDigits: 0 })
const number = new Intl.NumberFormat('ru-KZ')

const statusLabels: Record<string, string> = {
  new: 'Новый',
  confirmed: 'Подтверждён',
  preparing: 'Собирается',
  ready_for_pickup: 'Готов к самовывозу',
  shipped: 'Отправлен',
  completed: 'Завершён',
  cancelled: 'Отменён',
  unpaid: 'Не оплачен',
  paid: 'Оплачен',
  partially_refunded: 'Возвращена часть оплаты',
  refunded: 'Возвращена оплата',
}

const pageTypeLabels: Record<string, string> = {
  home: 'Главная',
  category: 'Категория',
  product: 'Карточка товара',
}

function localDateOffset(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() + days)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function Chart({ title, points }: { title: string; points: BarPoint[] }) {
  const maximum = Math.max(1, ...points.map((point) => point.value))
  return (
    <section className="rounded-2xl border bg-card p-4 sm:p-5">
      <h2 className="font-semibold">{title}</h2>
      {points.length === 0 ? (
        <p className="mt-6 text-sm text-muted-foreground">За выбранный период данных пока нет.</p>
      ) : (
        <div aria-label={title} className="mt-5 overflow-x-auto" role="img">
          <div className="flex h-44 min-w-full items-end gap-1">
            {points.map((point) => (
              <div className="flex h-full min-w-3 flex-1 flex-col items-center justify-end gap-2" key={point.label} title={`${point.label}: ${point.detail}`}>
                <div
                  aria-hidden="true"
                  className="w-full max-w-8 rounded-t bg-primary/80"
                  style={{ height: `${Math.max(3, (point.value / maximum) * 100)}%` }}
                />
                <span className="text-[10px] text-muted-foreground">{point.label}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </section>
  )
}

export function AdminAnalyticsPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [from, setFrom] = useState(localDateOffset(-29))
  const [to, setTo] = useState(localDateOffset(0))
  const [range, setRange] = useState({ from: localDateOffset(-29), to: localDateOffset(0) })
  const [analytics, setAnalytics] = useState<AnalyticsResponse['data'] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const canViewAnalytics = user !== null && ['administrator', 'super_administrator'].includes(user.role)

  useEffect(() => {
    if (!canViewAnalytics) {
      setLoading(false)
      return
    }

    let active = true
    setLoading(true)
    const params = new URLSearchParams(range)
    void apiRequest<AnalyticsResponse>(`/admin/analytics?${params.toString()}`)
      .then((response) => {
        if (active) setAnalytics(response.data)
      })
      .catch((requestError: unknown) => {
        if (active) setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить статистику.')
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [canViewAnalytics, range])

  const submitRange = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setError(null)
    setRange({ from, to })
  }

  if (!canViewAnalytics) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">
          {sessionError ?? (user ? 'Статистику магазина может просматривать администратор.' : 'Войдите под аккаунтом администратора.')}
        </p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  const summary = analytics?.summary
  const orderPoints = (analytics?.orders_by_day ?? []).map((item) => ({
    label: new Date(`${item.date}T00:00:00`).toLocaleDateString('ru-KZ', { day: '2-digit', month: '2-digit' }),
    value: item.orders_count,
    detail: `${number.format(item.orders_count)} заказов`,
  }))
  const orderValuePoints = (analytics?.orders_by_day ?? []).map((item) => ({
    label: new Date(`${item.date}T00:00:00`).toLocaleDateString('ru-KZ', { day: '2-digit', month: '2-digit' }),
    value: item.value_kzt,
    detail: money.format(item.value_kzt),
  }))
  const viewPoints = (analytics?.views_by_day ?? []).map((item) => ({
    label: new Date(`${item.date}T00:00:00`).toLocaleDateString('ru-KZ', { day: '2-digit', month: '2-digit' }),
    value: item.views,
    detail: `${number.format(item.views)} просмотров`,
  }))

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <header className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Aluxtorg</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Статистика магазина</h1>
            <p className="mt-2 text-sm text-muted-foreground">Заказы, покупатели, города доставки и просмотры каталога.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <a className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" href="/admin/settings">
              <Settings2 aria-hidden="true" className="size-4" /> Контакты
            </a>
            <button className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted disabled:opacity-50" disabled={loading} onClick={() => setRange({ ...range })} type="button">
              <RefreshCw aria-hidden="true" className={`size-4 ${loading ? 'animate-spin' : ''}`} /> Обновить
            </button>
          </div>
        </header>

        {(error || sessionError) && (
          <div className="mb-5 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive" role="alert">
            {error ?? sessionError}
          </div>
        )}

        <form className="mb-5 flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4" onSubmit={submitRange}>
          <label className="grid gap-1 text-sm">
            <span className="font-medium">С</span>
            <input className="h-10 rounded-lg border bg-background px-3" max={to} onChange={(event) => setFrom(event.target.value)} required type="date" value={from} />
          </label>
          <label className="grid gap-1 text-sm">
            <span className="font-medium">По</span>
            <input className="h-10 rounded-lg border bg-background px-3" min={from} max={localDateOffset(0)} onChange={(event) => setTo(event.target.value)} required type="date" value={to} />
          </label>
          <button className="h-10 rounded-lg bg-primary px-4 text-sm font-medium !text-white hover:opacity-90" type="submit">Показать</button>
          {loading && <span className="text-sm text-muted-foreground" role="status">Обновляем статистику…</span>}
        </form>

        <section aria-label="Основные показатели" className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          {[
            ['Заказов', number.format(summary?.orders_count ?? 0)],
            ['Сумма заказов', money.format(summary?.order_value_kzt ?? 0)],
            ['Уникальных покупателей', number.format(summary?.buyers_count ?? 0)],
            ['Оплачено заказов', number.format(summary?.paid_orders_count ?? 0)],
            ['Просмотров страниц', number.format(summary?.page_views ?? 0)],
          ].map(([label, value]) => (
            <article className="rounded-2xl border bg-card p-4" key={label}>
              <h2 className="text-sm text-muted-foreground">{label}</h2>
              <p className="mt-2 text-2xl font-semibold tabular-nums">{value}</p>
            </article>
          ))}
        </section>

        <div className="grid gap-5 xl:grid-cols-2">
          <Chart title="Заказы по дням" points={orderPoints} />
          <Chart title="Сумма заказов по дням" points={orderValuePoints} />
          <Chart title="Просмотры страниц по дням" points={viewPoints} />
        </div>

        <div className="mt-5 grid gap-5 lg:grid-cols-3">
          <section className="rounded-2xl border bg-card p-4 sm:p-5">
            <h2 className="font-semibold">Заказы по статусам</h2>
            <ul className="mt-4 space-y-3">
              {(analytics?.orders_by_status ?? []).map((item) => (
                <li className="flex items-center justify-between gap-3 text-sm" key={item.order_status}>
                  <span>{statusLabels[item.order_status] ?? item.order_status}</span>
                  <strong className="tabular-nums">{number.format(item.orders_count)}</strong>
                </li>
              ))}
              {!analytics?.orders_by_status.length && <li className="text-sm text-muted-foreground">Данных пока нет.</li>}
            </ul>
          </section>

          <section className="rounded-2xl border bg-card p-4 sm:p-5">
            <h2 className="font-semibold">Заказы по городам</h2>
            <ul className="mt-4 space-y-3">
              {(analytics?.orders_by_city ?? []).map((item) => (
                <li className="flex items-center justify-between gap-3 text-sm" key={item.city}>
                  <span className="truncate">{item.city}</span>
                  <strong className="tabular-nums">{number.format(item.orders_count)}</strong>
                </li>
              ))}
              {!analytics?.orders_by_city.length && <li className="text-sm text-muted-foreground">Данных пока нет.</li>}
            </ul>
          </section>

          <section className="rounded-2xl border bg-card p-4 sm:p-5">
            <h2 className="font-semibold">Просмотры по типу страницы</h2>
            <ul className="mt-4 space-y-3">
              {(analytics?.views_by_type ?? []).map((item) => (
                <li className="flex items-center justify-between gap-3 text-sm" key={item.page_type}>
                  <span>{pageTypeLabels[item.page_type] ?? item.page_type}</span>
                  <strong className="tabular-nums">{number.format(item.views)}</strong>
                </li>
              ))}
              {!analytics?.views_by_type.length && <li className="text-sm text-muted-foreground">Данных пока нет.</li>}
            </ul>
          </section>
        </div>

        <section className="mt-5 rounded-2xl border bg-card p-4 sm:p-5">
          <h2 className="font-semibold">Популярные страницы</h2>
          <div className="mt-4 divide-y">
            {(analytics?.popular_pages ?? []).map((item) => (
              <div className="flex items-center justify-between gap-3 py-3 text-sm" key={`${item.page_type}-${item.resource_key}`}>
                <span className="min-w-0 truncate">{item.title} <span className="text-muted-foreground">· {pageTypeLabels[item.page_type] ?? item.page_type}</span></span>
                <strong className="shrink-0 tabular-nums">{number.format(item.views)}</strong>
              </div>
            ))}
            {!analytics?.popular_pages.length && <p className="py-3 text-sm text-muted-foreground">Данных пока нет.</p>}
          </div>
        </section>

        <section className="mt-5 rounded-2xl border bg-card p-4 sm:p-5">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 className="font-semibold">Заказы за период</h2>
              <p className="mt-1 text-xs text-muted-foreground">Показаны последние 100 заказов с именем, телефоном и городом.</p>
            </div>
            <span className="text-sm text-muted-foreground">{analytics?.from} — {analytics?.to}</span>
          </div>
          <div className="mt-4 overflow-x-auto">
            <table className="w-full min-w-[900px] text-left text-sm">
              <thead className="border-b text-muted-foreground">
                <tr>
                  <th className="px-3 py-3 font-medium">Заказ</th>
                  <th className="px-3 py-3 font-medium">Покупатель</th>
                  <th className="px-3 py-3 font-medium">Город</th>
                  <th className="px-3 py-3 font-medium">Статус</th>
                  <th className="px-3 py-3 font-medium">Оплата</th>
                  <th className="px-3 py-3 text-right font-medium">Сумма</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {(analytics?.recent_orders ?? []).map((order) => (
                  <tr key={order.id}>
                    <td className="px-3 py-3">
                      <a className="font-medium text-primary hover:underline" href={`/admin/orders?search=${encodeURIComponent(order.number)}`}>{order.number}</a>
                      <div className="mt-1 text-xs text-muted-foreground">{new Date(order.created_at).toLocaleString('ru-KZ')}</div>
                    </td>
                    <td className="px-3 py-3">
                      <div>{order.customer_name}</div>
                      <a className="text-xs text-muted-foreground hover:text-foreground" href={`tel:${order.customer_phone}`}>{order.customer_phone}</a>
                    </td>
                    <td className="px-3 py-3">{order.delivery_city ?? (order.fulfillment_method === 'pickup' ? 'Самовывоз' : '—')}</td>
                    <td className="px-3 py-3">{statusLabels[order.order_status] ?? order.order_status}</td>
                    <td className="px-3 py-3">{statusLabels[order.payment_status] ?? order.payment_status}</td>
                    <td className="px-3 py-3 text-right font-medium tabular-nums">{money.format(order.total_kzt ?? order.subtotal_kzt)}</td>
                  </tr>
                ))}
                {!analytics?.recent_orders.length && (
                  <tr><td className="px-3 py-8 text-center text-muted-foreground" colSpan={6}>Заказов за выбранный период нет.</td></tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </main>
  )
}

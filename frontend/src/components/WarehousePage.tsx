import { useEffect, useState, type FormEvent } from 'react'
import { RefreshCw } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'

interface Page<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    total: number
    low_stock_threshold?: number
    low_stock_count?: number
  }
}

interface VariantOption {
  id: number
  sku: string
  name: string
  product_name: string
}

interface StockBalanceRow {
  id: number
  warehouse_name: string
  variant_id: number
  sku: string
  variant_name: string
  product_name: string
  on_hand: number
  reserved: number
  available: number
}

interface Movement {
  id: number
  type: string
  quantity_delta: number
  note: string | null
  sku: string
  product_name: string
  warehouse_name: string
  actor_name: string | null
  created_at: string | null
}

type WarehouseTab = 'balances' | 'movements'
const tabLabels: Record<WarehouseTab, string> = { balances: 'Остатки', movements: 'Движения' }
const movementLabels: Record<string, string> = {
  receipt: 'Поступление',
  dispatch: 'Отгрузка',
  adjustment: 'Корректировка',
  correction: 'Исправление',
}
const quantityFormat = new Intl.NumberFormat('ru-KZ', { maximumFractionDigits: 0 })

function formattedDate(value: string | null): string {
  return value ? new Date(value).toLocaleString('ru-KZ') : '—'
}

export function WarehousePage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [tab, setTab] = useState<WarehouseTab>('balances')
  const [balances, setBalances] = useState<StockBalanceRow[]>([])
  const [selectedBalance, setSelectedBalance] = useState<StockBalanceRow | null>(null)
  const [movements, setMovements] = useState<Movement[]>([])
  const [variants, setVariants] = useState<VariantOption[]>([])
  const [selectedVariantId, setSelectedVariantId] = useState('')
  const [selectedVariantSearch, setSelectedVariantSearch] = useState('')
  const [inventorySearch, setInventorySearch] = useState('')
  const [movementType, setMovementType] = useState('')
  const [lowStockOnly, setLowStockOnly] = useState(false)
  const [lowStockCount, setLowStockCount] = useState(0)
  const [lowStockThreshold, setLowStockThreshold] = useState(2)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const canManageWarehouse = user && ['warehouse_operator', 'manager', 'administrator', 'super_administrator'].includes(user.role)

  const loadInventory = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams({ page: String(page), per_page: '50' })
      if (inventorySearch.trim()) params.set('search', inventorySearch.trim())
      if (tab === 'balances') {
        if (lowStockOnly) params.set('low_stock', '1')
        const response = await apiRequest<Page<StockBalanceRow>>(`/admin/warehouse/balances?${params.toString()}`)
        setBalances(response.data)
        setLastPage(response.meta.last_page)
        setLowStockCount(response.meta.low_stock_count ?? 0)
        setLowStockThreshold(response.meta.low_stock_threshold ?? 2)
      } else {
        if (movementType) params.set('type', movementType)
        const response = await apiRequest<Page<Movement>>(`/admin/warehouse/movements?${params.toString()}`)
        setMovements(response.data)
        setLastPage(response.meta.last_page)
      }
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить складские данные.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManageWarehouse) void loadInventory()
  }, [canManageWarehouse, tab, page, inventorySearch, movementType, lowStockOnly])

  const searchVariants = async (event?: FormEvent<HTMLFormElement>) => {
    event?.preventDefault()
    setError(null)
    try {
      const params = new URLSearchParams({ per_page: '50' })
      if (selectedVariantSearch.trim()) params.set('search', selectedVariantSearch.trim())
      const response = await apiRequest<Page<VariantOption>>(`/admin/warehouse/variants?${params.toString()}`)
      setVariants(response.data)
      if (!response.data.some((variant) => String(variant.id) === selectedVariantId)) {
        setSelectedVariantId(response.data.length ? String(response.data[0].id) : '')
      }
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось найти варианты товара.')
    }
  }

  useEffect(() => {
    if (canManageWarehouse) void searchVariants()
  }, [canManageWarehouse])

  useEffect(() => {
    let active = true
    if (!selectedVariantId) {
      setSelectedBalance(null)
      return () => {
        active = false
      }
    }

    apiRequest<Page<StockBalanceRow>>(`/admin/warehouse/balances?variant_id=${selectedVariantId}&per_page=1`)
      .then((response) => {
        if (active) setSelectedBalance(response.data[0] ?? null)
      })
      .catch((requestError: unknown) => {
        if (active) setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить остаток выбранного товара.')
      })
    return () => {
      active = false
    }
  }, [selectedVariantId, balances])

  const submitStockOperation = async (event: FormEvent<HTMLFormElement>, kind: 'receipts' | 'reconciliations') => {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    if (!selectedVariantId) {
      setError('Сначала найдите и выберите вариант товара.')
      return
    }

    const payload = {
      variant_id: Number(selectedVariantId),
      ...(kind === 'receipts'
        ? { quantity: Number(values.get('quantity')) }
        : { counted_on_hand: Number(values.get('counted_on_hand')) }),
      note: String(values.get('note') ?? '').trim(),
    }

    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const response = await apiRequest<{ message?: string }>(`/admin/warehouse/${kind}`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })
      setMessage(response.message ?? (kind === 'receipts' ? 'Поступление записано.' : 'Остаток сверён.'))
      form.reset()
      await loadInventory()
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось выполнить складскую операцию.')
    } finally {
      setBusy(false)
    }
  }

  const selectTab = (next: WarehouseTab) => {
    setPage(1)
    setTab(next)
  }

  const runInventorySearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setPage(1)
    const values = new FormData(event.currentTarget)
    setInventorySearch(String(values.get('search') ?? '').trim())
  }

  if (!canManageWarehouse) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">Войдите под аккаунтом с правами работы со складом.</p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  const selectedVariant = variants.find((variant) => String(variant.id) === selectedVariantId)
  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Рабочее пространство</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Склад</h1>
            <p className="mt-2 text-sm text-muted-foreground">Поступления, сверка физических остатков и журнал движений.</p>
          </div>
          <button className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" disabled={loading} onClick={() => void loadInventory()} type="button">
            <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} /> Обновить
          </button>
        </div>

        {(error || sessionError || message) && <div className={`mb-5 rounded-xl border px-4 py-3 text-sm ${error || sessionError ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`} role={error || sessionError ? 'alert' : 'status'}>{error ?? sessionError ?? message}</div>}

        <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
          <section className="space-y-4">
            <nav aria-label="Складские разделы" className="flex gap-2 border-b pb-3">
              {(Object.entries(tabLabels) as [WarehouseTab, string][]).map(([key, label]) => (
                <button aria-current={tab === key ? 'page' : undefined} className={`rounded-xl px-4 py-2.5 text-sm font-medium ${tab === key ? 'bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'}`} key={key} onClick={() => selectTab(key)} type="button">{label}</button>
              ))}
            </nav>
            <form className="flex gap-2" onSubmit={runInventorySearch}>
              <input aria-label="Поиск по названию товара или SKU" className="h-10 min-w-0 flex-1 rounded-lg border bg-background px-3 text-sm" defaultValue={inventorySearch} name="search" placeholder="Товар или SKU" />
              {tab === 'movements' && (
                <select aria-label="Тип движения" className="h-10 rounded-lg border bg-background px-2 text-sm" onChange={(event) => { setPage(1); setMovementType(event.target.value) }} value={movementType}>
                  <option value="">Все движения</option>
                  {Object.entries(movementLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              )}
              <button className="h-10 rounded-lg border px-4 text-sm hover:bg-muted" type="submit">Найти</button>
            </form>
            {tab === 'balances' && (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950" role="status">
                <span>Низкий остаток (доступно ≤ {lowStockThreshold}): <strong>{lowStockCount}</strong></span>
                <button
                  className="rounded-lg border border-amber-400 px-3 py-1.5 text-xs font-medium hover:bg-amber-100"
                  onClick={() => { setPage(1); setLowStockOnly((current) => !current) }}
                  type="button"
                >
                  {lowStockOnly ? 'Показать все остатки' : 'Показать низкие остатки'}
                </button>
              </div>
            )}

            {loading ? <p className="py-12 text-center text-sm text-muted-foreground">Загружаем склад…</p> : tab === 'balances' ? (
              balances.length === 0 ? <p className="rounded-2xl border bg-card px-4 py-10 text-center text-sm text-muted-foreground">Остатки не найдены.</p> : (
                <div className="overflow-x-auto rounded-2xl border bg-card">
                  <table className="w-full min-w-[640px] text-left text-sm">
                    <thead className="bg-muted/50 text-xs text-muted-foreground"><tr><th className="p-3 font-medium">Товар / SKU</th><th className="p-3 font-medium">Склад</th><th className="p-3 font-medium">Всего</th><th className="p-3 font-medium">В резерве</th><th className="p-3 font-medium">Доступно</th></tr></thead>
                    <tbody className="divide-y">
                      {balances.map((balance) => (
                        <tr className="hover:bg-muted/30" key={balance.id}>
                          <td className="p-3"><p className="font-medium">{balance.product_name}</p><p className="mt-1 text-xs text-muted-foreground">{balance.variant_name} · {balance.sku}</p></td>
                          <td className="p-3">{balance.warehouse_name}</td>
                          <td className="p-3">{quantityFormat.format(balance.on_hand)}</td>
                          <td className="p-3">{quantityFormat.format(balance.reserved)}</td>
                          <td className="p-3 font-semibold">
                            {quantityFormat.format(balance.available)}
                            {balance.available <= lowStockThreshold && <span className="ml-2 rounded-full bg-amber-100 px-2 py-1 text-xs font-medium text-amber-900">Низкий</span>}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )
            ) : movements.length === 0 ? <p className="rounded-2xl border bg-card px-4 py-10 text-center text-sm text-muted-foreground">Движений пока нет.</p> : (
              <div className="space-y-2">
                {movements.map((movement) => (
                  <article className="flex flex-wrap items-start justify-between gap-3 rounded-xl border bg-card p-4" key={movement.id}>
                    <div><p className="font-medium">{movement.product_name} · {movement.sku}</p><p className="mt-1 text-xs text-muted-foreground">{movementLabels[movement.type] ?? movement.type} · {movement.warehouse_name} · {movement.actor_name ?? 'Система'} · {formattedDate(movement.created_at)}</p>{movement.note && <p className="mt-2 text-sm">{movement.note}</p>}</div>
                    <span className={`rounded-full px-3 py-1 text-sm font-semibold ${movement.quantity_delta > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-muted'}`}>{movement.quantity_delta > 0 ? '+' : ''}{quantityFormat.format(movement.quantity_delta)}</span>
                  </article>
                ))}
              </div>
            )}

            {!loading && lastPage > 1 && (
              <div className="flex items-center justify-between rounded-xl border bg-card px-3 py-2 text-sm">
                <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={page <= 1} onClick={() => setPage((current) => current - 1)} type="button">Назад</button>
                <span className="text-muted-foreground">Страница {page} из {lastPage}</span>
                <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={page >= lastPage} onClick={() => setPage((current) => current + 1)} type="button">Далее</button>
              </div>
            )}
          </section>

          <aside className="space-y-4">
            <section className="space-y-4 rounded-2xl border bg-card p-5">
              <div>
                <h2 className="text-lg font-semibold">Найти вариант товара</h2>
                <p className="mt-1 text-xs text-muted-foreground">Укажите название или SKU для поступления или сверки.</p>
              </div>
              <form className="flex gap-2" onSubmit={(event) => void searchVariants(event)}>
                <input className="h-10 min-w-0 flex-1 rounded-lg border bg-background px-3 text-sm" onChange={(event) => setSelectedVariantSearch(event.target.value)} value={selectedVariantSearch} />
                <button className="h-10 rounded-lg border px-3 text-sm hover:bg-muted" type="submit">Найти</button>
              </form>
              <label className="block text-sm font-medium">Вариант / SKU
                <select className="mt-2 h-11 w-full rounded-lg border bg-background px-3 font-normal" onChange={(event) => setSelectedVariantId(event.target.value)} value={selectedVariantId}>
                  {variants.length === 0 ? <option value="">Нет вариантов</option> : variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.product_name} · {variant.name} · {variant.sku}</option>)}
                </select>
              </label>
              {selectedVariant && <p className="text-xs text-muted-foreground">{selectedBalance ? `Всего ${selectedBalance.on_hand}, резерв ${selectedBalance.reserved}, доступно ${selectedBalance.available}` : 'Текущий остаток: 0'}</p>}
            </section>

            <form className="space-y-4 rounded-2xl border bg-card p-5" onSubmit={(event) => void submitStockOperation(event, 'receipts')}>
              <div><h2 className="text-lg font-semibold">Поступление</h2><p className="mt-1 text-xs text-muted-foreground">Добавляет количество к физическому остатку.</p></div>
              <label className="block text-sm font-medium">Количество
                <input className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" max="2147483647" min="1" name="quantity" required type="number" />
              </label>
              <label className="block text-sm font-medium">Комментарий / документ
                <textarea className="mt-2 min-h-20 w-full rounded-lg border bg-background px-3 py-2 font-normal" maxLength={1000} name="note" required />
              </label>
              <button className="h-10 w-full rounded-lg bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy || !selectedVariantId} type="submit">Записать поступление</button>
            </form>

            <form className="space-y-4 rounded-2xl border bg-card p-5" onSubmit={(event) => void submitStockOperation(event, 'reconciliations')}>
              <div><h2 className="text-lg font-semibold">Сверка остатка</h2><p className="mt-1 text-xs text-muted-foreground">Задайте фактически пересчитанное количество; разница попадёт в журнал.</p></div>
              <label className="block text-sm font-medium">Фактическое количество
                <input className="mt-2 h-10 w-full rounded-lg border bg-background px-3 font-normal" max="2147483647" min="0" name="counted_on_hand" required type="number" />
              </label>
              <label className="block text-sm font-medium">Причина расхождения
                <textarea className="mt-2 min-h-20 w-full rounded-lg border bg-background px-3 py-2 font-normal" maxLength={1000} name="note" required />
              </label>
              <button className="h-10 w-full rounded-lg border px-4 text-sm font-medium hover:bg-muted disabled:opacity-50" disabled={busy || !selectedVariantId} type="submit">Сверить и сохранить</button>
            </form>
          </aside>
        </div>
      </div>
    </main>
  )
}

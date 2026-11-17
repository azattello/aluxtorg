import { useEffect, useMemo, useState } from 'react'
import { ArrowLeft, ArrowRight, Minus, Plus, Trash2 } from 'lucide-react'
import { ApiError } from '@/lib/api'
import { BrandMark } from '@/components/BrandMark'
import {
  formatKzt,
  loadCurrentCart,
  removeCartEntry,
  setCartEntryQuantity,
  type CurrentCartEntry,
} from '@/lib/cart'

export function CartPage() {
  const [lines, setLines] = useState<CurrentCartEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const reload = async () => {
    setLoading(true)
    setError(null)
    try {
      setLines(await loadCurrentCart())
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : requestError instanceof Error
          ? requestError.message
          : 'Не удалось загрузить корзину.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    document.title = 'Корзина'
    void reload()
  }, [])

  const total = useMemo(
    () => lines.reduce((sum, line) => sum + (line.variant?.price_kzt ?? 0) * line.entry.quantity, 0),
    [lines],
  )
  const hasUnavailableItems = lines.some((line) => !line.variant?.available)

  const changeQuantity = (variantId: number, quantity: number) => {
    try {
      setCartEntryQuantity(variantId, quantity)
      setLines((current) => current.map((line) => line.entry.variantId === variantId
        ? { ...line, entry: { ...line.entry, quantity } }
        : line))
      setError(null)
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось изменить количество.')
    }
  }

  const removeItem = (variantId: number) => {
    try {
      removeCartEntry(variantId)
      setLines((current) => current.filter((line) => line.entry.variantId !== variantId))
      setError(null)
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось удалить товар.')
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

        <div className="mt-8">
          <h1 className="text-3xl font-semibold tracking-tight">Корзина</h1>
          <p className="mt-2 text-sm text-muted-foreground">Цены и наличие обновлены по данным каталога. Итог заказа сервер пересчитает при оформлении.</p>
        </div>

        {error && (
          <div className="mt-6 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive" role="alert">
            {error}
            <button className="ml-3 font-medium underline" onClick={() => void reload()} type="button">Повторить</button>
          </div>
        )}

        {loading ? (
          <p className="py-16 text-center text-sm text-muted-foreground" role="status">Обновляем цены и наличие…</p>
        ) : lines.length === 0 ? (
          <section className="mt-8 rounded-2xl border bg-card p-10 text-center">
            <h2 className="text-lg font-semibold">Корзина пуста</h2>
            <p className="mt-2 text-sm text-muted-foreground">Добавьте товары из каталога, чтобы оформить заказ.</p>
            <a className="mt-5 inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/">
              Перейти в каталог <ArrowRight size={16} />
            </a>
          </section>
        ) : (
          <div className="mt-8 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <section aria-label="Товары в корзине" className="space-y-3">
              {lines.map(({ entry, product, variant }) => (
                <article className="flex gap-4 rounded-2xl border bg-card p-4" key={entry.variantId}>
                  <a className="size-24 shrink-0 overflow-hidden rounded-xl bg-muted sm:size-28" href={`/products/${encodeURIComponent(entry.productSlug)}`}>
                    {product?.images[0] ? (
                      <img className="size-full object-cover" src={product.images[0].secure_url} alt={product.images[0].alt_text} />
                    ) : (
                      <span className="flex size-full items-center justify-center px-2 text-center text-xs text-muted-foreground">Нет фото</span>
                    )}
                  </a>
                  <div className="min-w-0 flex-1">
                    <a className="font-medium hover:text-primary" href={`/products/${encodeURIComponent(entry.productSlug)}`}>
                      {product?.name ?? entry.productName}
                    </a>
                    <p className="mt-1 text-sm text-muted-foreground">{variant?.name ?? entry.variantName} · SKU {variant?.sku ?? entry.sku}</p>
                    {variant ? (
                      <>
                        <p className="mt-2 text-sm font-semibold">{formatKzt(variant.price_kzt)} / {variant.sale_unit}</p>
                        {!variant.available && <p className="mt-1 text-sm text-destructive" role="status">Нет в наличии — удалите товар, чтобы продолжить.</p>}
                      </>
                    ) : (
                      <p className="mt-2 text-sm text-destructive" role="status">Товар больше недоступен — удалите его из корзины.</p>
                    )}
                    <div className="mt-3 flex items-center gap-2">
                      <button
                        aria-label={`Уменьшить количество ${entry.productName}`}
                        className="flex size-9 items-center justify-center rounded-lg border hover:bg-muted"
                        disabled={entry.quantity <= 1}
                        onClick={() => changeQuantity(entry.variantId, entry.quantity - 1)}
                        type="button"
                      ><Minus size={15} /></button>
                      <span aria-label="Количество" className="min-w-8 text-center text-sm tabular-nums">{entry.quantity}</span>
                      <button
                        aria-label={`Увеличить количество ${entry.productName}`}
                        className="flex size-9 items-center justify-center rounded-lg border hover:bg-muted"
                        disabled={entry.quantity >= 999}
                        onClick={() => changeQuantity(entry.variantId, entry.quantity + 1)}
                        type="button"
                      ><Plus size={15} /></button>
                      <button
                        aria-label={`Удалить ${entry.productName} из корзины`}
                        className="ml-auto flex size-9 items-center justify-center rounded-lg text-muted-foreground hover:bg-muted hover:text-destructive"
                        onClick={() => removeItem(entry.variantId)}
                        type="button"
                      ><Trash2 size={16} /></button>
                    </div>
                  </div>
                  <p className="hidden shrink-0 text-sm font-semibold tabular-nums sm:block">
                    {variant ? formatKzt(variant.price_kzt * entry.quantity) : '—'}
                  </p>
                </article>
              ))}
            </section>

            <aside className="rounded-2xl border bg-card p-5">
              <h2 className="text-lg font-semibold">Ваш заказ</h2>
              <div className="mt-4 flex justify-between gap-4 border-t pt-4 text-sm">
                <span className="text-muted-foreground">Товары</span>
                <span className="font-semibold tabular-nums">{formatKzt(total)}</span>
              </div>
              <p className="mt-3 text-xs leading-5 text-muted-foreground">
                Доставку и её стоимость менеджер согласует в WhatsApp. Самовывоз также согласуется с менеджером.
              </p>
              {hasUnavailableItems ? (
                <button className="mt-5 flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-muted px-4 text-sm font-medium text-muted-foreground" disabled type="button">
                  Удалите недоступные товары
                </button>
              ) : (
                <a className="mt-5 flex h-11 items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90" href="/checkout">
                  Оформить заказ <ArrowRight size={16} />
                </a>
              )}
              <a className="mt-4 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground" href="/">
                <ArrowLeft size={15} /> Продолжить покупки
              </a>
            </aside>
          </div>
        )}
      </div>
    </main>
  )
}

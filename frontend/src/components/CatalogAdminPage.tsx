import { useEffect, useState, type FormEvent } from 'react'
import { ImagePlus, Plus, Save, Trash2 } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'
import { ViewModeToggle, type ViewMode } from '@/components/ViewModeToggle'

interface ApiCollection<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number }
}

interface Category {
  id: number
  parent_id: number | null
  name: string
  slug: string
  is_active: boolean
  sort_order: number
  meta_title: string | null
  meta_description: string | null
}

interface ProductImage {
  id: number
  secure_url: string
  alt_text: string
  sort_order: number
}

interface ProductVariant {
  id: number
  sku: string
  name: string
  sale_unit: string
  attributes: Record<string, string>
  price_kzt: number
  is_active: boolean
  is_returnable: boolean
  sort_order: number
}

interface Product {
  id: number
  category_id: number
  name: string
  slug: string
  description: string | null
  is_published: boolean
  sort_order: number
  meta_title: string | null
  meta_description: string | null
  images: ProductImage[]
  variants: ProductVariant[]
}

interface Banner {
  id: number
  title: string
  placement: 'home_hero' | 'home_promo'
  secure_url: string
  alt_text: string
  destination_url: string | null
  starts_at: string | null
  ends_at: string | null
  is_active: boolean
  sort_order: number
}

interface CatalogData {
  categories: Category[]
  products: Product[]
  banners: Banner[]
}

type CatalogSection = 'products' | 'categories' | 'banners'

interface VariantForm {
  id?: number
  sku: string
  name: string
  sale_unit: string
  attributes: string
  price_kzt: string
  is_active: boolean
  is_returnable: boolean
  sort_order: number
}

const initialVariant: VariantForm = {
  sku: '',
  name: 'Основной вариант',
  sale_unit: 'шт.',
  attributes: '',
  price_kzt: '',
  is_active: true,
  is_returnable: false,
  sort_order: 0,
}

function blankCatalogData(): CatalogData {
  return { categories: [], products: [], banners: [] }
}

function formatLocalDate(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function toUtcDate(value: FormDataEntryValue | null): string | null {
  if (typeof value !== 'string' || value === '') return null
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? null : date.toISOString()
}

function parseAttributes(value: string): Record<string, string> {
  return Object.fromEntries(
    value.split('\n')
      .map((line) => line.split('=').map((part) => part.trim()))
      .filter((parts) => parts.length === 2 && parts[0] !== '' && parts[1] !== '')
      .map(([key, entry]) => [key, entry]),
  )
}

function attributesText(attributes: Record<string, string>): string {
  return Object.entries(attributes).map(([key, value]) => `${key}=${value}`).join('\n')
}

export function CatalogAdminPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [data, setData] = useState<CatalogData>(blankCatalogData)
  const [section, setSection] = useState<CatalogSection>('products')
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [editingProduct, setEditingProduct] = useState<Product | null>(null)
  const [editingCategory, setEditingCategory] = useState<Category | null>(null)
  const [editingBanner, setEditingBanner] = useState<Banner | null>(null)
  const [variants, setVariants] = useState<VariantForm[]>([{ ...initialVariant }])
  const [productSearch, setProductSearch] = useState('')
  const [productQuery, setProductQuery] = useState('')
  const [productStatus, setProductStatus] = useState('')
  const [productSort, setProductSort] = useState('updated_newest')
  const [productPage, setProductPage] = useState(1)
  const [productLastPage, setProductLastPage] = useState(1)
  const [productTotal, setProductTotal] = useState(0)
  const [viewMode, setViewMode] = useState<ViewMode>('cards')

  const canManageCatalog = user && ['manager', 'administrator', 'super_administrator'].includes(user.role)

  const loadCatalog = async () => {
    setLoading(true)
    setError(null)
    try {
      const [categories, products, banners] = await Promise.all([
        apiRequest<ApiCollection<Category>>('/admin/categories'),
        apiRequest<ApiCollection<Product>>(`/admin/products?${new URLSearchParams({
          page: String(productPage),
          per_page: '30',
          sort: productSort,
          ...(productQuery ? { search: productQuery } : {}),
          ...(productStatus ? { status: productStatus } : {}),
        }).toString()}`),
        apiRequest<ApiCollection<Banner>>('/admin/banners'),
      ])
      setData({ categories: categories.data, products: products.data, banners: banners.data })
      setProductLastPage(products.meta?.last_page ?? 1)
      setProductTotal(products.meta?.total ?? products.data.length)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить каталог.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManageCatalog) void loadCatalog()
  }, [canManageCatalog, productPage, productQuery, productSort, productStatus])

  const resetProduct = () => {
    setEditingProduct(null)
    setVariants([{ ...initialVariant }])
    const form = document.querySelector<HTMLFormElement>('#product-form')
    form?.reset()
  }

  const startProductEdit = (product: Product) => {
    setEditingProduct(product)
    setVariants(product.variants.map((variant) => ({
      id: variant.id,
      sku: variant.sku,
      name: variant.name,
      sale_unit: variant.sale_unit,
      attributes: attributesText(variant.attributes),
      price_kzt: String(variant.price_kzt),
      is_active: variant.is_active,
      is_returnable: variant.is_returnable,
      sort_order: variant.sort_order,
    })))
    document.querySelector('#product-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  const saveProduct = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const form = event.currentTarget
    const values = new FormData(form)
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const payload = {
        category_id: Number(values.get('category_id')),
        name: String(values.get('name') ?? '').trim(),
        slug: String(values.get('slug') ?? '').trim() || null,
        description: String(values.get('description') ?? '').trim() || null,
        is_published: values.get('is_published') === 'on',
        sort_order: Number(values.get('sort_order') || 0),
        meta_title: String(values.get('meta_title') ?? '').trim() || null,
        meta_description: String(values.get('meta_description') ?? '').trim() || null,
        variants: variants.map((variant, index) => ({
          ...(variant.id ? { id: variant.id } : {}),
          sku: variant.sku.trim(),
          name: variant.name.trim(),
          sale_unit: variant.sale_unit.trim(),
          attributes: parseAttributes(variant.attributes),
          price_kzt: Number(variant.price_kzt),
          is_active: variant.is_active,
          is_returnable: variant.is_returnable,
          sort_order: index,
        })),
      }
      const saved = await apiRequest<{ data: Product }>(
        editingProduct ? `/admin/products/${editingProduct.id}` : '/admin/products',
        { method: editingProduct ? 'PATCH' : 'POST', body: JSON.stringify(payload) },
      )

      const images = values.getAll('images').filter((image): image is File => image instanceof File && image.size > 0)
      for (const image of images) {
        const upload = new FormData()
        upload.set('image', image)
        upload.set('alt_text', saved.data.name)
        await apiRequest(`/admin/products/${saved.data.id}/images`, { method: 'POST', body: upload })
      }

      setMessage(editingProduct ? 'Товар обновлён.' : 'Товар создан.')
      resetProduct()
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось сохранить товар.')
    } finally {
      setBusy(false)
    }
  }

  const saveCategory = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const payload = {
        parent_id: String(values.get('parent_id') ?? '') ? Number(values.get('parent_id')) : null,
        name: String(values.get('name') ?? '').trim(),
        slug: String(values.get('slug') ?? '').trim() || null,
        is_active: values.get('is_active') === 'on',
        sort_order: Number(values.get('sort_order') || 0),
        meta_title: String(values.get('meta_title') ?? '').trim() || null,
        meta_description: String(values.get('meta_description') ?? '').trim() || null,
      }
      await apiRequest(editingCategory ? `/admin/categories/${editingCategory.id}` : '/admin/categories', {
        method: editingCategory ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      setMessage(editingCategory ? 'Категория обновлена.' : 'Категория создана.')
      setEditingCategory(null)
      event.currentTarget.reset()
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось сохранить категорию.')
    } finally {
      setBusy(false)
    }
  }

  const saveBanner = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const values = new FormData(event.currentTarget)
    const image = values.get('image')
    setBusy(true)
    setError(null)
    setMessage(null)
    try {
      const payload = new FormData()
      payload.set('title', String(values.get('title') ?? '').trim())
      payload.set('placement', String(values.get('placement') ?? 'home_hero'))
      payload.set('alt_text', String(values.get('alt_text') ?? '').trim())
      payload.set('destination_url', String(values.get('destination_url') ?? '').trim())
      payload.set('starts_at', toUtcDate(values.get('starts_at')) ?? '')
      payload.set('ends_at', toUtcDate(values.get('ends_at')) ?? '')
      payload.set('is_active', values.get('is_active') === 'on' ? '1' : '0')
      payload.set('sort_order', String(values.get('sort_order') || 0))
      if (image instanceof File && image.size > 0) payload.set('image', image)

      if (editingBanner) payload.set('_method', 'PATCH')
      await apiRequest(editingBanner ? `/admin/banners/${editingBanner.id}` : '/admin/banners', {
        method: 'POST',
        body: payload,
      })
      setMessage(editingBanner ? 'Баннер обновлён.' : 'Баннер создан.')
      setEditingBanner(null)
      event.currentTarget.reset()
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось сохранить баннер.')
    } finally {
      setBusy(false)
    }
  }

  const removeProductImage = async (product: Product, image: ProductImage) => {
    if (!window.confirm(`Удалить изображение товара «${product.name}»?`)) return
    setBusy(true)
    setError(null)
    try {
      await apiRequest(`/admin/products/${product.id}/images/${image.id}`, { method: 'DELETE' })
      setMessage('Изображение удалено.')
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось удалить изображение.')
    } finally {
      setBusy(false)
    }
  }

  const archiveProduct = async (product: Product) => {
    if (!window.confirm(`Снять товар «${product.name}» с публикации? История заказов будет сохранена.`)) return
    setBusy(true)
    setError(null)
    try {
      await apiRequest(`/admin/products/${product.id}`, { method: 'DELETE' })
      setMessage('Товар снят с публикации.')
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось изменить публикацию.')
    } finally {
      setBusy(false)
    }
  }

  const removeCategory = async (category: Category) => {
    if (!window.confirm(`Удалить категорию «${category.name}»?`)) return
    setBusy(true)
    setError(null)
    try {
      await apiRequest(`/admin/categories/${category.id}`, { method: 'DELETE' })
      setMessage('Категория удалена.')
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось удалить категорию.')
    } finally {
      setBusy(false)
    }
  }

  const removeBanner = async (banner: Banner) => {
    if (!window.confirm(`Удалить баннер «${banner.title}»?`)) return
    setBusy(true)
    setError(null)
    try {
      await apiRequest(`/admin/banners/${banner.id}`, { method: 'DELETE' })
      setMessage('Баннер удалён.')
      await loadCatalog()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось удалить баннер.')
    } finally {
      setBusy(false)
    }
  }

  if (!canManageCatalog) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">Войдите под аккаунтом с правами управления каталогом.</p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  const editingVariant = editingProduct?.variants ?? []

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-8">
          <p className="text-sm font-medium text-primary">Рабочее пространство</p>
          <h1 className="mt-2 text-3xl font-semibold tracking-tight">Каталог и контент</h1>
          <p className="mt-2 text-sm text-muted-foreground">Управляйте товарами, категориями и расписанием баннеров.</p>
        </div>

        {(error || sessionError || message) && (
          <div className={`mb-6 rounded-xl border px-4 py-3 text-sm ${error || sessionError ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`} role={error || sessionError ? 'alert' : 'status'}>
            {error ?? sessionError ?? message}
          </div>
        )}

        <nav aria-label="Разделы каталога" className="mb-7 flex flex-wrap gap-2 border-b pb-3">
          {([
            ['products', 'Товары'],
            ['categories', 'Категории'],
            ['banners', 'Баннеры'],
          ] as const).map(([key, label]) => (
            <button aria-current={section === key ? 'page' : undefined} className={`rounded-xl px-4 py-2.5 text-sm font-medium ${section === key ? 'bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'}`} key={key} onClick={() => setSection(key)} type="button">{label}</button>
          ))}
        </nav>

        {loading ? <p className="py-12 text-center text-sm text-muted-foreground" role="status">Загружаем каталог…</p> : (
          <>
            {section === 'products' && (
              <section className="grid items-start gap-8 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
                <div className="space-y-3">
                  <form className="grid gap-3 rounded-2xl border bg-card p-4 sm:grid-cols-2" onSubmit={(event) => {
                    event.preventDefault()
                    setProductPage(1)
                    setProductQuery(productSearch.trim())
                  }}>
                    <label className="sr-only" htmlFor="admin-product-search">Поиск по названию, адресу или SKU</label>
                    <input autoComplete="off" className="h-10 min-w-0 rounded-xl border bg-background px-3 text-sm" id="admin-product-search" maxLength={120} onChange={(event) => setProductSearch(event.target.value)} placeholder="Название, адрес или SKU" type="search" value={productSearch} />
                    <label className="sr-only" htmlFor="admin-product-status">Статус публикации</label>
                    <select className="h-10 min-w-0 rounded-xl border bg-background px-3 text-sm" id="admin-product-status" onChange={(event) => { setProductPage(1); setProductStatus(event.target.value) }} value={productStatus}>
                      <option value="">Все товары</option>
                      <option value="published">Опубликованные</option>
                      <option value="draft">Черновики</option>
                    </select>
                    <label className="sr-only" htmlFor="admin-product-sort">Сортировка товаров</label>
                    <select className="h-10 min-w-0 rounded-xl border bg-background px-3 text-sm" id="admin-product-sort" onChange={(event) => { setProductPage(1); setProductSort(event.target.value) }} value={productSort}>
                      <option value="updated_newest">Сначала изменённые</option>
                      <option value="name_asc">Название А—Я</option>
                      <option value="name_desc">Название Я—А</option>
                      <option value="price_asc">Сначала дешевле</option>
                      <option value="price_desc">Сначала дороже</option>
                    </select>
                    <div className="flex items-center justify-between gap-3">
                      <button className="h-10 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground" type="submit">Найти</button>
                      <span className="text-sm text-muted-foreground">Товаров: {productTotal}</span>
                    </div>
                  </form>
                  <div className="flex justify-end">
                    <ViewModeToggle onChange={setViewMode} value={viewMode} />
                  </div>
                  {data.products.length === 0 ? <EmptyState title={productQuery || productStatus ? 'По запросу товары не найдены' : 'Товаров пока нет'} /> : viewMode === 'cards' ? data.products.map((product) => (
                    <article className="rounded-2xl border bg-card p-4" key={product.id}>
                      <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                          <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-semibold">{product.name}</h2>
                            <span className={`rounded-full px-2.5 py-1 text-xs ${product.is_published ? 'bg-emerald-50 text-emerald-800' : 'bg-muted text-muted-foreground'}`}>{product.is_published ? 'Опубликован' : 'Черновик'}</span>
                          </div>
                          <p className="mt-1 text-sm text-muted-foreground">/{product.slug} · {product.variants.length} вариантов</p>
                        </div>
                        <div className="flex gap-2">
                          <button className="rounded-lg border px-3 py-2 text-sm hover:bg-muted" onClick={() => startProductEdit(product)} type="button">Изменить</button>
                          <button aria-label={`Снять с публикации ${product.name}`} className="rounded-lg border p-2 text-muted-foreground hover:text-destructive" onClick={() => void archiveProduct(product)} type="button"><Trash2 className="size-4" /></button>
                        </div>
                      </div>
                      {product.images.length > 0 && (
                        <div className="mt-4 flex flex-wrap gap-3">
                          {product.images.map((image) => (
                            <div className="relative" key={image.id}>
                              <img className="size-20 rounded-lg border object-cover" src={image.secure_url} alt={image.alt_text} />
                              <button aria-label="Удалить изображение" className="absolute -right-2 -top-2 rounded-full bg-card p-1 shadow" disabled={busy} onClick={() => void removeProductImage(product, image)} type="button"><Trash2 className="size-3.5 text-destructive" /></button>
                            </div>
                          ))}
                        </div>
                      )}
                    </article>
                  )) : (
                    <div className="overflow-x-auto rounded-2xl border bg-card">
                      <table className="w-full min-w-[700px] text-left text-sm">
                        <thead className="border-b bg-muted/40 text-muted-foreground">
                          <tr><th className="px-3 py-3 font-medium">Товар</th><th className="px-3 py-3 font-medium">Категория</th><th className="px-3 py-3 font-medium">SKU / варианты</th><th className="px-3 py-3 font-medium">Статус</th><th aria-label="Действия" className="px-3 py-3 font-medium" /></tr>
                        </thead>
                        <tbody className="divide-y">
                          {data.products.map((product) => (
                            <tr key={product.id}>
                              <td className="px-3 py-3"><span className="font-medium">{product.name}</span><span className="block text-xs text-muted-foreground">/{product.slug}</span></td>
                              <td className="px-3 py-3">{data.categories.find((category) => category.id === product.category_id)?.name ?? '—'}</td>
                              <td className="px-3 py-3">{product.variants.length} вариантов<span className="block text-xs text-muted-foreground">{product.variants.map((variant) => variant.sku).join(', ')}</span></td>
                              <td className="px-3 py-3">{product.is_published ? 'Опубликован' : 'Черновик'}</td>
                              <td className="px-3 py-3 text-right whitespace-nowrap">
                                <button className="rounded-lg border px-3 py-1.5 hover:bg-muted" onClick={() => startProductEdit(product)} type="button">Изменить</button>
                                <button aria-label={`Снять с публикации ${product.name}`} className="ml-2 rounded-lg border p-2 text-muted-foreground hover:text-destructive" onClick={() => void archiveProduct(product)} type="button"><Trash2 className="size-4" /></button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  {productLastPage > 1 && (
                    <nav aria-label="Страницы каталога" className="flex items-center justify-between rounded-xl border bg-card px-3 py-2 text-sm">
                      <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={productPage <= 1 || loading} onClick={() => setProductPage((current) => current - 1)} type="button">Назад</button>
                      <span className="text-muted-foreground">Страница {productPage} из {productLastPage}</span>
                      <button className="rounded-lg border px-3 py-1.5 disabled:opacity-40" disabled={productPage >= productLastPage || loading} onClick={() => setProductPage((current) => current + 1)} type="button">Далее</button>
                    </nav>
                  )}
                </div>

                <form className="space-y-4 rounded-2xl border bg-card p-5" id="product-form" key={editingProduct?.id ?? 'new-product'} onSubmit={saveProduct}>
                  <div className="flex items-center justify-between gap-3" id="product-editor">
                    <h2 className="text-lg font-semibold">{editingProduct ? 'Редактировать товар' : 'Новый товар'}</h2>
                    {editingProduct && <button className="text-sm text-muted-foreground underline" onClick={resetProduct} type="button">Отмена</button>}
                  </div>
                  <label className="block text-sm font-medium">Название
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingProduct?.name ?? ''} maxLength={180} name="name" required />
                  </label>
                  <label className="block text-sm font-medium">Адрес страницы (можно оставить пустым)
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingProduct?.slug ?? ''} maxLength={180} name="slug" placeholder="Например, letnyaya-tkan" />
                  </label>
                  <label className="block text-sm font-medium">Категория
                    <select className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingProduct?.category_id ?? ''} name="category_id" required>
                      <option disabled value="">Выберите категорию</option>
                      {data.categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                  </label>
                  <label className="block text-sm font-medium">Описание
                    <textarea className="mt-2 min-h-24 w-full rounded-xl border bg-background px-3 py-2 font-normal" defaultValue={editingProduct?.description ?? ''} maxLength={20000} name="description" />
                  </label>
                  <div className="space-y-3 border-t pt-4">
                    <div className="flex items-center justify-between gap-3">
                      <h3 className="font-medium">Варианты и SKU</h3>
                      <button className="inline-flex items-center gap-1 text-sm text-primary" onClick={() => setVariants((current) => [...current, { ...initialVariant, sort_order: current.length }])} type="button"><Plus className="size-4" /> Вариант</button>
                    </div>
                    {variants.map((variant, index) => (
                      <fieldset className="space-y-3 rounded-xl border p-3" key={variant.id ?? `new-${index}`}>
                        <legend className="px-1 text-xs text-muted-foreground">Вариант {index + 1}</legend>
                        <label className="block text-xs font-medium">SKU
                          <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, sku: event.target.value } : item))} required value={variant.sku} />
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                          <label className="text-xs font-medium">Название варианта
                            <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, name: event.target.value } : item))} required value={variant.name} />
                          </label>
                          <label className="text-xs font-medium">Цена, ₸
                            <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" min="0" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, price_kzt: event.target.value } : item))} required type="number" value={variant.price_kzt} />
                          </label>
                        </div>
                        <label className="block text-xs font-medium">Единица продажи
                          <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, sale_unit: event.target.value } : item))} required value={variant.sale_unit} />
                        </label>
                        <label className="flex items-start gap-2 text-xs leading-5">
                          <input checked={variant.is_returnable} className="mt-1 accent-primary" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, is_returnable: event.target.checked } : item))} type="checkbox" />
                          <span>Разрешён возврат только целой, нераспечатанной упаковки этого SKU. Товары, продаваемые на отрез (например, единица «м»), и использованный материал вернуть нельзя.</span>
                        </label>
                        <label className="block text-xs font-medium">Характеристики (строка key=value на каждую)
                          <textarea className="mt-1 min-h-16 w-full rounded-lg border bg-background px-3 py-2 text-sm" onChange={(event) => setVariants((current) => current.map((item, position) => position === index ? { ...item, attributes: event.target.value } : item))} value={variant.attributes} />
                        </label>
                        {variants.length > 1 && <button className="text-xs text-destructive" onClick={() => setVariants((current) => current.filter((_, position) => position !== index))} type="button">Убрать вариант</button>}
                      </fieldset>
                    ))}
                  </div>
                  <label className="block text-sm font-medium">Изображения товара
                    <span className="mt-2 flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-dashed px-3 text-sm font-normal text-muted-foreground">
                      <ImagePlus className="size-4" /> Выберите JPG, PNG или WebP (до 5 МБ каждое)
                      <input accept="image/jpeg,image/png,image/webp" className="sr-only" multiple name="images" type="file" />
                    </span>
                  </label>
                  {editingProduct && editingVariant.length > 0 && (
                    <p className="text-xs text-muted-foreground">Сохранение списка отключает пропущенные варианты, но не удаляет их из истории заказов.</p>
                  )}
                  <label className="flex items-center gap-2 text-sm"><input defaultChecked={editingProduct?.is_published ?? false} name="is_published" type="checkbox" /> Опубликовать товар</label>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <label className="text-xs font-medium">SEO title
                      <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" defaultValue={editingProduct?.meta_title ?? ''} maxLength={160} name="meta_title" />
                    </label>
                    <label className="text-xs font-medium">SEO description
                      <input className="mt-1 h-10 w-full rounded-lg border bg-background px-3 text-sm" defaultValue={editingProduct?.meta_description ?? ''} maxLength={320} name="meta_description" />
                    </label>
                  </div>
                  <button className="inline-flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy || data.categories.length === 0} type="submit"><Save className="size-4" /> {busy ? 'Сохраняем…' : 'Сохранить товар'}</button>
                  {data.categories.length === 0 && <p className="text-xs text-muted-foreground">Сначала создайте категорию товара.</p>}
                </form>
              </section>
            )}

            {section === 'categories' && (
              <section className="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.65fr)]">
                <div className="space-y-3">
                  {data.categories.length === 0 ? <EmptyState title="Категорий пока нет" /> : data.categories.map((category) => (
                    <article className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border bg-card p-4" key={category.id}>
                      <div>
                        <h2 className="font-medium">{category.name}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">/{category.slug} · {category.is_active ? 'Активна' : 'Скрыта'}</p>
                      </div>
                      <div className="flex gap-2">
                        <button className="rounded-lg border px-3 py-2 text-sm" onClick={() => setEditingCategory(category)} type="button">Изменить</button>
                        <button aria-label={`Удалить ${category.name}`} className="rounded-lg border p-2 text-destructive" onClick={() => void removeCategory(category)} type="button"><Trash2 className="size-4" /></button>
                      </div>
                    </article>
                  ))}
                </div>
                <form className="space-y-4 rounded-2xl border bg-card p-5" key={editingCategory?.id ?? 'new-category'} onSubmit={saveCategory}>
                  <h2 className="text-lg font-semibold">{editingCategory ? 'Редактировать категорию' : 'Новая категория'}</h2>
                  <label className="block text-sm font-medium">Название
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingCategory?.name ?? ''} name="name" required />
                  </label>
                  <label className="block text-sm font-medium">Адрес страницы (можно оставить пустым)
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingCategory?.slug ?? ''} name="slug" placeholder="Например, materialy" />
                  </label>
                  <label className="block text-sm font-medium">Родительская категория
                    <select className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingCategory?.parent_id ?? ''} name="parent_id">
                      <option value="">Нет</option>
                      {data.categories.filter((category) => category.id !== editingCategory?.id).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
                    </select>
                  </label>
                  <label className="block text-sm font-medium">Порядок
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingCategory?.sort_order ?? 0} min="0" name="sort_order" type="number" />
                  </label>
                  <label className="flex items-center gap-2 text-sm"><input defaultChecked={editingCategory?.is_active ?? true} name="is_active" type="checkbox" /> Активна на витрине</label>
                  <label className="block text-sm font-medium">SEO title
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingCategory?.meta_title ?? ''} maxLength={160} name="meta_title" />
                  </label>
                  <label className="block text-sm font-medium">SEO description
                    <textarea className="mt-2 min-h-20 w-full rounded-xl border bg-background px-3 py-2 font-normal" defaultValue={editingCategory?.meta_description ?? ''} maxLength={320} name="meta_description" />
                  </label>
                  <button className="h-11 w-full rounded-xl bg-primary text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy} type="submit">{busy ? 'Сохраняем…' : 'Сохранить категорию'}</button>
                  {editingCategory && <button className="w-full text-sm text-muted-foreground underline" onClick={() => setEditingCategory(null)} type="button">Отмена</button>}
                </form>
              </section>
            )}

            {section === 'banners' && (
              <section className="grid items-start gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(320px,0.65fr)]">
                <div className="space-y-3">
                  {data.banners.length === 0 ? <EmptyState title="Баннеров пока нет" /> : data.banners.map((banner) => (
                    <article className="flex flex-wrap items-center gap-4 rounded-2xl border bg-card p-4" key={banner.id}>
                      <img className="size-20 rounded-xl object-cover" src={banner.secure_url} alt={banner.alt_text} />
                      <div className="min-w-0 flex-1">
                        <h2 className="font-medium">{banner.title}</h2>
                        <p className="mt-1 text-sm text-muted-foreground">{banner.placement === 'home_hero' ? 'Главный экран' : 'Промо-блок'} · {banner.is_active ? 'Активен' : 'Скрыт'}</p>
                      </div>
                      <button className="rounded-lg border px-3 py-2 text-sm" onClick={() => setEditingBanner(banner)} type="button">Изменить</button>
                      <button aria-label={`Удалить ${banner.title}`} className="rounded-lg border p-2 text-destructive" onClick={() => void removeBanner(banner)} type="button"><Trash2 className="size-4" /></button>
                    </article>
                  ))}
                </div>
                <form className="space-y-4 rounded-2xl border bg-card p-5" key={editingBanner?.id ?? 'new-banner'} onSubmit={saveBanner}>
                  <h2 className="text-lg font-semibold">{editingBanner ? 'Редактировать баннер' : 'Новый баннер'}</h2>
                  <label className="block text-sm font-medium">Заголовок
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingBanner?.title ?? ''} name="title" required />
                  </label>
                  <label className="block text-sm font-medium">Размещение
                    <select className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingBanner?.placement ?? 'home_hero'} name="placement">
                      <option value="home_hero">Главный экран</option>
                      <option value="home_promo">Промо-блок</option>
                    </select>
                  </label>
                  <label className="block text-sm font-medium">Изображение {editingBanner ? '(необязательно)' : ''}
                    <input accept="image/jpeg,image/png,image/webp" className="mt-2 block w-full text-sm" name="image" required={!editingBanner} type="file" />
                  </label>
                  <label className="block text-sm font-medium">Описание изображения
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingBanner?.alt_text ?? ''} name="alt_text" required />
                  </label>
                  <label className="block text-sm font-medium">Ссылка (необязательно)
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingBanner?.destination_url ?? ''} name="destination_url" placeholder="/products/item или https://…" type="text" />
                  </label>
                  <div className="grid grid-cols-2 gap-3">
                    <label className="text-xs font-medium">Показывать с
                      <input className="mt-2 h-11 w-full rounded-xl border bg-background px-2 text-sm" defaultValue={formatLocalDate(editingBanner?.starts_at ?? null)} name="starts_at" type="datetime-local" />
                    </label>
                    <label className="text-xs font-medium">Показывать до
                      <input className="mt-2 h-11 w-full rounded-xl border bg-background px-2 text-sm" defaultValue={formatLocalDate(editingBanner?.ends_at ?? null)} name="ends_at" type="datetime-local" />
                    </label>
                  </div>
                  <label className="block text-sm font-medium">Порядок
                    <input className="mt-2 h-11 w-full rounded-xl border bg-background px-3 font-normal" defaultValue={editingBanner?.sort_order ?? 0} min="0" name="sort_order" type="number" />
                  </label>
                  <label className="flex items-center gap-2 text-sm"><input defaultChecked={editingBanner?.is_active ?? false} name="is_active" type="checkbox" /> Активен</label>
                  <button className="h-11 w-full rounded-xl bg-primary text-sm font-medium text-primary-foreground disabled:opacity-50" disabled={busy} type="submit">{busy ? 'Сохраняем…' : 'Сохранить баннер'}</button>
                  {editingBanner && <button className="w-full text-sm text-muted-foreground underline" onClick={() => setEditingBanner(null)} type="button">Отмена</button>}
                </form>
              </section>
            )}
          </>
        )}

        <footer className="mt-12 border-t pt-5 text-xs text-muted-foreground">Изображения загружаются напрямую в Cloudinary через сервер. В браузере не используются Cloudinary credentials.</footer>
      </div>
    </main>
  )
}

function EmptyState({ title }: { title: string }) {
  return <div className="rounded-2xl border bg-card p-10 text-center text-sm text-muted-foreground">{title}</div>
}

import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { ArrowLeft, ArrowRight, Search, ShoppingBag, UserRound } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { BrandMark } from '@/components/BrandMark'
import { addCartEntry } from '@/lib/cart'
import { NotFoundPage } from '@/components/NotFoundPage'
import type { StoreSettings } from '@/lib/store'
import { whatsappHref } from '@/lib/store'

interface ApiCollection<T> {
  data: T[]
  links?: { next: string | null; prev: string | null }
  meta?: { current_page: number; last_page: number; total: number }
}

export interface Category {
  id: number
  parent_id: number | null
  name: string
  slug: string
  meta_title: string | null
  meta_description: string | null
}

export interface ProductImage {
  id: number
  secure_url: string
  alt_text: string
  sort_order: number
}

export interface ProductVariant {
  id: number
  sku: string
  name: string
  sale_unit: string
  attributes: Record<string, string>
  price_kzt: number
  available: boolean
  available_quantity: number | null
}

export interface Product {
  id: number
  category_id: number
  category: Category
  slug: string
  name: string
  description: string | null
  is_published: boolean
  meta_title: string | null
  meta_description: string | null
  min_price_kzt: number | null
  max_price_kzt: number | null
  available: boolean
  images: ProductImage[]
  variants: ProductVariant[]
}

export interface Banner {
  id: number
  title: string
  placement: 'home_hero' | 'home_promo'
  secure_url: string
  alt_text: string
  destination_url: string | null
}

interface ViewResponse {
  data: { recorded: boolean }
}

interface ProductResponse {
  data: Product
}

const money = new Intl.NumberFormat('ru-KZ', {
  style: 'currency',
  currency: 'KZT',
  maximumFractionDigits: 0,
})

const normalizedPath = window.location.pathname.replace(/\/+$/, '') || '/'
const queryFromLocation = new URLSearchParams(window.location.search)
const routeSlug = normalizedPath.startsWith('/products/')
  ? normalizedPath.slice('/products/'.length)
  : null
const categorySlug = normalizedPath.startsWith('/categories/')
  ? normalizedPath.slice('/categories/'.length)
  : null

function ProductCard({ product }: { product: Product }) {
  const image = product.images[0]

  return (
    <article className="group overflow-hidden rounded-2xl border bg-card">
      <a className="block" href={`/products/${encodeURIComponent(product.slug)}`}>
        <div className="aspect-[4/3] overflow-hidden bg-muted">
          {image ? (
            <img className="size-full object-cover transition duration-300 group-hover:scale-[1.03]" src={image.secure_url} alt={image.alt_text} loading="lazy" />
          ) : (
            <div className="flex size-full items-center justify-center text-sm text-muted-foreground">Изображение скоро появится</div>
          )}
        </div>
        <div className="space-y-2 p-3 sm:space-y-3 sm:p-4">
          <div className="text-xs text-muted-foreground">{product.category?.name}</div>
          <h2 className="min-h-10 text-sm font-semibold leading-5 sm:min-h-12 sm:text-base sm:leading-6">{product.name}</h2>
          <div className="flex flex-col items-start gap-1 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
            <span className="font-semibold tabular-nums">
              {product.min_price_kzt === null ? 'Цена уточняется' : money.format(product.min_price_kzt)}
              {product.max_price_kzt !== null && product.max_price_kzt !== product.min_price_kzt ? ` — ${money.format(product.max_price_kzt)}` : ''}
            </span>
            <span className={`shrink-0 text-xs ${product.available ? 'text-emerald-700' : 'text-muted-foreground'}`}>
              {product.available ? 'В наличии' : 'Наличие уточняется'}
            </span>
          </div>
        </div>
      </a>
    </article>
  )
}

function setMetadata(title: string, description: string, canonicalPath: string, imageUrl?: string, type = 'website') {
  document.title = title
  let descriptionElement = document.querySelector<HTMLMetaElement>('meta[name="description"]')
  if (!descriptionElement) {
    descriptionElement = document.createElement('meta')
    descriptionElement.name = 'description'
    document.head.append(descriptionElement)
  }
  descriptionElement.content = description

  let canonicalElement = document.querySelector<HTMLLinkElement>('link[rel="canonical"]')
  if (!canonicalElement) {
    canonicalElement = document.createElement('link')
    canonicalElement.rel = 'canonical'
    document.head.append(canonicalElement)
  }
  canonicalElement.href = `${window.location.origin}${canonicalPath}`

  const properties: Record<string, string> = {
    'og:title': title,
    'og:description': description,
    'og:type': type,
    'og:url': canonicalElement.href,
  }
  if (imageUrl) properties['og:image'] = imageUrl

  for (const [property, content] of Object.entries(properties)) {
    let element = document.querySelector<HTMLMetaElement>(`meta[property="${property}"]`)
    if (!element) {
      element = document.createElement('meta')
      element.setAttribute('property', property)
      document.head.append(element)
    }
    element.content = content
  }
}

async function recordStorefrontView(pageType: 'home' | 'category' | 'product', resourceKey: string): Promise<void> {
  const sessionKey = `aluxtorg-view:${pageType}:${resourceKey}`
  if (sessionStorage.getItem(sessionKey)) return

  try {
    await apiRequest<ViewResponse>('/storefront/views', {
      method: 'POST',
      body: JSON.stringify({ page_type: pageType, resource_key: resourceKey }),
    })
    sessionStorage.setItem(sessionKey, '1')
  } catch (requestError) {
    console.error('Не удалось записать просмотр страницы магазина.', requestError)
  }
}

export function CatalogPage({ user, settings }: { user: AuthUser | null; settings: StoreSettings | null }) {
  const [categories, setCategories] = useState<Category[]>([])
  const [banners, setBanners] = useState<Banner[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [product, setProduct] = useState<Product | null>(null)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [selectedVariantId, setSelectedVariantId] = useState<number | null>(null)
  const [quantity, setQuantity] = useState(1)
  const [cartMessage, setCartMessage] = useState<string | null>(null)
  const [cartError, setCartError] = useState<string | null>(null)

  const categoryId = Number(queryFromLocation.get('category_id')) || null
  const isHome = !routeSlug && !categorySlug && !categoryId && !queryFromLocation.get('q')
  const selectedCategory = categories.find((category) => category.id === categoryId || category.slug === categorySlug)
  const heroBanners = banners.filter((banner) => banner.placement === 'home_hero')
  const promoBanners = banners.filter((banner) => banner.placement === 'home_promo')
  const whatsapp = whatsappHref(settings?.whatsapp ?? null)
  const activeFilters = ['q', 'min_price', 'max_price', 'available', 'sort'].some((key) => queryFromLocation.has(key))
    || Boolean(categoryId || categorySlug)
  const clearFiltersHref = selectedCategory ? `/categories/${encodeURIComponent(selectedCategory.slug)}` : '/'
  const categoryOptions = useMemo(() => {
    const categoryById = new Map(categories.map((category) => [category.id, category]))
    return categories.map((category) => {
      let level = 0
      let parentId = category.parent_id
      while (parentId && categoryById.has(parentId) && level < categories.length) {
        level++
        parentId = categoryById.get(parentId)?.parent_id ?? null
      }
      return { ...category, level }
    })
  }, [categories])

  useEffect(() => {
    let active = true
    const load = async () => {
      setLoading(true)
      setError(null)
      try {
        const params = new URLSearchParams(window.location.search)
        params.set('per_page', '12')
        const productRequest = routeSlug
          ? apiRequest<ProductResponse>(`/products/${encodeURIComponent(routeSlug)}`)
          : Promise.resolve(null)
        const catalogRequest = !routeSlug && !categorySlug
          ? apiRequest<ApiCollection<Product>>(`/products?${params.toString()}`)
          : Promise.resolve(null)
        const [categoryResponse, bannerResponse, productResponse, initialCatalogResponse] = await Promise.all([
          apiRequest<ApiCollection<Category>>('/categories'),
          apiRequest<ApiCollection<Banner>>('/banners'),
          productRequest,
          catalogRequest,
        ])
        if (!active) return
        setCategories(categoryResponse.data)
        setBanners(bannerResponse.data)

        if (routeSlug) {
          if (!productResponse) throw new Error('Не удалось загрузить карточку товара.')
          if (!active) return
          setProduct(productResponse.data)
          setSelectedVariantId(productResponse.data.variants[0]?.id ?? null)
          setMetadata(
            productResponse.data.meta_title || `${productResponse.data.name} — Aluxtorg`,
            productResponse.data.meta_description || productResponse.data.description || `${productResponse.data.name} в интернет-магазине Aluxtorg. Характеристики, варианты и цена в тенге.`,
            `/products/${encodeURIComponent(productResponse.data.slug)}`,
            productResponse.data.images[0]?.secure_url,
            'product',
          )
          void recordStorefrontView('product', productResponse.data.slug)
          return
        }

        const categoryForMetadata = categoryResponse.data.find((category) => category.slug === categorySlug || category.id === categoryId)
        if (categorySlug && !categoryForMetadata) {
          setNotFound(true)
          return
        }
        if (categoryForMetadata) params.set('category_id', String(categoryForMetadata.id))
        const response = initialCatalogResponse ?? await apiRequest<ApiCollection<Product>>(`/products?${params.toString()}`)
        if (!active) return
        setProducts(response.data)
        setPage(response.meta?.current_page ?? 1)
        setLastPage(response.meta?.last_page ?? 1)
        setTotal(response.meta?.total ?? response.data.length)
        const pageType = categoryForMetadata ? 'category' : 'home'
        void recordStorefrontView(pageType, categoryForMetadata?.slug ?? 'home')
        setMetadata(
          categoryForMetadata?.meta_title || (categoryForMetadata
            ? `${categoryForMetadata.name} — Aluxtorg`
            : params.get('q') ? `Поиск: ${params.get('q')} — Aluxtorg` : 'Aluxtorg — текстиль и товары для дома в Казахстане'),
          categoryForMetadata?.meta_description || (categoryForMetadata
            ? `Выбирайте товары в категории «${categoryForMetadata.name}» интернет-магазина Aluxtorg. Цены в тенге и заказ онлайн.`
            : 'Интернет-магазин Aluxtorg в Казахстане: каталог текстиля и товаров для дома, характеристики и онлайн-заказ.'),
          categoryForMetadata ? `/categories/${encodeURIComponent(categoryForMetadata.slug)}` : '/',
        )
      } catch (requestError) {
        if (!active) return
        if (requestError instanceof ApiError && requestError.status === 404) {
          setNotFound(true)
        } else {
          setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить каталог. Проверьте подключение и повторите попытку.')
        }
      } finally {
        if (active) setLoading(false)
      }
    }

    void load()
    return () => {
      active = false
    }
  }, [])

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    const data = new FormData(event.currentTarget)
    const params = new URLSearchParams()
    const category = categories.find((item) => item.id === Number(data.get('category_id')))
    for (const key of ['q', 'min_price', 'max_price', 'available', 'sort']) {
      const value = String(data.get(key) ?? '').trim()
      if (value) params.set(key, value)
    }
    window.location.href = `${category ? `/categories/${encodeURIComponent(category.slug)}` : '/'}?${params.toString()}`
  }

  const goToPage = (nextPage: number) => {
    const params = new URLSearchParams(window.location.search)
    params.set('page', String(nextPage))
    window.location.href = `${window.location.pathname}?${params.toString()}`
  }

  if (notFound && !loading) return <NotFoundPage />

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0">
      {routeSlug && product && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'Product',
            name: product.name,
            brand: { '@type': 'Brand', name: 'Aluxtorg' },
            description: product.description ?? undefined,
            category: product.category.name,
            image: product.images.map((image) => image.secure_url),
            url: `${window.location.origin}/products/${encodeURIComponent(product.slug)}`,
            offers: product.variants.map((variant) => ({
              '@type': 'Offer',
              sku: variant.sku,
              priceCurrency: 'KZT',
              price: variant.price_kzt,
              availability: variant.available ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
              itemCondition: 'https://schema.org/NewCondition',
            })),
          }).replace(/</g, '\\u003c'),
        }} />
      )}
      {isHome && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{
          __html: JSON.stringify({
            '@context': 'https://schema.org',
            '@type': 'OnlineStore',
            name: 'Aluxtorg',
            url: window.location.origin,
            areaServed: { '@type': 'Country', name: 'Kazakhstan' },
            email: settings?.email ?? undefined,
            telephone: settings?.phone ?? undefined,
          }).replace(/</g, '\\u003c'),
        }} />
      )}
      <header className="border-b bg-card">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:gap-4 sm:px-6 sm:py-4 lg:px-8">
          <a className="flex items-center gap-2.5 text-lg font-semibold tracking-tight sm:gap-3" href="/" aria-label="Aluxtorg — на главную">
            <BrandMark className="h-10 w-[3.25rem] rounded-xl sm:h-11 sm:w-[3.6rem]" />
            <span>Aluxtorg</span>
          </a>
          <nav className="flex items-center gap-1 text-xs sm:gap-2 sm:text-sm">
            {user && ['manager', 'administrator', 'super_administrator'].includes(user.role) && (
              <a className="hidden rounded-lg px-3 py-2 text-muted-foreground hover:bg-muted hover:text-foreground sm:inline-flex" href="/admin/catalog">Управление каталогом</a>
            )}
            <a aria-label={user ? `Аккаунт ${user.name}` : 'Войти в аккаунт'} className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm font-medium hover:bg-muted" href="/account">
              <UserRound aria-hidden="true" size={16} /><span className="hidden max-w-36 truncate sm:inline">{user ? user.name : 'Войти'}</span>
            </a>
            <a aria-label="Открыть корзину" className="inline-flex h-10 items-center gap-2 rounded-xl bg-primary px-3 text-sm font-medium !text-white hover:opacity-90 sm:px-4" href="/cart">
              <ShoppingBag aria-hidden="true" size={16} /><span className="hidden sm:inline">Корзина</span>
            </a>
          </nav>
          <form className="order-3 flex w-full gap-2 md:order-none md:max-w-md" onSubmit={submitSearch} role="search">
            <label className="flex min-w-0 flex-1 items-center gap-2 rounded-xl border bg-background px-3">
              <Search className="size-4 text-muted-foreground" aria-hidden="true" />
              <input aria-label="Поиск по товарам и SKU" className="h-11 min-w-0 flex-1 bg-transparent text-sm outline-none" defaultValue={queryFromLocation.get('q') ?? ''} name="q" placeholder="Поиск товаров и SKU" />
            </label>
            <button className="shrink-0 rounded-xl bg-primary px-4 text-sm font-medium !text-white hover:opacity-90" type="submit">Найти</button>
          </form>
        </div>
      </header>

      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {new URLSearchParams(window.location.search).get('verified') === '1' && (
          <p className="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900" role="status">
            Почта подтверждена. Теперь можно войти в аккаунт.
          </p>
        )}

        {!routeSlug && (
          <>
            {isHome && (
              <section aria-label="Главное предложение магазина" className="mb-8">
                {heroBanners[0] ? (
                  <div className="group relative flex min-h-[340px] items-end overflow-hidden rounded-3xl bg-[#315c4d] p-6 text-white shadow-sm sm:min-h-[400px] sm:p-10 lg:min-h-[460px] lg:p-14">
                    <img
                      alt={heroBanners[0].alt_text}
                      className="absolute inset-0 size-full object-cover transition-transform duration-700 group-hover:scale-[1.03]"
                      fetchPriority="high"
                      loading="eager"
                      src={heroBanners[0].secure_url}
                    />
                    <span aria-hidden="true" className="absolute inset-0 bg-gradient-to-r from-black/75 via-black/40 to-black/10" />
                    <span className="relative block max-w-2xl">
                      <span className="mb-3 inline-flex rounded-full border border-white/40 bg-white/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] backdrop-blur sm:text-xs">
                        Aluxtorg · интернет-магазин в Казахстане
                      </span>
                      <h1 className="block text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-5xl">
                        {heroBanners[0].title}
                      </h1>
                      <span className="mt-3 block max-w-xl text-sm leading-6 text-white/90 sm:mt-4 sm:text-base">
                        Выбирайте текстиль и товары для дома, сравнивайте характеристики и оформляйте заказ онлайн.
                      </span>
                      <span className="mt-6 flex flex-wrap gap-3">
                        <a className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold text-[#24483b] transition-transform group-hover:translate-x-1" href={heroBanners[0].destination_url || '#catalog'}>
                          Перейти в каталог <ArrowRight aria-hidden="true" className="size-4" />
                        </a>
                        {whatsapp && (
                          <a className="inline-flex min-h-11 items-center rounded-xl border border-white/60 bg-black/15 px-5 text-sm font-semibold !text-white hover:bg-black/25" href={whatsapp} rel="noreferrer" target="_blank">
                            Связаться в WhatsApp
                          </a>
                        )}
                      </span>
                    </span>
                  </div>
                ) : (
                  <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#24483b] via-[#315c4d] to-[#8a9e85] px-6 py-10 text-white sm:px-10 sm:py-14 lg:px-14">
                    <p className="text-xs font-semibold uppercase tracking-[0.16em] text-white/75">Aluxtorg · интернет-магазин в Казахстане</p>
                    <h1 className="mt-3 max-w-2xl text-3xl font-semibold leading-tight tracking-tight sm:text-4xl lg:text-5xl">Текстиль и товары для дома — выбирайте онлайн</h1>
                    <p className="mt-4 max-w-xl text-sm leading-6 text-white/85 sm:text-base">Изучайте характеристики и варианты товаров, добавляйте покупки в корзину и оформляйте заказ.</p>
                    <div className="mt-6 flex flex-wrap gap-3">
                      <a className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-white px-5 text-sm font-semibold text-[#24483b]" href="#catalog">
                        Открыть каталог <ArrowRight aria-hidden="true" className="size-4" />
                      </a>
                      {whatsapp && <a className="inline-flex min-h-11 items-center rounded-xl border border-white/60 px-5 text-sm font-semibold !text-white hover:bg-white/10" href={whatsapp} rel="noreferrer" target="_blank">WhatsApp</a>}
                    </div>
                  </div>
                )}
              </section>
            )}

            {isHome && (
              <section aria-label="Преимущества магазина" className="mb-8 grid gap-3 sm:grid-cols-3">
                {[
                  ['Характеристики товаров', 'Сравнивайте варианты, единицы продажи, цену и наличие.'],
                  ['Заказ онлайн', 'Выберите нужные позиции и оформите заказ через личный кабинет.'],
                  ['Помощь менеджера', 'Если нужен совет — свяжитесь с магазином удобным способом.'],
                ].map(([title, description]) => (
                  <article className="rounded-2xl border bg-card p-4 sm:p-5" key={title}>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <p className="mt-2 text-sm leading-6 text-muted-foreground">{description}</p>
                  </article>
                ))}
              </section>
            )}

            <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
              <div>
                <p className="mb-2 text-sm font-medium text-primary">{selectedCategory ? 'Категория' : 'Интернет-магазин'}</p>
                {isHome ? (
                  <h2 className="text-3xl font-semibold tracking-tight sm:text-4xl" id="catalog">Каталог товаров</h2>
                ) : (
                  <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{selectedCategory?.name ?? (queryFromLocation.get('q') ? 'Результаты поиска' : 'Каталог товаров')}</h1>
                )}
              </div>
              <p className="text-sm text-muted-foreground">{total} товаров</p>
            </div>

            {categoryOptions.length > 0 && (
              <nav aria-label="Категории товаров" className="-mx-4 mb-6 flex snap-x gap-2 overflow-x-auto px-4 pb-2 [scrollbar-width:thin] sm:mx-0 sm:mb-7 sm:px-0">
                <a className={`shrink-0 snap-start rounded-full border px-4 py-2 text-sm ${!categoryId && !categorySlug ? 'border-primary bg-accent text-accent-foreground' : 'bg-card hover:bg-muted'}`} href="/">Все категории</a>
                {categoryOptions.filter((category) => !category.parent_id).map((category) => (
                  <a className={`shrink-0 snap-start rounded-full border px-4 py-2 text-sm ${selectedCategory?.id === category.id ? 'border-primary bg-accent text-accent-foreground' : 'bg-card hover:bg-muted'}`} href={`/categories/${encodeURIComponent(category.slug)}`} key={category.id}>
                    {category.name}
                  </a>
                ))}
              </nav>
            )}

            <form className="mb-4 grid min-w-0 grid-cols-1 gap-3 rounded-2xl border bg-card p-3 sm:mb-7 sm:grid-cols-2 sm:p-4 xl:grid-cols-[minmax(180px,1fr)_160px_120px_120px_160px_minmax(250px,1fr)]" onSubmit={submitSearch}>
              <input aria-label="Фильтр по названию или SKU" className="h-11 min-w-0 rounded-xl border bg-background px-3 text-sm outline-none focus:ring-2 focus:ring-ring/30 sm:col-span-2 xl:col-span-1" defaultValue={queryFromLocation.get('q') ?? ''} name="q" placeholder="Название или SKU" />
              <select aria-label="Категория" className="h-11 min-w-0 rounded-xl border bg-background px-3 text-sm sm:col-span-2 xl:col-span-1" defaultValue={selectedCategory?.id ?? ''} name="category_id">
                <option value="">Все категории</option>
                {categoryOptions.map((category) => <option key={category.id} value={category.id}>{`${'— '.repeat(category.level)}${category.name}`}</option>)}
              </select>
              <input aria-label="Цена от, ₸" className="h-11 min-w-0 rounded-xl border bg-background px-3 text-sm" defaultValue={queryFromLocation.get('min_price') ?? ''} min="0" name="min_price" placeholder="Цена от, ₸" type="number" />
              <input aria-label="Цена до, ₸" className="h-11 min-w-0 rounded-xl border bg-background px-3 text-sm" defaultValue={queryFromLocation.get('max_price') ?? ''} min="0" name="max_price" placeholder="Цена до, ₸" type="number" />
              <select aria-label="Наличие" className="h-11 min-w-0 rounded-xl border bg-background px-3 text-sm sm:col-span-2 xl:col-span-1" defaultValue={queryFromLocation.get('available') ?? ''} name="available">
                <option value="">Любое наличие</option>
                <option value="1">В наличии</option>
                <option value="0">Нет в наличии</option>
              </select>
              <div className="grid min-w-0 gap-2 sm:col-span-2 sm:grid-cols-[minmax(0,1fr)_auto] xl:col-span-1">
                <select aria-label="Сортировка" className="h-11 min-w-0 w-full rounded-xl border bg-background px-3 text-sm" defaultValue={queryFromLocation.get('sort') ?? 'relevance'} name="sort">
                  <option value="relevance">Сначала рекомендуемые</option>
                  <option value="name">По названию</option>
                  <option value="price_asc">Сначала дешевле</option>
                  <option value="price_desc">Сначала дороже</option>
                  <option value="newest">Сначала новые</option>
                </select>
                <button className="h-11 w-full rounded-xl bg-primary px-4 text-sm font-medium !text-white sm:w-auto" type="submit">Применить</button>
              </div>
            </form>
            {activeFilters && (
              <a className="mb-7 inline-flex min-h-10 items-center text-sm font-medium text-primary underline-offset-4 hover:underline" href={clearFiltersHref}>
                Сбросить фильтры
              </a>
            )}
          </>
        )}

        {routeSlug && product && (
          <section className="grid gap-8 lg:grid-cols-[1.1fr_0.9fr]">
            <div>
              <a className="mb-5 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground" href="/"><ArrowLeft className="size-4" /> Вернуться в каталог</a>
              <div className="grid gap-3 sm:grid-cols-2">
                {product.images.length > 0 ? product.images.map((image, index) => (
                  <img className="aspect-square size-full rounded-2xl border bg-card object-cover" fetchPriority={index === 0 ? 'high' : 'auto'} key={image.id} loading={index === 0 ? 'eager' : 'lazy'} src={image.secure_url} alt={image.alt_text} />
                )) : (
                  <div className="flex aspect-square items-center justify-center rounded-2xl border bg-card text-sm text-muted-foreground sm:col-span-2">Изображение скоро появится</div>
                )}
              </div>
            </div>
            <div className="lg:pt-12">
              <a className="mb-3 inline-block text-sm text-muted-foreground hover:text-primary" href={`/categories/${encodeURIComponent(product.category.slug)}`}>{product.category.name}</a>
              <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{product.name}</h1>
              {product.variants.length > 0 && (() => {
                const selected = product.variants.find((variant) => variant.id === selectedVariantId) ?? product.variants[0]
                return (
                  <div className="mt-6 space-y-5">
                    <div className="text-2xl font-semibold tabular-nums">{money.format(selected.price_kzt)} <span className="text-base font-normal text-muted-foreground">/ {selected.sale_unit}</span></div>
                    {product.variants.length > 1 && (
                      <label className="block max-w-sm">
                        <span className="mb-2 block text-sm font-medium">Вариант товара</span>
                        <select className="h-12 w-full rounded-xl border bg-card px-3 text-sm" onChange={(event) => setSelectedVariantId(Number(event.target.value))} value={selected.id}>
                          {product.variants.map((variant) => <option key={variant.id} value={variant.id}>{variant.name} — {money.format(variant.price_kzt)}</option>)}
                        </select>
                      </label>
                    )}
                    <div className={`inline-flex rounded-full px-3 py-1.5 text-sm ${selected.available ? 'bg-emerald-50 text-emerald-800' : 'bg-muted text-muted-foreground'}`}>
                      {selected.available ? 'В наличии' : 'Наличие уточнит менеджер'}
                    </div>
                    <p className="text-sm text-muted-foreground">Артикул: {selected.sku}</p>
                    {Object.entries(selected.attributes).length > 0 && (
                      <section aria-labelledby="product-characteristics">
                        <h2 className="mb-3 text-base font-semibold" id="product-characteristics">Характеристики</h2>
                        <dl className="grid max-w-md grid-cols-2 gap-x-4 gap-y-3 rounded-2xl border bg-card p-4 text-sm">
                          {Object.entries(selected.attributes).map(([name, value]) => (
                            <div className="border-b pb-2 last:border-b-0" key={name}>
                              <dt className="text-muted-foreground">{name}</dt>
                              <dd className="mt-1 font-medium">{value}</dd>
                            </div>
                          ))}
                        </dl>
                      </section>
                    )}
                    <div className="flex flex-wrap items-end gap-3">
                      <label className="block text-sm font-medium">
                        Количество
                        <input
                          className="mt-2 h-11 w-24 rounded-xl border bg-card px-3 text-center"
                          max="999"
                          min="1"
                          onChange={(event) => {
                            const nextQuantity = Number(event.target.value)
                            if (Number.isInteger(nextQuantity) && nextQuantity >= 1 && nextQuantity <= 999) setQuantity(nextQuantity)
                          }}
                          type="number"
                          value={quantity}
                        />
                      </label>
                      <button
                        className="inline-flex h-11 items-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium !text-white disabled:cursor-not-allowed disabled:opacity-50"
                        disabled={!selected.available}
                        onClick={() => {
                          try {
                            addCartEntry({
                              variantId: selected.id,
                              productSlug: product.slug,
                              productName: product.name,
                              variantName: selected.name,
                              sku: selected.sku,
                              saleUnit: selected.sale_unit,
                              priceKzt: selected.price_kzt,
                              imageUrl: product.images[0]?.secure_url ?? null,
                            }, quantity)
                            setCartError(null)
                            setCartMessage('Добавлено в корзину.')
                          } catch (requestError) {
                            setCartError(requestError instanceof Error ? requestError.message : 'Не удалось добавить товар в корзину.')
                            setCartMessage(null)
                          }
                        }}
                        type="button"
                      >
                        <ShoppingBag aria-hidden="true" size={16} /> В корзину
                      </button>
                    </div>
                    {(cartMessage || cartError) && (
                      <p className={`text-sm ${cartError ? 'text-destructive' : 'text-emerald-700'}`} role={cartError ? 'alert' : 'status'}>
                        {cartError ?? cartMessage}
                        {cartMessage && <a className="ml-2 font-medium underline" href="/cart">Открыть корзину</a>}
                      </p>
                    )}
                  </div>
                )
              })()}
              {product.description && (
                <section className="mt-8 border-t pt-6">
                  <h2 className="text-lg font-semibold">Описание товара</h2>
                  <div className="mt-3 whitespace-pre-line text-sm leading-7 text-muted-foreground">{product.description}</div>
                </section>
              )}
            </div>
          </section>
        )}

        {!routeSlug && (
          <>
            {promoBanners.length > 0 && (
              <section aria-label="Информация магазина" className="mb-8 grid gap-4 sm:grid-cols-2">
                {promoBanners.map((banner) => (
                  <a className="group flex items-center gap-4 overflow-hidden rounded-2xl border bg-card p-3" href={banner.destination_url || '#catalog'} key={banner.id}>
                    <img className="size-20 rounded-xl object-cover" src={banner.secure_url} alt={banner.alt_text} loading="lazy" />
                    <span className="font-medium">{banner.title}</span>
                    <ArrowRight className="ml-auto size-4 text-muted-foreground transition group-hover:translate-x-1" aria-hidden="true" />
                  </a>
                ))}
              </section>
            )}
            <section id="catalog" aria-label="Товары">
              {loading ? (
                <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4" aria-live="polite">
                  {Array.from({ length: 8 }, (_, index) => <div className="aspect-[4/5] animate-pulse rounded-2xl bg-muted" key={index} />)}
                </div>
              ) : error ? (
                <div className="rounded-2xl border border-destructive/30 bg-card p-8 text-center">
                  <p className="font-medium">Каталог пока недоступен</p>
                  <p className="mt-2 text-sm text-muted-foreground">{error}</p>
                </div>
              ) : products.length === 0 ? (
                <div className="rounded-2xl border bg-card p-12 text-center">
                  <h2 className="text-lg font-semibold">Товары не найдены</h2>
                  <p className="mt-2 text-sm text-muted-foreground">Измените запрос или выберите другую категорию.</p>
                  <a className="mt-5 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/">Сбросить фильтры</a>
                </div>
              ) : (
                <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4">
                  {products.map((item) => <ProductCard key={item.id} product={item} />)}
                </div>
              )}
            </section>
            {lastPage > 1 && (
              <nav aria-label="Страницы каталога" className="mt-8 flex items-center justify-center gap-4">
                <button className="inline-flex h-10 items-center gap-2 rounded-xl border bg-card px-4 text-sm disabled:opacity-40" disabled={page <= 1} onClick={() => goToPage(page - 1)} type="button"><ArrowLeft className="size-4" /> Назад</button>
                <span className="text-sm text-muted-foreground">Страница {page} из {lastPage}</span>
                <button className="inline-flex h-10 items-center gap-2 rounded-xl border bg-card px-4 text-sm disabled:opacity-40" disabled={page >= lastPage} onClick={() => goToPage(page + 1)} type="button">Далее <ArrowRight className="size-4" /></button>
              </nav>
            )}
          </>
        )}

      </div>
    </main>
  )
}

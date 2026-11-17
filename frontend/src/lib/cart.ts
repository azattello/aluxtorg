import { ApiError, apiRequest } from '@/lib/api'

const storageKey = 'ecommerce.cart.v1'

export interface CartEntry {
  variantId: number
  productSlug: string
  productName: string
  variantName: string
  sku: string
  saleUnit: string
  priceKzt: number
  quantity: number
  imageUrl: string | null
}

export interface CartProductVariant {
  id: number
  sku: string
  name: string
  sale_unit: string
  price_kzt: number
  available: boolean
}

export interface CartProduct {
  id: number
  slug: string
  name: string
  images: { secure_url: string; alt_text: string }[]
  variants: CartProductVariant[]
}

export interface CurrentCartEntry {
  entry: CartEntry
  product: CartProduct | null
  variant: CartProductVariant | null
}

function isCartEntry(value: unknown): value is CartEntry {
  if (typeof value !== 'object' || value === null) return false
  const entry = value as Record<string, unknown>

  return (
    Number.isInteger(entry.variantId) &&
    typeof entry.productSlug === 'string' &&
    typeof entry.productName === 'string' &&
    typeof entry.variantName === 'string' &&
    typeof entry.sku === 'string' &&
    typeof entry.saleUnit === 'string' &&
    Number.isInteger(entry.priceKzt) &&
    Number.isInteger(entry.quantity) &&
    (entry.quantity as number) >= 1 &&
    (entry.quantity as number) <= 999 &&
    (entry.imageUrl === null || typeof entry.imageUrl === 'string')
  )
}

export function readCart(): CartEntry[] {
  let serialized: string | null
  try {
    serialized = localStorage.getItem(storageKey)
  } catch {
    throw new Error('Не удалось прочитать корзину в этом браузере.')
  }

  if (!serialized) return []

  let parsed: unknown
  try {
    parsed = JSON.parse(serialized)
  } catch {
    throw new Error('Данные корзины повреждены. Очистите данные корзины и попробуйте снова.')
  }

  if (!Array.isArray(parsed) || !parsed.every(isCartEntry)) {
    throw new Error('Формат сохранённой корзины не распознан.')
  }

  return parsed
}

function writeCart(entries: CartEntry[]): void {
  try {
    localStorage.setItem(storageKey, JSON.stringify(entries))
    window.dispatchEvent(new CustomEvent('ecommerce:cart-updated'))
  } catch {
    throw new Error('Не удалось сохранить корзину. Проверьте доступное место в браузере.')
  }
}

export function addCartEntry(entry: Omit<CartEntry, 'quantity'>, quantity: number): void {
  const cart = readCart()
  const existing = cart.find((item) => item.variantId === entry.variantId)
  const nextQuantity = (existing?.quantity ?? 0) + quantity

  if (!Number.isInteger(quantity) || quantity < 1 || nextQuantity > 999) {
    throw new Error('Количество товара должно быть от 1 до 999.')
  }

  if (existing) {
    existing.quantity = nextQuantity
    Object.assign(existing, entry)
  } else {
    cart.push({ ...entry, quantity })
  }

  writeCart(cart)
}

export function setCartEntryQuantity(variantId: number, quantity: number): void {
  const cart = readCart()
  const entry = cart.find((item) => item.variantId === variantId)

  if (!entry || !Number.isInteger(quantity) || quantity < 1 || quantity > 999) {
    throw new Error('Не удалось изменить количество товара в корзине.')
  }

  entry.quantity = quantity
  writeCart(cart)
}

export function removeCartEntry(variantId: number): void {
  writeCart(readCart().filter((entry) => entry.variantId !== variantId))
}

export function clearCart(): void {
  writeCart([])
}

export async function loadCurrentCart(): Promise<CurrentCartEntry[]> {
  const entries = readCart()
  const slugs = [...new Set(entries.map((entry) => entry.productSlug))]
  const products = await Promise.all(
    slugs.map(async (slug) => {
      try {
        const response = await apiRequest<{ data: CartProduct }>(`/products/${encodeURIComponent(slug)}`)
        return response.data
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    }),
  )
  const productsBySlug = new Map(products.filter((product) => product !== null).map((product) => [product.slug, product]))

  return entries.map((entry) => {
    const product = productsBySlug.get(entry.productSlug) ?? null
    const variant = product?.variants.find((candidate) => candidate.id === entry.variantId) ?? null

    return { entry, product, variant }
  })
}

export function formatKzt(amount: number): string {
  return new Intl.NumberFormat('ru-KZ', {
    style: 'currency',
    currency: 'KZT',
    maximumFractionDigits: 0,
  }).format(amount)
}

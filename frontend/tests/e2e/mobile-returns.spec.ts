import { expect, test } from '@playwright/test'

const customer = {
  id: 2,
  name: 'QA Customer',
  email: 'qa.customer@example.test',
  phone: '+77010000000',
  role: 'customer',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
}

const manager = {
  ...customer,
  id: 3,
  name: 'QA Manager',
  email: 'qa.manager@example.test',
  role: 'manager',
}

test('mobile storefront tabs reflect a signed-in customer', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 })
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: { data: customer } }))

  await page.goto('/cart')

  const tabs = page.getByRole('navigation', { name: 'Основная навигация' })
  await expect(page.locator('header img[src="/aluxtorg-logo.jpg"]')).toBeVisible()
  await expect(tabs.locator('img[src="/apple-touch-icon.png"]')).toBeVisible()
  await expect(page.locator('link[rel="icon"]')).toHaveAttribute('href', '/favicon-32.png')
  await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveAttribute('href', '/apple-touch-icon.png')
  await expect(tabs.getByRole('link', { name: 'Каталог' })).toHaveAttribute('href', '/')
  await expect(tabs.getByRole('link', { name: 'Корзина' })).toHaveAttribute('aria-current', 'page')
  await expect(tabs.getByRole('link', { name: 'Заказы' })).toHaveAttribute('href', '/account#orders')
  await expect(tabs.getByRole('link', { name: 'Профиль' })).toHaveAttribute('href', '/account#profile')
})

test('mobile CRM tabs expose return management to a manager', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 })
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: { data: manager } }))
  await page.route('**/api/v1/admin/returns**', (route) => route.fulfill({
    json: {
      data: [{
        id: 8,
        status: 'submitted',
        reason: 'Не подошёл товар.',
        order: { id: 42, number: 'ORD-RETURN-42', customer_name: 'QA Customer', customer_phone: '+77010000000', customer_email: null },
        items: [],
        status_history: [],
        created_at: '2026-11-16T09:00:00Z',
      }],
      meta: { current_page: 1, last_page: 1, total: 1 },
    },
  }))

  await page.goto('/admin/returns')

  await expect(page.getByRole('link', { name: 'Aluxtorg — перейти в магазин' })).toBeVisible()
  const tabs = page.getByRole('navigation', { name: 'Основная навигация' })
  await expect(tabs.getByRole('link', { name: 'Возвраты' })).toHaveAttribute('aria-current', 'page')
  await expect(tabs.getByRole('link', { name: 'Заказы' })).toHaveAttribute('href', '/admin/orders')
  await expect(tabs.getByRole('link', { name: 'Склад' })).toHaveAttribute('href', '/admin/warehouse')
  await expect(tabs.getByRole('link', { name: 'Каталог' })).toHaveAttribute('href', '/admin/catalog')
  await expect(page.getByText('ORD-RETURN-42 · QA Customer')).toBeVisible()
  await page.getByRole('button', { name: 'Таблица' }).click()
  await expect(page.getByRole('table')).toBeVisible()
})

test('manager can switch the order list between cards and table on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 })
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: { data: manager } }))
  await page.route('**/api/v1/admin/orders**', (route) => {
    if (new URL(route.request().url()).pathname.endsWith('/assignees')) {
      return route.fulfill({ json: { data: [] } })
    }

    return route.fulfill({
      json: {
        data: [{
          id: 1,
          number: 'ORD-LIST-1',
          assigned_to: null,
          order_status: 'new',
          payment_status: 'unpaid',
          fulfillment_method: 'pickup',
          customer_name: 'QA Customer',
          customer_phone: '+77010000000',
          customer_email: null,
          delivery_city: null,
          delivery_street: null,
          delivery_building: null,
          delivery_apartment: null,
          delivery_instructions: null,
          subtotal_kzt: 2000,
          delivery_fee_kzt: 0,
          total_kzt: 2000,
          created_at: '2026-11-16T09:00:00Z',
          items: [],
        }],
        meta: { current_page: 1, last_page: 1, total: 1 },
      },
    })
  })

  await page.goto('/admin/orders')
  await expect(page.getByRole('button', { name: /ORD-LIST-1/ })).toBeVisible()
  await page.getByRole('button', { name: 'Таблица' }).click()
  await expect(page.getByRole('table')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Открыть заказ ORD-LIST-1' })).toBeVisible()
})

test('mobile storefront shows the eco-leather hero with readable actions above the tab bar', async ({ page }) => {
  const category = { id: 1, parent_id: null, name: 'Ткани', slug: 'tkani', meta_title: null, meta_description: null }
  await page.setViewportSize({ width: 360, height: 800 })
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: { data: customer } }))
  await page.route('**/api/v1/categories', (route) => route.fulfill({ json: { data: [category] } }))
  await page.route('**/api/v1/banners', (route) => route.fulfill({
    json: { data: [{ id: 1, title: 'Экокожа для мебели', placement: 'home_hero', secure_url: 'https://example.test/hero.jpg', alt_text: 'Демо-баннер', destination_url: '/categories/tkani' }] },
  }))
  await page.route('**/api/v1/products**', (route) => {
    const filtered = new URL(route.request().url()).searchParams.has('min_price')
    return route.fulfill({
      json: {
        data: filtered ? [] : [{
          id: 1,
          category_id: 1,
          category,
          slug: 'demo-product',
          name: 'Демо-товар',
          description: null,
          is_published: true,
          meta_title: null,
          meta_description: null,
          min_price_kzt: 1000,
          max_price_kzt: 1000,
          available: true,
          images: [],
          variants: [],
        }],
        meta: { current_page: 1, last_page: 1, total: filtered ? 0 : 1 },
      },
    })
  })

  await page.goto('/?min_price=20000')
  await expect(page.getByRole('img', { name: 'Демо-баннер' })).toBeVisible()
  const heroAction = page.getByRole('link', { name: 'Перейти в каталог' })
  await expect(heroAction).toHaveCSS('color', 'rgb(36, 72, 59)')
  await expect(page.getByRole('heading', { name: 'Товары не найдены' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Товары' }).getByRole('link', { name: 'Сбросить фильтры' })).toHaveCSS('color', 'rgb(255, 255, 255)')
  const apply = page.getByRole('button', { name: 'Применить' })
  await apply.scrollIntoViewIfNeeded()

  const applyBounds = await apply.boundingBox()
  const tabsBounds = await page.getByRole('navigation', { name: 'Основная навигация' }).boundingBox()
  expect(applyBounds).not.toBeNull()
  expect(tabsBounds).not.toBeNull()
  expect(applyBounds!.y + applyBounds!.height).toBeLessThanOrEqual(tabsBounds!.y)
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
  }))
  expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport)
})

test('customer submits a whole-package return request from order details', async ({ page }) => {
  let submitted = false
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: { data: customer } }))
  await page.route('**/api/v1/orders/42/returns', async (route) => {
    submitted = true
    await route.fulfill({
      status: 201,
      json: {
        data: {
          id: 9,
          status: 'submitted',
          reason: 'Не подошёл товар.',
          items: [{ order_item_id: 501, product_name: 'Товар', variant_name: null, sale_unit: 'упаковка', quantity: 1 }],
          status_history: [{ from_status: null, to_status: 'submitted', created_at: '2026-11-16T09:00:00Z' }],
          created_at: '2026-11-16T09:00:00Z',
        },
      },
    })
  })
  await page.route('**/api/v1/orders/42', (route) => route.fulfill({
    json: {
      data: {
        id: 42,
        number: 'ORD-RETURN-42',
        order_status: 'completed',
        payment_status: 'unpaid',
        fulfillment_method: 'pickup',
        customer_name: customer.name,
        customer_phone: customer.phone,
        customer_email: customer.email,
        delivery_city: null,
        delivery_street: null,
        delivery_building: null,
        delivery_apartment: null,
        delivery_instructions: null,
        subtotal_kzt: 2000,
        delivery_fee_kzt: 0,
        total_kzt: 2000,
        created_at: '2026-11-01T09:00:00Z',
        can_request_return: true,
        return_deadline_at: '2026-11-30T09:00:00Z',
        return_requests: submitted ? [{
          id: 9,
          status: 'submitted',
          reason: 'Не подошёл товар.',
          items: [{ order_item_id: 501, product_name: 'Товар', variant_name: null, sale_unit: 'упаковка', quantity: 1 }],
          status_history: [{ from_status: null, to_status: 'submitted', created_at: '2026-11-16T09:00:00Z' }],
          created_at: '2026-11-16T09:00:00Z',
        }] : [],
        items: [{
          id: 501,
          product_name: 'Товар',
          variant_name: null,
          sku: 'SKU-RETURN',
          sale_unit: 'упаковка',
          is_returnable: true,
          remaining_returnable_quantity: submitted ? 1 : 2,
          quantity: 2,
          unit_price_kzt: 1000,
          line_total_kzt: 2000,
        }],
        status_history: [],
      },
    },
  }))

  await page.goto('/account/orders/42')
  await page.getByLabel('Количество упаковок для возврата: Товар').fill('1')
  await page.getByLabel('Причина возврата').fill('Не подошёл товар.')
  await page.getByLabel(/Подтверждаю: товар не использован/).check()
  await page.getByRole('button', { name: 'Отправить заявку на возврат' }).click()

  await expect(page.getByRole('status')).toContainText('Менеджер свяжется с вами в WhatsApp')
  await expect(page.getByText('Заявка №9')).toBeVisible()
  expect(submitted).toBe(true)
})

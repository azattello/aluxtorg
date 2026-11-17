import { expect, test } from '@playwright/test'

const superAdministrator = {
  id: 1,
  name: 'QA Super Administrator',
  email: 'qa.super@example.test',
  phone: null,
  role: 'super_administrator',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
}

const customer = {
  id: 2,
  name: 'QA Customer',
  email: 'qa.customer@example.test',
  phone: null,
  role: 'customer',
  email_verified_at: '2026-01-01T00:00:00.000000Z',
}

test('super administrator searches users, changes a role and gets a mobile-friendly view', async ({ page }) => {
  let assignedRole = customer.role
  let searchedFor: string | null = null
  let filteredRole: string | null = null
  let selectedSort: string | null = null

  await page.route('**/api/v1/auth/me', (route) => route.fulfill({
    json: { data: superAdministrator },
  }))
  await page.route('**/api/v1/admin/users**', async (route) => {
    if (route.request().method() === 'PATCH') {
      assignedRole = route.request().postDataJSON().role
      return route.fulfill({
        json: { data: { ...customer, role: assignedRole } },
      })
    }

    const params = new URL(route.request().url()).searchParams
    searchedFor = params.get('search')
    filteredRole = params.get('role')
    selectedSort = params.get('sort')
    return route.fulfill({
      json: {
        data: [{ ...customer, role: assignedRole }],
        meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
        links: { first: null, last: null, prev: null, next: null },
      },
    })
  })

  await page.setViewportSize({ width: 360, height: 900 })
  await page.goto('/admin/users')
  await expect(page.getByRole('heading', { name: 'Пользователи и роли' })).toBeVisible()

  const search = page.getByRole('searchbox', { name: 'Поиск по имени, email или телефону' })
  await search.fill(customer.email)
  const searchResponse = page.waitForResponse((response) =>
    response.url().includes('/api/v1/admin/users')
    && new URL(response.url()).searchParams.get('search') === customer.email,
  )
  await page.getByRole('button', { name: 'Найти' }).click()
  await searchResponse
  await expect(page.getByText('Всего пользователей: 1')).toBeVisible()
  expect(searchedFor).toBe(customer.email)

  const roleFilter = page.getByLabel('Фильтр по роли')
  const roleResponse = page.waitForResponse((response) =>
    response.url().includes('/api/v1/admin/users')
    && new URL(response.url()).searchParams.get('role') === 'manager',
  )
  await roleFilter.selectOption('manager')
  await roleResponse
  expect(filteredRole).toBe('manager')
  await page.getByLabel('Сортировка пользователей').selectOption('name_asc')
  await expect.poll(() => selectedSort).toBe('name_asc')
  await page.getByRole('button', { name: 'Таблица' }).click()
  await expect(page.getByRole('table')).toBeVisible()
  await page.getByRole('button', { name: 'Карточки' }).click()
  await expect(page.locator('article').first()).toBeVisible()

  await page.getByRole('button', { name: 'Таблица' }).click()
  await page.setViewportSize({ width: 1280, height: 900 })
  const sidebar = page.getByRole('navigation', { name: 'Разделы админки' })
  await expect(sidebar).toBeVisible()
  await expect(sidebar.getByRole('link', { name: 'Пользователи' })).toHaveAttribute('aria-current', 'page')
  await expect(sidebar.getByRole('link', { name: 'Пользователи' })).toHaveCSS('color', 'rgb(255, 255, 255)')
  await expect(sidebar.getByRole('link', { name: 'Возвраты' })).toBeVisible()
  const roleSelect = page.getByLabel(`Роль пользователя ${customer.email}`)
  await expect(roleSelect).toHaveValue('customer')
  await roleSelect.selectOption('manager')
  await page.getByRole('button', { name: 'Сохранить', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('обновлена: Менеджер')
  await expect(roleSelect).toHaveValue('manager')

  await page.setViewportSize({ width: 360, height: 900 })
  await expect(sidebar).toBeHidden()
  await expect(page.getByLabel('Роль', { exact: true })).toHaveValue('manager')
  const dimensions = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
  }))
  expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport)
})

test('non-super-administrator cannot access user management controls', async ({ page }) => {
  await page.route('**/api/v1/auth/me', (route) => route.fulfill({
    json: { data: { ...customer, role: 'administrator' } },
  }))

  await page.goto('/admin/users')

  await expect(page.getByRole('heading', { name: 'Доступ ограничен' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Перейти ко входу' })).toHaveAttribute('href', '/account')
})

import { Suspense, lazy, useEffect, useState } from 'react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { StoreFooter } from '@/components/StoreFooter'
import { MobileTabBar } from '@/components/MobileTabBar'
import { NotFoundPage } from '@/components/NotFoundPage'
import type { StoreSettings, StoreSettingsResponse } from '@/lib/store'

const AuthPage = lazy(() => import('@/components/AuthPage').then((module) => ({ default: module.AuthPage })))
const CatalogAdminPage = lazy(() => import('@/components/CatalogAdminPage').then((module) => ({ default: module.CatalogAdminPage })))
const CatalogPage = lazy(() => import('@/components/CatalogPage').then((module) => ({ default: module.CatalogPage })))
const CartPage = lazy(() => import('@/components/CartPage').then((module) => ({ default: module.CartPage })))
const CheckoutPage = lazy(() => import('@/components/CheckoutPage').then((module) => ({ default: module.CheckoutPage })))
const OrderConfirmationPage = lazy(() => import('@/components/CheckoutPage').then((module) => ({ default: module.OrderConfirmationPage })))
const ManagerOrdersPage = lazy(() => import('@/components/ManagerOrdersPage').then((module) => ({ default: module.ManagerOrdersPage })))
const WarehousePage = lazy(() => import('@/components/WarehousePage').then((module) => ({ default: module.WarehousePage })))
const CustomerOrderPage = lazy(() => import('@/components/CustomerOrderPage').then((module) => ({ default: module.CustomerOrderPage })))
const UserManagementPage = lazy(() => import('@/components/UserManagementPage').then((module) => ({ default: module.UserManagementPage })))
const ReturnManagementPage = lazy(() => import('@/components/ReturnManagementPage').then((module) => ({ default: module.ReturnManagementPage })))
const StoreSettingsPage = lazy(() => import('@/components/StoreSettingsPage').then((module) => ({ default: module.StoreSettingsPage })))
const AdminAnalyticsPage = lazy(() => import('@/components/AdminAnalyticsPage').then((module) => ({ default: module.AdminAnalyticsPage })))

interface UserResponse {
  data: AuthUser
}

export default function App() {
  const pathName = window.location.pathname.replace(/\/+$/, '') || '/'
  const [user, setUser] = useState<AuthUser | null>(null)
  const [ready, setReady] = useState(false)
  const [statusError, setStatusError] = useState<string | null>(null)
  const [storeSettings, setStoreSettings] = useState<StoreSettings | null>(null)
  const [storeSettingsError, setStoreSettingsError] = useState<string | null>(null)
  const [statusMessage, setStatusMessage] = useState<string | null>(
    new URLSearchParams(window.location.search).get('verified') === '1' ? 'Почта подтверждена. Теперь можно войти в аккаунт.' : null,
  )

  useEffect(() => {
    let active = true
    const needsCustomerOrder = /^\/account\/orders\/\d+$/.test(pathName)
    const needsSession = needsCustomerOrder || ['/account', '/reset-password', '/admin/catalog', '/admin/orders', '/admin/returns', '/admin/warehouse', '/admin/users', '/admin/settings', '/admin/analytics', '/checkout'].includes(pathName)

    if (!needsSession) {
      setReady(true)
    }

    apiRequest<UserResponse>('/auth/me')
      .then((response) => {
        if (active) setUser(response.data)
      })
      .catch((error: unknown) => {
        if (!(error instanceof ApiError) || error.status !== 401) {
          if (['/account', '/admin/catalog', '/admin/orders', '/admin/returns', '/admin/warehouse', '/admin/users', '/admin/settings', '/admin/analytics', '/checkout'].includes(pathName)) {
            setStatusError('Не удалось проверить сессию. Проверьте подключение к API и попробуйте ещё раз.')
          }
        }
      })
      .finally(() => {
        if (active && needsSession) setReady(true)
      })

    return () => {
      active = false
    }
  }, [])

  useEffect(() => {
    if (pathName.startsWith('/admin/')) return

    let active = true
    void apiRequest<StoreSettingsResponse>('/store-settings')
      .then((response) => {
        if (active) setStoreSettings(response.data)
      })
      .catch((requestError: unknown) => {
        if (active) {
          setStoreSettingsError(requestError instanceof ApiError
            ? requestError.message
            : 'Не удалось загрузить контактные данные магазина.')
        }
      })

    return () => {
      active = false
    }
  }, [])

  if (!ready) {
    return (
      <main className="flex min-h-screen items-center justify-center bg-background px-5 text-sm text-muted-foreground" aria-live="polite">
        Загружаем личный кабинет…
      </main>
    )
  }

  let content

  if (pathName === '/admin/catalog') {
    content = <CatalogAdminPage error={statusError} user={user} />
  } else if (pathName === '/admin/orders') {
    content = <ManagerOrdersPage error={statusError} user={user} />
  } else if (pathName === '/admin/returns') {
    content = <ReturnManagementPage error={statusError} user={user} />
  } else if (pathName === '/admin/warehouse') {
    content = <WarehousePage error={statusError} user={user} />
  } else if (pathName === '/admin/users') {
    content = <UserManagementPage error={statusError} user={user} />
  } else if (pathName === '/admin/settings') {
    content = <StoreSettingsPage error={statusError} user={user} />
  } else if (pathName === '/admin/analytics') {
    content = <AdminAnalyticsPage error={statusError} user={user} />
  } else if (pathName.startsWith('/account/orders/')) {
    content = <CustomerOrderPage user={user} />
  } else if (pathName === '/cart') {
    content = <CartPage />
  } else if (pathName === '/checkout') {
    content = <CheckoutPage error={statusError} user={user} />
  } else if (/^\/order-confirmation\/[^/]+$/.test(pathName)) {
    content = <OrderConfirmationPage />
  } else if (pathName === '/account' || pathName === '/reset-password') {
    content = (
      <AuthPage
        onAuthenticated={(authenticatedUser) => {
          setUser(authenticatedUser)
          setStatusError(null)
          setStatusMessage(null)
          const next = new URLSearchParams(window.location.search).get('next')
          if (authenticatedUser && next !== null && (next === '/checkout' || /^\/account\/orders\/\d+$/.test(next))) {
            window.location.assign(next)
          }
        }}
        onClearStatus={() => {
          setStatusError(null)
          setStatusMessage(null)
        }}
        statusError={statusError}
        statusMessage={statusMessage}
        user={user}
      />
    )
  } else if (pathName === '/' || /^\/(products|categories)\/[^/]+$/.test(pathName)) {
    content = <CatalogPage settings={storeSettings} user={user} />
  } else {
    content = <NotFoundPage />
  }

  return (
    <>
      <Suspense fallback={<main className="flex min-h-screen items-center justify-center bg-background px-5 text-sm text-muted-foreground" aria-live="polite">Загружаем страницу…</main>}>
        <div className="pb-[calc(4.5rem+env(safe-area-inset-bottom))] md:pb-0">
          {content}
          {!pathName.startsWith('/admin/') && <StoreFooter error={storeSettingsError} settings={storeSettings} />}
        </div>
      </Suspense>
      <MobileTabBar pathName={pathName} user={user} />
    </>
  )
}

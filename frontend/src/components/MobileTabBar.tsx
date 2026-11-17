import { useEffect, useState } from 'react'
import {
  Boxes,
  ChartNoAxesColumn,
  ClipboardList,
  LayoutGrid,
  RotateCcw,
  Settings2,
  ShoppingBasket,
  ShoppingCart,
  Store,
  UserRound,
  Users,
} from 'lucide-react'
import { BrandMark } from '@/components/BrandMark'
import type { AuthUser } from '@/components/AuthPage'

interface Tab {
  label: string
  href: string
  icon: typeof Store
  active: (pathName: string) => boolean
}

function isActivePath(pathName: string, href: string): boolean {
  return href === '/'
    ? pathName === '/' || pathName.startsWith('/products/') || pathName.startsWith('/categories/')
    : pathName === href
}

export function MobileTabBar({ user, pathName }: { user: AuthUser | null; pathName: string }) {
  const [hash, setHash] = useState(window.location.hash)
  useEffect(() => {
    const updateHash = () => setHash(window.location.hash)
    window.addEventListener('hashchange', updateHash)
    return () => window.removeEventListener('hashchange', updateHash)
  }, [])

  const isAdminArea = pathName.startsWith('/admin/')
  const isCrmStaff = user && ['manager', 'administrator', 'super_administrator'].includes(user.role)
  const isSuperAdmin = user?.role === 'super_administrator'
  const isAdministrator = user && ['administrator', 'super_administrator'].includes(user.role)
  const isWarehouseOperator = user?.role === 'warehouse_operator'

  let tabs: Tab[]
  if (isAdminArea && isCrmStaff) {
    tabs = [
      { label: 'Заказы', href: '/admin/orders', icon: ClipboardList, active: (path) => path === '/admin/orders' },
      { label: 'Возвраты', href: '/admin/returns', icon: RotateCcw, active: (path) => path === '/admin/returns' },
      { label: 'Склад', href: '/admin/warehouse', icon: Boxes, active: (path) => path === '/admin/warehouse' },
      { label: 'Каталог', href: '/admin/catalog', icon: LayoutGrid, active: (path) => path === '/admin/catalog' },
      isSuperAdmin
        ? { label: 'Польз.', href: '/admin/users', icon: Users, active: (path) => path === '/admin/users' }
        : { label: 'Профиль', href: '/account#profile', icon: UserRound, active: (path) => path === '/account' },
    ]
    if (isAdministrator) {
      tabs.push(
        { label: 'Стат.', href: '/admin/analytics', icon: ChartNoAxesColumn, active: (path) => path === '/admin/analytics' },
        { label: 'Настр.', href: '/admin/settings', icon: Settings2, active: (path) => path === '/admin/settings' },
      )
    }
  } else if (isAdminArea && isWarehouseOperator) {
    tabs = [
      { label: 'Склад', href: '/admin/warehouse', icon: Boxes, active: (path) => path === '/admin/warehouse' },
      { label: 'Магазин', href: '/', icon: Store, active: (path) => path === '/' },
      { label: 'Профиль', href: '/account#profile', icon: UserRound, active: (path) => path === '/account' },
    ]
  } else {
    tabs = [
      { label: 'Каталог', href: '/', icon: Store, active: (path) => isActivePath(path, '/') },
      { label: 'Корзина', href: '/cart', icon: ShoppingCart, active: (path) => path === '/cart' },
      { label: 'Заказы', href: '/account#orders', icon: ShoppingBasket, active: (path) => path.startsWith('/account/orders') || path === '/account' && hash === '#orders' },
      { label: user ? 'Профиль' : 'Войти', href: '/account#profile', icon: UserRound, active: (path) => path === '/account' && hash !== '#orders' },
    ]
  }

  return (
    <nav aria-label="Основная навигация" className="fixed inset-x-0 bottom-0 z-50 border-t bg-card/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_rgba(0,0,0,0.06)] backdrop-blur md:hidden">
      <div className="mx-auto grid min-h-16 max-w-xl" style={{ gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))` }}>
        {tabs.map(({ label, href, icon: Icon, active }) => {
          const current = active(pathName)
          return (
            <a
              aria-current={current ? 'page' : undefined}
              className={`flex min-w-0 flex-col items-center justify-center gap-1 px-1 py-2 text-[10px] font-medium leading-none transition-colors ${current ? 'text-primary' : 'text-muted-foreground hover:text-foreground'}`}
              href={href}
              key={href}
            >
              {Icon === Store
                ? <BrandMark className="size-6 rounded-md" variant="icon" />
                : <Icon aria-hidden="true" className="size-5" strokeWidth={current ? 2.4 : 1.8} />}
              <span className="max-w-full truncate">{label}</span>
            </a>
          )
        })}
      </div>
    </nav>
  )
}

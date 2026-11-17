import {
  Boxes,
  ChartNoAxesColumn,
  ClipboardList,
  LayoutGrid,
  RotateCcw,
  Settings2,
  Store,
  Users,
} from 'lucide-react'
import { BrandMark } from '@/components/BrandMark'
import type { AuthUser } from '@/components/AuthPage'

interface AdminSidebarProps {
  user: AuthUser
  pathName?: string
}

interface SidebarLink {
  label: string
  href: string
  icon: typeof ClipboardList
}

export function AdminSidebar({ user, pathName = window.location.pathname }: AdminSidebarProps) {
  const isCrmStaff = ['manager', 'administrator', 'super_administrator'].includes(user.role)
  const links: SidebarLink[] = []

  if (isCrmStaff) {
    links.push(
      { label: 'Заказы', href: '/admin/orders', icon: ClipboardList },
      { label: 'Возвраты', href: '/admin/returns', icon: RotateCcw },
    )
  }

  if (user.role !== 'customer') {
    links.push({ label: 'Склад', href: '/admin/warehouse', icon: Boxes })
  }

  if (isCrmStaff) {
    links.push({ label: 'Каталог', href: '/admin/catalog', icon: LayoutGrid })
  }

  if (['administrator', 'super_administrator'].includes(user.role)) {
    links.push(
      { label: 'Статистика', href: '/admin/analytics', icon: ChartNoAxesColumn },
      { label: 'Контакты магазина', href: '/admin/settings', icon: Settings2 },
    )
  }

  if (user.role === 'super_administrator') {
    links.push({ label: 'Пользователи', href: '/admin/users', icon: Users })
  }

  return (
    <>
      <a className="sticky top-0 z-40 flex h-14 shrink-0 items-center gap-2 border-b bg-card px-4 lg:hidden" href="/" aria-label="Aluxtorg — перейти в магазин">
        <BrandMark className="size-9" variant="icon" />
        <span className="text-sm font-semibold">Aluxtorg</span>
        <span className="ml-auto text-xs text-muted-foreground">Панель управления</span>
      </a>
      <aside className="fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r bg-card lg:flex">
        <a className="flex h-20 items-center gap-3 border-b px-6" href="/" aria-label="Aluxtorg — перейти в магазин">
          <BrandMark className="h-10 w-[3.25rem] rounded-xl" />
          <span className="min-w-0">
            <span className="block truncate text-base font-semibold">Aluxtorg</span>
            <span className="mt-0.5 block text-xs text-muted-foreground">Панель управления</span>
          </span>
        </a>

        <nav aria-label="Разделы админки" className="flex-1 space-y-1 overflow-y-auto px-3 py-6">
          <p className="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            Рабочее пространство
          </p>
          {links.map(({ label, href, icon: Icon }) => {
            const current = pathName === href
            return (
              <a
                aria-current={current ? 'page' : undefined}
                className={`flex h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium transition-colors ${
                  current
                    ? 'bg-primary !text-white shadow-sm'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                }`}
                href={href}
                key={href}
              >
                <Icon aria-hidden="true" className="size-[18px] shrink-0" />
                <span>{label}</span>
              </a>
            )
          })}
        </nav>

        <div className="space-y-3 border-t p-4">
          <a className="flex h-10 items-center gap-3 rounded-xl px-3 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" href="/">
            <Store aria-hidden="true" className="size-[18px] shrink-0" />
            <span>Перейти в магазин</span>
          </a>
          <div className="flex min-w-0 items-center gap-3 rounded-xl bg-muted/60 p-3">
            <span aria-hidden="true" className="flex size-9 shrink-0 items-center justify-center rounded-full bg-background text-sm font-semibold text-primary">
              {user.name.trim().charAt(0).toLocaleUpperCase()}
            </span>
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium">{user.name}</span>
              <span className="mt-0.5 block truncate text-xs text-muted-foreground">{user.role.replaceAll('_', ' ')}</span>
            </span>
          </div>
        </div>
      </aside>
    </>
  )
}

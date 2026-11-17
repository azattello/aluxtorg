import { useEffect, useState, type FormEvent } from 'react'
import { ChevronLeft, ChevronRight, RefreshCw } from 'lucide-react'
import { ApiError, apiRequest } from '@/lib/api'
import type { AuthUser } from '@/components/AuthPage'
import { AdminSidebar } from '@/components/AdminSidebar'
import { ViewModeToggle, type ViewMode } from '@/components/ViewModeToggle'

type UserRole = 'customer' | 'manager' | 'warehouse_operator' | 'administrator' | 'super_administrator'

interface ManagedUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  role: UserRole
  email_verified_at: string | null
}

interface UsersPageResponse {
  data: ManagedUser[]
  meta: {
    current_page: number
    last_page: number
    total: number
  }
}

interface UserResponse {
  data: ManagedUser
}

const roles: { value: UserRole; label: string }[] = [
  { value: 'customer', label: 'Покупатель' },
  { value: 'manager', label: 'Менеджер' },
  { value: 'warehouse_operator', label: 'Оператор склада' },
  { value: 'administrator', label: 'Администратор' },
  { value: 'super_administrator', label: 'Супер-администратор' },
]

function roleLabel(role: UserRole): string {
  return roles.find((option) => option.value === role)?.label ?? role
}

function isUserRole(value: string): value is UserRole {
  return roles.some((role) => role.value === value)
}

export function UserManagementPage({ user, error: sessionError }: { user: AuthUser | null; error?: string | null }) {
  const [users, setUsers] = useState<ManagedUser[]>([])
  const [draftRoles, setDraftRoles] = useState<Record<number, UserRole>>({})
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [sort, setSort] = useState('newest')
  const [viewMode, setViewMode] = useState<ViewMode>('cards')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [busyUserId, setBusyUserId] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const canManageUserRoles = user?.role === 'super_administrator'

  const loadUsers = async () => {
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams({ page: String(page) })
      if (query) params.set('search', query)
      if (roleFilter) params.set('role', roleFilter)
      params.set('sort', sort)
      const response = await apiRequest<UsersPageResponse>(`/admin/users?${params.toString()}`)
      setUsers(response.data)
      setLastPage(response.meta.last_page)
      setTotal(response.meta.total)
      setDraftRoles({})
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось загрузить пользователей.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (canManageUserRoles) void loadUsers()
  }, [canManageUserRoles, page, query, roleFilter, sort])

  const submitSearch = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setPage(1)
    setQuery(search.trim())
  }

  const updateRole = async (target: ManagedUser) => {
    const role = draftRoles[target.id] ?? target.role
    if (role === target.role) return

    setBusyUserId(target.id)
    setError(null)
    setMessage(null)
    try {
      const response = await apiRequest<UserResponse>(`/admin/users/${target.id}/role`, {
        method: 'PATCH',
        body: JSON.stringify({ role }),
      })
      setUsers((current) => current.map((item) => item.id === target.id ? response.data : item))
      setDraftRoles((current) => {
        const next = { ...current }
        delete next[target.id]
        return next
      })
      setMessage(`Роль пользователя ${response.data.email ?? response.data.phone ?? response.data.name} обновлена: ${roleLabel(response.data.role)}.`)
    } catch (requestError) {
      setError(requestError instanceof ApiError ? requestError.message : 'Не удалось изменить роль пользователя.')
    } finally {
      setBusyUserId(null)
    }
  }

  if (!canManageUserRoles) {
    return (
      <main className="min-h-screen bg-background px-4 py-16 text-center">
        <h1 className="text-2xl font-semibold">Доступ ограничен</h1>
        <p className="mt-3 text-sm text-muted-foreground">
          {sessionError ?? (user ? 'Управлять пользователями может только супер-администратор.' : 'Войдите под аккаунтом супер-администратора.')}
        </p>
        <a className="mt-6 inline-flex rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/account">Перейти ко входу</a>
      </main>
    )
  }

  return (
    <main className="min-h-screen bg-background pb-24 text-foreground md:pb-0 lg:pl-64">
      <AdminSidebar user={user} />
      <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div className="mb-7 flex flex-wrap items-end justify-between gap-4">
          <div>
            <p className="text-sm font-medium text-primary">Рабочее пространство</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-tight">Пользователи и роли</h1>
            <p className="mt-2 text-sm text-muted-foreground">Назначайте роли аккаунтам. Каждое изменение записывается в аудит.</p>
          </div>
          <button className="inline-flex h-10 items-center gap-2 rounded-xl border px-3 text-sm hover:bg-muted" disabled={loading} onClick={() => void loadUsers()} type="button">
            <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} /> Обновить
          </button>
        </div>

        {(error || sessionError || message) && (
          <div className={`mb-5 rounded-xl border px-4 py-3 text-sm ${error || sessionError ? 'border-destructive/30 bg-destructive/5 text-destructive' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`} role={error || sessionError ? 'alert' : 'status'}>
            {error ?? sessionError ?? message}
          </div>
        )}

        <section className="rounded-2xl border bg-card p-4 sm:p-6">
          <form className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(220px,1fr)_200px_200px_auto_auto]" onSubmit={submitSearch}>
            <label className="sr-only" htmlFor="user-search">Поиск по имени, email или телефону</label>
            <input
              autoComplete="off"
              className="h-10 min-w-0 flex-1 rounded-xl border bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
              id="user-search"
              maxLength={100}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Имя, email или телефон"
              type="search"
              value={search}
            />
            <label className="sr-only" htmlFor="user-role-filter">Фильтр по роли</label>
            <select className="h-10 min-w-0 rounded-xl border bg-background px-3 text-sm" id="user-role-filter" onChange={(event) => { setPage(1); setRoleFilter(event.target.value) }} value={roleFilter}>
              <option value="">Все роли</option>
              {roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
            </select>
            <label className="sr-only" htmlFor="user-sort">Сортировка пользователей</label>
            <select className="h-10 min-w-0 rounded-xl border bg-background px-3 text-sm" id="user-sort" onChange={(event) => { setPage(1); setSort(event.target.value) }} value={sort}>
              <option value="newest">Сначала новые</option>
              <option value="oldest">Сначала старые</option>
              <option value="name_asc">По имени А—Я</option>
              <option value="name_desc">По имени Я—А</option>
              <option value="email_asc">По email А—Я</option>
              <option value="email_desc">По email Я—А</option>
            </select>
            <button className="h-10 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground hover:opacity-90" type="submit">Найти</button>
            <p className="self-center text-sm text-muted-foreground">Всего пользователей: {total}</p>
          </form>

          <div className="mb-4 flex justify-end">
            <ViewModeToggle onChange={setViewMode} value={viewMode} />
          </div>

          <div className={viewMode === 'cards' ? 'grid gap-3' : 'hidden'}>
            {users.map((target) => {
              const selectedRole = draftRoles[target.id] ?? target.role
              const busy = busyUserId === target.id
              return (
                <article className="rounded-xl border p-4" key={target.id}>
                  <div className="font-medium">{target.name}</div>
                  <div className="break-all text-sm text-muted-foreground">{target.email ?? 'Email не указан'}</div>
                  <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                    <dt className="text-muted-foreground">Телефон</dt>
                    <dd>{target.phone ?? '—'}</dd>
                    <dt className="text-muted-foreground">Почта</dt>
                    <dd>{!target.email ? 'Не указана' : target.email_verified_at ? 'Подтверждена' : 'Не подтверждена'}</dd>
                  </dl>
                  <label className="mt-4 block text-sm font-medium" htmlFor={`mobile-role-${target.id}`}>Роль</label>
                  <select
                    className="mt-1 h-10 w-full rounded-lg border bg-background px-2 text-sm"
                    disabled={busy}
                    id={`mobile-role-${target.id}`}
                    onChange={(event) => {
                      const value = event.target.value
                      if (isUserRole(value)) {
                        setDraftRoles((current) => ({ ...current, [target.id]: value }))
                      }
                    }}
                    value={selectedRole}
                  >
                    {roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                  </select>
                  <button
                    className="mt-3 h-10 w-full rounded-lg border px-3 text-sm font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                    disabled={busy || selectedRole === target.role || busyUserId !== null}
                    onClick={() => void updateRole(target)}
                    type="button"
                  >
                    {busy ? 'Сохраняем…' : 'Сохранить'}
                  </button>
                </article>
              )
            })}
          </div>

          <div className={viewMode === 'table' ? 'overflow-x-auto' : 'hidden'}>
          <table className="w-full min-w-[760px] table-fixed text-left text-sm">
              <thead className="border-b text-muted-foreground">
                <tr>
                  <th className="px-3 py-3 font-medium" scope="col">Пользователь</th>
                  <th className="px-3 py-3 font-medium" scope="col">Телефон</th>
                  <th className="px-3 py-3 font-medium" scope="col">Почта</th>
                  <th className="px-3 py-3 font-medium" scope="col">Роль</th>
                  <th aria-label="Действие" className="px-3 py-3 font-medium" scope="col" />
                </tr>
              </thead>
              <tbody className="divide-y">
                {users.map((target) => {
                  const selectedRole = draftRoles[target.id] ?? target.role
                  const busy = busyUserId === target.id
                  return (
                    <tr key={target.id}>
                      <td className="px-3 py-4">
                        <div className="font-medium">{target.name}</div>
                        <div className="text-muted-foreground">{target.email ?? 'Email не указан'}</div>
                      </td>
                      <td className="px-3 py-4 text-muted-foreground">{target.phone ?? '—'}</td>
                      <td className="px-3 py-4">{!target.email ? 'Не указана' : target.email_verified_at ? 'Подтверждена' : 'Не подтверждена'}</td>
                      <td className="px-3 py-4">
                        <select
                          aria-label={`Роль пользователя ${target.email ?? target.phone ?? target.name}`}
                          className="h-9 w-full rounded-lg border bg-background px-2 text-sm"
                          disabled={busy}
                          id={`role-${target.id}`}
                          onChange={(event) => {
                            const value = event.target.value
                            if (isUserRole(value)) {
                              setDraftRoles((current) => ({ ...current, [target.id]: value }))
                            }
                          }}
                          value={selectedRole}
                        >
                          {roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                        </select>
                      </td>
                      <td className="px-3 py-4 text-right">
                        <button
                          className="h-9 rounded-lg border px-3 text-sm font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                          disabled={busy || selectedRole === target.role || busyUserId !== null}
                          onClick={() => void updateRole(target)}
                          type="button"
                        >
                          {busy ? 'Сохраняем…' : 'Сохранить'}
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
          {!loading && users.length === 0 && <p className="py-10 text-center text-sm text-muted-foreground">Пользователи не найдены.</p>}
          {loading && <p className="py-10 text-center text-sm text-muted-foreground" aria-live="polite">Загружаем пользователей…</p>}

          <nav aria-label="Страницы пользователей" className="mt-5 flex items-center justify-between border-t pt-4">
            <span className="text-sm text-muted-foreground">Страница {page} из {lastPage}</span>
            <div className="flex gap-2">
              <button
                aria-label="Предыдущая страница"
                className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-muted disabled:opacity-50"
                disabled={loading || page <= 1}
                onClick={() => setPage((current) => current - 1)}
                type="button"
              >
                <ChevronLeft className="size-4" />
              </button>
              <button
                aria-label="Следующая страница"
                className="inline-flex size-9 items-center justify-center rounded-lg border hover:bg-muted disabled:opacity-50"
                disabled={loading || page >= lastPage}
                onClick={() => setPage((current) => current + 1)}
                type="button"
              >
                <ChevronRight className="size-4" />
              </button>
            </div>
          </nav>
        </section>
      </div>
    </main>
  )
}

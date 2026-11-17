import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { ArrowLeft, ArrowRight, Check, KeyRound, LockKeyhole, Mail, Phone, ShieldCheck, UserRound } from 'lucide-react'
import { apiRequest, prepareSanctumSession } from '@/lib/api'
import { BrandMark } from '@/components/BrandMark'
import { Button } from '@/components/ui/button'

export interface AuthUser {
  id: number
  name: string
  email: string | null
  phone: string | null
  role: string
  email_verified_at: string | null
}

interface AuthPageProps {
  user: AuthUser | null
  onAuthenticated: (user: AuthUser | null) => void
  statusMessage?: string | null
  statusError?: string | null
  onClearStatus: () => void
}

type AuthMode = 'login' | 'register' | 'forgot' | 'reset'

interface MessageResponse {
  message: string
}

interface UserResponse {
  data: AuthUser
}

interface CustomerOrder {
  id: number
  number: string
  order_status: string
  payment_status: string
  fulfillment_method: 'delivery' | 'pickup'
  subtotal_kzt: number
  delivery_fee_kzt: number | null
  total_kzt: number | null
  created_at: string | null
  items: { product_name: string; variant_name: string | null; quantity: number; line_total_kzt: number }[]
}

interface OrdersResponse {
  data: CustomerOrder[]
}

const roleLabels: Record<string, string> = {
  customer: 'Покупатель',
  manager: 'Менеджер',
  warehouse_operator: 'Складской оператор',
  administrator: 'Администратор',
  super_administrator: 'Супер-администратор',
}

const orderStatusLabels: Record<string, string> = {
  new: 'Новый',
  confirmed: 'Подтверждён',
  preparing: 'Собирается',
  ready_for_pickup: 'Готов к самовывозу',
  shipped: 'Отправлен',
  completed: 'Завершён',
  cancelled: 'Отменён',
}

const paymentStatusLabels: Record<string, string> = {
  unpaid: 'Не оплачен',
  paid: 'Оплачен',
  partially_refunded: 'Возвращена часть оплаты',
  refunded: 'Оплата возвращена',
}

const orderMoney = new Intl.NumberFormat('ru-KZ', {
  style: 'currency',
  currency: 'KZT',
  maximumFractionDigits: 0,
})

function Field({
  label,
  icon,
  type = 'text',
  name,
  value,
  onChange,
  autoComplete,
  required = true,
  minLength,
}: {
  label: string
  icon: ReactNode
  type?: string
  name: string
  value: string
  onChange: (value: string) => void
  autoComplete?: string
  required?: boolean
  minLength?: number
}) {
  return (
    <label className="block">
      <span className="mb-2 block text-sm font-medium">{label}</span>
      <span className="flex h-12 items-center gap-3 rounded-xl border border-input bg-background px-3.5 transition focus-within:border-primary focus-within:ring-3 focus-within:ring-ring/15">
        <span className="text-muted-foreground">{icon}</span>
        <input
          autoComplete={autoComplete}
          className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/70"
          name={name}
          minLength={minLength}
          onChange={(event) => onChange(event.target.value)}
          required={required}
          type={type}
          value={value}
        />
      </span>
    </label>
  )
}

export function AuthPage({ user, onAuthenticated, statusMessage, statusError, onClearStatus }: AuthPageProps) {
  const resetParams = new URLSearchParams(window.location.search)
  const resetToken = resetParams.get('token') ?? ''
  const resetEmail = resetParams.get('email') ?? ''
  const [mode, setMode] = useState<AuthMode>(resetToken && resetEmail ? 'reset' : 'login')
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [profileName, setProfileName] = useState('')
  const [profilePhone, setProfilePhone] = useState('')
  const [profileEmail, setProfileEmail] = useState('')
  const [email, setEmail] = useState(resetEmail)
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [orders, setOrders] = useState<CustomerOrder[]>([])
  const [ordersLoading, setOrdersLoading] = useState(false)
  const [ordersError, setOrdersError] = useState<string | null>(null)

  useEffect(() => {
    if (user) {
      setProfileName(user.name)
      setProfilePhone(user.phone ?? '')
      setProfileEmail(user.email ?? '')
    }
  }, [user?.id, user?.name, user?.phone, user?.email])

  const switchMode = (nextMode: AuthMode) => {
    if (nextMode === 'forgot' && !email.includes('@')) setEmail('')
    setMode(nextMode)
    setMessage(null)
    setError(null)
    onClearStatus()
  }

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setMessage(null)

    try {
      if (mode === 'login') {
        await prepareSanctumSession()
        const response = await apiRequest<UserResponse>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        })
        onAuthenticated(response.data)
        return
      }

      if (mode === 'register') {
        await prepareSanctumSession()
        const response = await apiRequest<MessageResponse>('/auth/register', {
          method: 'POST',
          body: JSON.stringify({ name, email: email.trim() || null, phone, password, password_confirmation: passwordConfirmation }),
        })
        setMessage(response.message)
        setEmail(email.trim() || phone)
        setMode('login')
        return
      }

      if (mode === 'forgot') {
        await prepareSanctumSession()
        const response = await apiRequest<MessageResponse>('/auth/forgot-password', {
          method: 'POST',
          body: JSON.stringify({ email }),
        })
        setMessage(response.message)
        return
      }

      await prepareSanctumSession()
      const response = await apiRequest<MessageResponse>('/auth/reset-password', {
        method: 'POST',
        body: JSON.stringify({
          email,
          token: resetToken,
          password,
          password_confirmation: passwordConfirmation,
        }),
      })
      window.history.replaceState({}, '', '/account')
      setPassword('')
      setPasswordConfirmation('')
      setMessage(response.message)
      setMode('login')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось выполнить запрос.')
    } finally {
      setBusy(false)
    }
  }

  const resendVerification = async () => {
    setBusy(true)
    setError(null)
    setMessage(null)

    try {
      await prepareSanctumSession()
      const response = await apiRequest<MessageResponse>('/auth/email/verification-notification', { method: 'POST' })
      setMessage(response.message)
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось отправить письмо.')
    } finally {
      setBusy(false)
    }
  }

  const logout = async () => {
    setBusy(true)
    setError(null)

    try {
      await apiRequest<MessageResponse>('/auth/logout', { method: 'POST' })
      onAuthenticated(null)
      setMode('login')
      setMessage('Вы вышли из аккаунта.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Не удалось выйти из аккаунта.')
    } finally {
      setBusy(false)
    }
  }

  const loadOrders = async () => {
    if (!user) return
    setOrdersLoading(true)
    setOrdersError(null)
    try {
      const response = await apiRequest<OrdersResponse>('/orders')
      setOrders(response.data)
    } catch (requestError) {
      setOrdersError(requestError instanceof Error ? requestError.message : 'Не удалось загрузить заказы.')
    } finally {
      setOrdersLoading(false)
    }
  }

  useEffect(() => {
    if (user) {
      void loadOrders()
    } else {
      setOrders([])
      setOrdersError(null)
    }
  }, [user?.id])

  const modeTitle = {
    login: 'С возвращением',
    register: 'Создать аккаунт',
    forgot: 'Восстановить пароль',
    reset: 'Новый пароль',
  }[mode]

  const modeDescription = {
    login: 'Войдите по номеру телефона или электронной почте, чтобы управлять заказами.',
    register: 'Телефон обязателен. Email можно добавить для восстановления пароля.',
    forgot: 'Отправим ссылку на email, указанный в аккаунте. Если email не добавлен, обратитесь к менеджеру.',
    reset: 'Выберите новый пароль длиной не менее 8 символов.',
  }[mode]

  return (
    <main className="min-h-screen bg-background px-4 py-6 pb-24 text-foreground sm:px-8 sm:py-10 md:pb-10">
      <div className="mx-auto flex min-h-[calc(100vh-3rem)] max-w-6xl flex-col sm:min-h-[calc(100vh-5rem)]">
        <header className="flex items-center justify-between">
          <a className="inline-flex items-center gap-2.5 text-sm font-semibold tracking-tight" href="/" aria-label="Aluxtorg — на главную">
            <BrandMark className="h-9 w-[2.9rem]" />
            <span>Aluxtorg</span>
          </a>
          <span className="hidden items-center gap-2 rounded-full border border-border bg-card px-3 py-1.5 text-xs text-muted-foreground sm:inline-flex">
            <ShieldCheck aria-hidden="true" className="text-primary" size={14} />
            Защищённый аккаунт
          </span>
          <a className="text-sm text-muted-foreground hover:text-foreground" href="/cart">Корзина</a>
        </header>

        <section className="grid flex-1 items-center gap-10 py-10 md:grid-cols-[1fr_0.85fr] md:gap-16 md:py-14">
          <div className="mx-auto w-full max-w-md">
            {user ? (
              <div className="rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8">
                <span className="mb-6 flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-foreground">
                  <UserRound aria-hidden="true" size={22} />
                </span>
                <p className="text-sm font-medium text-muted-foreground">Ваш аккаунт</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight">{user.name}</h1>
                <p className="mt-2 text-sm text-muted-foreground">{user.email ?? user.phone}</p>
                <span className="mt-5 inline-flex rounded-full bg-secondary px-3 py-1.5 text-xs font-medium text-secondary-foreground">
                  {roleLabels[user.role] ?? user.role}
                </span>
                {['manager', 'administrator', 'super_administrator'].includes(user.role) && (
                  <a className="mt-5 inline-flex w-full items-center justify-center rounded-xl bg-primary px-4 py-3 text-sm font-medium text-primary-foreground hover:opacity-90" href="/admin/catalog">
                    Управление каталогом
                  </a>
                )}
                {(error || statusError || statusMessage || message) && (
                  <div
                    aria-live="polite"
                    className={`mt-5 rounded-xl border px-4 py-3 text-sm leading-6 ${
                      error || statusError
                        ? 'border-red-200 bg-red-50 text-red-800'
                        : 'border-emerald-200 bg-emerald-50 text-emerald-900'
                    }`}
                    role={error || statusError ? 'alert' : 'status'}
                  >
                    {error ?? statusError ?? statusMessage ?? message}
                  </div>
                )}

                {user.email && !user.email_verified_at && (
                  <div className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
                    <p className="font-medium">Подтвердите электронную почту</p>
                    <p className="mt-1 leading-6 text-amber-900/80">Отправили ссылку на {user.email}. После подтверждения обновите страницу.</p>
                    <Button className="mt-3" disabled={busy} onClick={() => void resendVerification()} type="button" variant="outline">
                      Отправить письмо ещё раз
                    </Button>
                  </div>
                )}

                {!user.email && (
                  <p className="mt-5 rounded-xl border border-border bg-muted/40 p-4 text-sm leading-6 text-muted-foreground">
                    Email не указан. Для восстановления доступа обратитесь к менеджеру.
                  </p>
                )}

                <form
                    id="profile"
                    className="mt-6 grid gap-4"
                    onSubmit={async (event) => {
                      event.preventDefault()
                      if (profileName === user.name && profilePhone === (user.phone ?? '') && profileEmail === (user.email ?? '')) return

                      setBusy(true)
                      setError(null)
                      setMessage(null)
                      try {
                        const response = await apiRequest<UserResponse>('/auth/profile', {
                          method: 'PATCH',
                          body: JSON.stringify({
                            name: profileName,
                            phone: profilePhone,
                            email: profileEmail.trim() || null,
                          }),
                        })
                        onAuthenticated(response.data)
                        setMessage(response.data.email && !response.data.email_verified_at
                          ? 'Профиль обновлён. Подтвердите новый email по ссылке из письма.'
                          : 'Профиль обновлён.')
                      } catch (requestError) {
                        setError(requestError instanceof Error ? requestError.message : 'Не удалось сохранить профиль.')
                      } finally {
                        setBusy(false)
                      }
                    }}
                  >
                    <Field
                      autoComplete="name"
                      icon={<UserRound size={17} />}
                      label="Имя"
                      name="profile-name"
                      onChange={setProfileName}
                      value={profileName}
                    />
                    <Field
                      autoComplete="tel"
                      icon={<Phone size={17} />}
                      label="Телефон"
                      name="profile-phone"
                      onChange={setProfilePhone}
                      value={profilePhone}
                    />
                    <Field
                      autoComplete={mode === 'login' ? 'username' : 'email'}
                      icon={mode === 'login' ? <Phone size={17} /> : <Mail size={17} />}
                      label="Электронная почта (необязательно)"
                      name="profile-email"
                      onChange={setProfileEmail}
                      required={false}
                      type="email"
                      value={profileEmail}
                    />
                    <Button
                      disabled={busy || (profileName === user.name && profilePhone === (user.phone ?? '') && profileEmail === (user.email ?? ''))}
                      type="submit"
                    >
                      Сохранить изменения
                    </Button>
                </form>
                <section aria-labelledby="orders-heading" className="mt-7 border-t pt-6" id="orders">
                  <div className="flex items-center justify-between gap-3">
                    <h2 className="font-semibold" id="orders-heading">Мои заказы</h2>
                    {!ordersLoading && <button className="text-sm text-primary underline" onClick={() => void loadOrders()} type="button">Обновить</button>}
                  </div>
                  {ordersLoading ? (
                    <p className="mt-3 text-sm text-muted-foreground" role="status">Загружаем заказы…</p>
                  ) : ordersError ? (
                    <p className="mt-3 text-sm text-destructive" role="alert">{ordersError}</p>
                  ) : orders.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">Заказов пока нет.</p>
                  ) : (
                    <div className="mt-3 space-y-3">
                      {orders.map((order) => (
                        <article className="rounded-xl border bg-background p-4" key={order.id}>
                          <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                              <h3 className="text-sm font-semibold">{order.number}</h3>
                              <p className="mt-1 text-xs text-muted-foreground">
                                {order.created_at ? new Date(order.created_at).toLocaleDateString('ru-KZ') : ''}
                              </p>
                            </div>
                            <span className="rounded-full bg-secondary px-2.5 py-1 text-xs font-medium">
                              {orderStatusLabels[order.order_status] ?? order.order_status}
                            </span>
                          </div>
                          <ul className="mt-3 space-y-1 text-sm text-muted-foreground">
                            {order.items.map((item, index) => (
                              <li key={`${order.id}-${index}`}>
                                {item.product_name}{item.variant_name ? ` — ${item.variant_name}` : ''} × {item.quantity}
                              </li>
                            ))}
                          </ul>
                          <div className="mt-3 flex flex-wrap justify-between gap-2 border-t pt-3 text-sm">
                            <span>{paymentStatusLabels[order.payment_status] ?? order.payment_status}</span>
                            <strong>{order.total_kzt === null ? `Товары: ${orderMoney.format(order.subtotal_kzt)}` : orderMoney.format(order.total_kzt)}</strong>
                          </div>
                          <p className="mt-2 text-xs leading-5 text-muted-foreground">
                            {order.fulfillment_method === 'delivery'
                              ? 'Стоимость доставки согласуется с менеджером в WhatsApp.'
                              : 'Детали самовывоза согласуются с менеджером в WhatsApp.'}
                          </p>
                          <a className="mt-3 inline-flex text-sm font-medium text-primary underline underline-offset-4" href={`/account/orders/${order.id}`}>Подробнее о заказе</a>
                        </article>
                      ))}
                    </div>
                  )}
                </section>
                <Button className="mt-4 w-full" disabled={busy} onClick={() => void logout()} type="button" variant="outline">
                  Выйти из аккаунта
                </Button>
              </div>
            ) : (
              <div className="rounded-3xl border border-border bg-card p-6 shadow-sm sm:p-8">
                {mode !== 'login' && (
                  <button
                    className="mb-6 inline-flex items-center gap-2 text-sm text-muted-foreground transition hover:text-foreground"
                    onClick={() => switchMode('login')}
                    type="button"
                  >
                    <ArrowLeft aria-hidden="true" size={16} />
                    Вернуться ко входу
                  </button>
                )}
                <p className="text-sm font-medium text-primary">{mode === 'register' ? 'Присоединяйтесь к нам' : 'Личный кабинет'}</p>
                <h1 className="mt-2 text-3xl font-semibold tracking-tight">{modeTitle}</h1>
                <p className="mt-2 text-sm leading-6 text-muted-foreground">{modeDescription}</p>

                <form className="mt-7 grid gap-4" onSubmit={(event) => void submit(event)}>
                  {mode === 'register' && (
                    <>
                      <Field autoComplete="name" icon={<UserRound size={17} />} label="Имя" name="name" onChange={setName} value={name} />
                      <Field
                        autoComplete="tel"
                        icon={<Phone size={17} />}
                        label="Номер телефона"
                        name="phone"
                        onChange={setPhone}
                        value={phone}
                      />
                    </>
                  )}
                  {mode !== 'reset' && (
                    <Field
                      autoComplete="email"
                      icon={<Mail size={17} />}
                      label={mode === 'login' ? 'Номер телефона или email' : mode === 'register' ? 'Электронная почта (необязательно)' : 'Электронная почта'}
                      name="email"
                      onChange={setEmail}
                      required={mode !== 'register'}
                      type={mode === 'login' ? 'text' : 'email'}
                      value={email}
                    />
                  )}
                  {(mode === 'login' || mode === 'register' || mode === 'reset') && (
                    <Field
                      autoComplete={mode === 'login' ? 'current-password' : 'new-password'}
                      icon={<LockKeyhole size={17} />}
                      label="Пароль"
                      name="password"
                      minLength={mode === 'register' || mode === 'reset' ? 8 : undefined}
                      onChange={setPassword}
                      type="password"
                      value={password}
                    />
                  )}
                  {(mode === 'register' || mode === 'reset') && (
                    <Field
                      autoComplete="new-password"
                      icon={<KeyRound size={17} />}
                      label="Повторите пароль"
                      name="password_confirmation"
                      minLength={8}
                      onChange={setPasswordConfirmation}
                      type="password"
                      value={passwordConfirmation}
                    />
                  )}

                  {(error || statusError || statusMessage || message) && (
                    <div
                      aria-live="polite"
                      className={`rounded-xl border px-4 py-3 text-sm leading-6 ${
                        error || statusError
                          ? 'border-red-200 bg-red-50 text-red-800'
                          : 'border-emerald-200 bg-emerald-50 text-emerald-900'
                      }`}
                      role={error || statusError ? 'alert' : 'status'}
                    >
                      {error ?? statusError ?? statusMessage ?? message}
                    </div>
                  )}

                  <Button className="mt-1 h-11 w-full justify-center gap-2" disabled={busy} type="submit">
                    {busy ? 'Подождите…' : { login: 'Войти', register: 'Создать аккаунт', forgot: 'Отправить ссылку', reset: 'Сохранить новый пароль' }[mode]}
                    {!busy && <ArrowRight aria-hidden="true" size={16} />}
                  </Button>
                </form>

                {mode === 'login' && (
                  <div className="mt-5 flex flex-wrap items-center justify-between gap-3 text-sm">
                    <button className="text-muted-foreground transition hover:text-primary" onClick={() => switchMode('forgot')} type="button">
                      Забыли пароль?
                    </button>
                    <button className="font-medium text-primary transition hover:text-primary/75" onClick={() => switchMode('register')} type="button">
                      Создать аккаунт
                    </button>
                  </div>
                )}
                {mode === 'register' && (
                  <p className="mt-5 text-center text-sm text-muted-foreground">
                    Уже есть аккаунт?{' '}
                    <button className="font-medium text-primary hover:underline" onClick={() => switchMode('login')} type="button">
                      Войти
                    </button>
                  </p>
                )}
              </div>
            )}
          </div>

          <aside className="hidden md:block">
            <div className="relative overflow-hidden rounded-[2rem] bg-[#315c4d] p-10 text-white shadow-sm lg:p-12">
              <div aria-hidden="true" className="absolute -right-20 -top-20 size-72 rounded-full border border-white/10" />
              <div aria-hidden="true" className="absolute -right-8 -top-8 size-48 rounded-full border border-white/10" />
              <span className="relative flex size-12 items-center justify-center rounded-2xl bg-white/10">
                {user?.email_verified_at ? <Check aria-hidden="true" size={22} /> : <ShieldCheck aria-hidden="true" size={22} />}
              </span>
              <p className="relative mt-10 text-sm font-medium text-white/70">Просто и надёжно</p>
              <h2 className="relative mt-3 max-w-sm text-3xl font-semibold leading-tight tracking-tight lg:text-4xl">
                Ваши заказы и данные — в одном месте.
              </h2>
              <p className="relative mt-5 max-w-sm text-sm leading-7 text-white/75">
                Создайте аккаунт, чтобы видеть историю заказов и управлять контактными данными. Для регистрации нужен номер телефона, email можно добавить по желанию.
              </p>
              <div className="relative mt-10 flex items-center gap-3 border-t border-white/15 pt-5 text-sm text-white/75">
                <span className="flex size-8 items-center justify-center rounded-full bg-white/10">
                  <ShieldCheck aria-hidden="true" size={15} />
                </span>
                Вход защищён сессионной cookie и CSRF-проверкой
              </div>
            </div>
          </aside>
        </section>

        <footer className="flex flex-col gap-2 border-t border-border pt-5 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <span>Безопасный доступ к личному кабинету</span>
          <span className="inline-flex items-center gap-1.5">
            Данные защищены <ShieldCheck aria-hidden="true" size={13} />
          </span>
        </footer>
      </div>
    </main>
  )
}

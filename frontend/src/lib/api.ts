export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
  ) {
    super(localizeApiMessage(message, status))
    this.name = 'ApiError'
  }
}

function localizeApiMessage(message: string, status: number): string {
  if (/[А-Яа-яЁё]/.test(message)) return message

  if (status === 401) return 'Войдите в аккаунт, чтобы продолжить.'
  if (status === 403) return 'У вас недостаточно прав для этого действия.'
  if (status === 404) return 'Запрошенная страница или запись не найдена.'
  if (status === 419) return 'Сессия устарела. Обновите страницу и попробуйте снова.'
  if (status === 422) return 'Проверьте правильность заполнения формы.'
  if (status === 429) return 'Слишком много попыток. Попробуйте позже.'
  if (status >= 500) return 'Не удалось выполнить запрос. Попробуйте позже.'

  return 'Не удалось выполнить запрос. Попробуйте ещё раз.'
}

export async function prepareSanctumSession(): Promise<void> {
  let response: Response
  try {
    response = await fetch('/sanctum/csrf-cookie', {
      credentials: 'include',
      headers: { Accept: 'application/json' },
    })
  } catch {
    throw new ApiError('Не удалось подключиться к серверу. Проверьте соединение и попробуйте снова.', 0)
  }

  if (!response.ok) {
    throw new ApiError('Не удалось подготовить защищённую сессию.', response.status)
  }
}

export async function apiRequest<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')

  const csrfToken = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.slice('XSRF-TOKEN='.length)

  if (csrfToken && !headers.has('X-XSRF-TOKEN')) {
    headers.set('X-XSRF-TOKEN', decodeURIComponent(csrfToken))
  }

  if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  let response: Response
  try {
    response = await fetch(`/api/v1${path}`, {
      ...init,
      headers,
      credentials: 'include',
    })
  } catch {
    throw new ApiError('Не удалось подключиться к серверу. Проверьте соединение и попробуйте снова.', 0)
  }
  const contentType = response.headers.get('content-type') ?? ''

  if (!contentType.includes('application/json')) {
    throw new ApiError('Сервер вернул ответ в неподдерживаемом формате.', response.status)
  }

  let body: unknown
  try {
    body = await response.json()
  } catch {
    throw new ApiError('Сервер вернул некорректный ответ. Попробуйте позже.', response.status)
  }

  if (!response.ok) {
    const errors =
      typeof body === 'object' && body !== null && 'errors' in body && typeof body.errors === 'object' && body.errors !== null
        ? Object.values(body.errors).flatMap((messages) => (Array.isArray(messages) ? messages : []))
        : []
    const message =
      typeof errors[0] === 'string'
        ? errors[0]
        : typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string'
          ? body.message
          : 'Не удалось выполнить запрос.'
    throw new ApiError(message, response.status)
  }

  return body as T
}

export interface HealthResponse {
  status: 'ok'
  service: 'api'
}

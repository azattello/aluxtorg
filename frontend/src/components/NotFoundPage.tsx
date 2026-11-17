import { ArrowLeft } from 'lucide-react'

export function NotFoundPage() {
  return (
    <main className="flex min-h-screen items-center justify-center bg-background px-5 pb-24 text-foreground md:pb-0">
      <section className="max-w-md text-center">
        <p className="text-sm font-semibold text-primary">404</p>
        <h1 className="mt-2 text-3xl font-semibold tracking-tight">Страница не найдена</h1>
        <p className="mt-3 text-sm leading-6 text-muted-foreground">Проверьте адрес или вернитесь в каталог магазина.</p>
        <a className="mt-6 inline-flex items-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground" href="/">
          <ArrowLeft className="size-4" /> В каталог
        </a>
      </section>
    </main>
  )
}

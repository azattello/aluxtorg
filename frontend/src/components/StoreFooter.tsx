import { Mail, MapPin, Phone } from 'lucide-react'
import { BrandMark } from '@/components/BrandMark'
import type { StoreSettings } from '@/lib/store'
import { whatsappHref } from '@/lib/store'

export function StoreFooter({
  settings,
  error,
}: {
  settings: StoreSettings | null
  error: string | null
}) {
  const whatsapp = whatsappHref(settings?.whatsapp ?? null)

  return (
    <footer className="mt-16 border-t bg-card text-card-foreground">
      <div className="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:px-6 md:grid-cols-2 lg:grid-cols-4 lg:px-8">
        <div>
          <a className="inline-flex items-center gap-3 text-xl font-semibold tracking-tight text-foreground" href="/" aria-label="Aluxtorg — на главную">
            <BrandMark className="h-9 w-[2.9rem]" />
            <span>Aluxtorg</span>
          </a>
          <p className="mt-3 max-w-xs text-sm leading-6 text-muted-foreground">
            Интернет-магазин. Выбирайте товары в каталоге и оформляйте заказ онлайн.
          </p>
        </div>

        <nav aria-label="Покупателям">
          <h2 className="text-sm font-semibold">Покупателям</h2>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><a className="hover:text-foreground" href="/">Каталог товаров</a></li>
            <li><a className="hover:text-foreground" href="/cart">Корзина</a></li>
            <li><a className="hover:text-foreground" href="/account">Личный кабинет и заказы</a></li>
          </ul>
        </nav>

        <nav aria-label="Карта сайта">
          <h2 className="text-sm font-semibold">Карта сайта</h2>
          <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
            <li><a className="hover:text-foreground" href="/sitemap.xml">XML-карта сайта</a></li>
            <li><a className="hover:text-foreground" href="/robots.txt">Правила индексации</a></li>
          </ul>
        </nav>

        <section aria-labelledby="store-contacts">
          <h2 className="text-sm font-semibold" id="store-contacts">Контакты</h2>
          {error ? (
            <p className="mt-3 text-sm text-destructive" role="alert">{error}</p>
          ) : settings ? (
            <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
              {settings.phone && (
                <li className="flex items-start gap-2">
                  <Phone aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <a className="hover:text-foreground" href={`tel:${settings.phone.replace(/[^\d+]/g, '')}`}>{settings.phone}</a>
                </li>
              )}
              {settings.email && (
                <li className="flex items-start gap-2">
                  <Mail aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <a className="break-all hover:text-foreground" href={`mailto:${settings.email}`}>{settings.email}</a>
                </li>
              )}
              {settings.address && (
                <li className="flex items-start gap-2">
                  <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                  <span>{settings.address}</span>
                </li>
              )}
              {settings.working_hours && <li>{settings.working_hours}</li>}
              {whatsapp && (
                <li>
                  <a className="inline-flex min-h-9 items-center rounded-lg bg-primary px-3 text-sm font-medium !text-white hover:opacity-90" href={whatsapp} rel="noreferrer" target="_blank">
                    Написать в WhatsApp
                  </a>
                </li>
              )}
              {settings.instagram_url && <li><a className="hover:text-foreground" href={settings.instagram_url} rel="noreferrer" target="_blank">Instagram</a></li>}
              {settings.telegram_url && <li><a className="hover:text-foreground" href={settings.telegram_url} rel="noreferrer" target="_blank">Telegram</a></li>}
              {!settings.phone && !settings.email && !settings.address && !whatsapp && !settings.instagram_url && !settings.telegram_url && (
                <li>Контактные данные будут опубликованы после настройки.</li>
              )}
            </ul>
          ) : (
            <p aria-live="polite" className="mt-3 text-sm text-muted-foreground">Загружаем контактные данные…</p>
          )}
        </section>
      </div>

      <div className="border-t">
        <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-4 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
          <span>© {new Date().getFullYear()} Aluxtorg</span>
          <span>Информация и контакты актуальны на момент оформления заказа.</span>
        </div>
      </div>
    </footer>
  )
}

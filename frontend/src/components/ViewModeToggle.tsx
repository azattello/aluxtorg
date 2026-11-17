import { LayoutGrid, Table2 } from 'lucide-react'

export type ViewMode = 'cards' | 'table'

interface ViewModeToggleProps {
  value: ViewMode
  onChange: (mode: ViewMode) => void
  label?: string
}

export function ViewModeToggle({ value, onChange, label = 'Вид отображения' }: ViewModeToggleProps) {
  return (
    <div aria-label={label} className="inline-flex shrink-0 rounded-xl border bg-card p-1" role="group">
      <button
        aria-pressed={value === 'cards'}
        className={`inline-flex h-9 items-center gap-2 rounded-lg px-3 text-xs font-medium transition-colors sm:text-sm ${
          value === 'cards' ? 'bg-primary text-white' : 'text-muted-foreground hover:bg-muted hover:text-foreground'
        }`}
        onClick={() => onChange('cards')}
        type="button"
      >
        <LayoutGrid aria-hidden="true" className="size-4" />
        Карточки
      </button>
      <button
        aria-pressed={value === 'table'}
        className={`inline-flex h-9 items-center gap-2 rounded-lg px-3 text-xs font-medium transition-colors sm:text-sm ${
          value === 'table' ? 'bg-primary text-white' : 'text-muted-foreground hover:bg-muted hover:text-foreground'
        }`}
        onClick={() => onChange('table')}
        type="button"
      >
        <Table2 aria-hidden="true" className="size-4" />
        Таблица
      </button>
    </div>
  )
}

interface BrandMarkProps {
  className?: string
  variant?: 'logo' | 'icon'
}

export function BrandMark({ className = '', variant = 'logo' }: BrandMarkProps) {
  const src = variant === 'icon' ? '/apple-touch-icon.png' : '/aluxtorg-logo.jpg'

  return (
    <img
      alt=""
      aria-hidden="true"
      className={`block shrink-0 rounded-lg bg-[#0b0b0b] object-cover ${className}`}
      draggable={false}
      src={src}
    />
  )
}

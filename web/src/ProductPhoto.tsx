import { useState } from 'react'

type ProductImageSource = { url: string; alt: string | null; srcset?: string | null }

type ProductPhotoProps = {
  name: string
  images: ProductImageSource[]
  fallbackIcon: string
  className?: string
  sizes?: string
  loading?: 'eager' | 'lazy'
}

export function ProductPhoto({
  name,
  images,
  fallbackIcon,
  className,
  sizes = '(max-width: 560px) 45vw, (max-width: 1120px) 24vw, 18vw',
  loading = 'lazy',
}: ProductPhotoProps) {
  const [failedUrls, setFailedUrls] = useState<string[]>([])

  const image = images.find((candidate) => !failedUrls.includes(candidate.url))
  if (!image) {
    return (
      <span className="product-placeholder" role="img" aria-label={`Ảnh chưa có: ${name}`}>
        {fallbackIcon}
      </span>
    )
  }

  return (
    <img
      className={className}
      src={image.url}
      srcSet={image.srcset ?? undefined}
      sizes={image.srcset ? sizes : undefined}
      alt={image.alt?.trim() || name}
      loading={loading}
      decoding="async"
      fetchPriority={loading === 'eager' ? 'high' : 'low'}
      onError={() => setFailedUrls((current) => [...current, image.url])}
    />
  )
}

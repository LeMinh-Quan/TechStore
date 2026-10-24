import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent, MouseEvent } from 'react'
import './App.css'
import { AppearanceSettings } from './AppearanceSettings'
import { ChatWidget } from './ChatWidget'
import { apiRequest } from './commerce'
import type { CartData, CartItem, Customer } from './commerce'
import { ProductPhoto } from './ProductPhoto'
import { useDialogAccessibility } from './useDialogAccessibility'
import { languageStorageKey, loadLanguage, translate, translateCategory } from './language'
import type { Language } from './language'

const CommerceDialogs = lazy(() => import('./CommerceDialogs').then(({ CommerceDialogs }) => ({ default: CommerceDialogs })))
const ProductComparisonDialog = lazy(() => import('./ProductComparisonDialog').then(({ ProductComparisonDialog }) => ({ default: ProductComparisonDialog })))

type Product = {
  id: string
  slug: string
  name: string
  category: string | null
  brand: string | null
  description: string | null
  price: number
  list_price: number | null
  stock: number
  purchasable: boolean
  images: { url: string; alt: string | null; srcset?: string | null }[]
  specifications?: Record<string, string>
  average_rating?: number
  review_count?: number
  sold_count?: number
}
type CatalogUrlState = {
  query: string
  category: string
  sort: string
  filters: SpecificationFilters
}
type ProductReview = { rating: number; content: string; created_at: string; name: string | null; is_demo?: boolean }

type CatalogResponse = {
  data: Product[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

type FacetOption = { value: string; count: number }
type SpecificationFilterKey = 'cpu' | 'ram' | 'rom' | 'gpu' | 'os' | 'display' | 'battery'
type SpecificationFacet = { key: SpecificationFilterKey; label: string; options: FacetOption[] }
type CatalogFacets = {
  brands: FacetOption[]
  specifications: SpecificationFacet[]
  price: { min: number | null; max: number | null }
}

type SpecificationFilters = {
  brand: string
  cpu: string
  ram: string
  rom: string
  gpu: string
  os: string
  display: string
  battery: string
  min_price: string
  max_price: string
  on_sale: string
  min_rating: string
}

const emptySpecificationFilters: SpecificationFilters = {
  brand: '',
  cpu: '',
  ram: '',
  rom: '',
  gpu: '',
  os: '',
  display: '',
  battery: '',
  min_price: '',
  max_price: '',
  on_sale: '',
  min_rating: '',
}

const apiUrl = (import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '')
const guestCartKey = 'techstore-guest-cart'
const emptyCart: CartData = { items: [], total: 0 }
const favoriteStorageKey = 'techstore-favorites'

function readCatalogUrl(): CatalogUrlState {
  const params = new URLSearchParams(window.location.search)
  const requestedSort = params.get('sort')
  return {
    query: params.get('q') ?? '',
    category: params.get('category') ?? '',
    sort: requestedSort && ['featured', 'price_asc', 'price_desc', 'name', 'best_selling', 'top_rated'].includes(requestedSort) ? requestedSort : 'featured',
    filters: {
      ...emptySpecificationFilters,
      brand: params.get('brand') ?? '',
      cpu: params.get('cpu') ?? '',
      ram: params.get('ram') ?? '',
      rom: params.get('rom') ?? '',
      gpu: params.get('gpu') ?? '',
      os: params.get('os') ?? '',
      display: params.get('display') ?? '',
      battery: params.get('battery') ?? '',
      min_price: params.get('min_price') ?? '',
      max_price: params.get('max_price') ?? '',
      on_sale: params.get('on_sale') ?? '',
      min_rating: params.get('min_rating') ?? '',
    },
  }
}

function readSavedProducts(key: string): Product[] {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(key) ?? '[]')
    if (!Array.isArray(saved)) return []
    return saved.filter((item): item is Product =>
      Boolean(item && typeof item === 'object' && typeof item.id === 'string'
        && typeof item.slug === 'string' && typeof item.name === 'string'
        && Number.isFinite(item.price) && Number.isFinite(item.stock)
        && Array.isArray(item.images)),
    )
  } catch {
    return []
  }
}

function openSupportChat(message?: string) {
  window.dispatchEvent(new CustomEvent('techstore:open-chat', { detail: { message } }))
}

function loadGuestCart(): CartData {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(guestCartKey) ?? 'null')
    if (!parsed || typeof parsed !== 'object' || !('items' in parsed) || !Array.isArray(parsed.items)) return emptyCart
    const items = parsed.items.filter((item): item is CartData['items'][number] => {
      if (!item || typeof item !== 'object') return false
      const candidate = item as Partial<CartData['items'][number]>
      return typeof candidate.product_id === 'string' && typeof candidate.slug === 'string'
        && typeof candidate.name === 'string' && Number.isFinite(candidate.price)
        && Number.isInteger(candidate.quantity) && Number(candidate.quantity) > 0
        && Number.isFinite(candidate.stock)
    }).map((item) => ({ ...item, line_total: item.price * item.quantity }))
    return { items, total: items.reduce((total, item) => total + item.line_total, 0) }
  } catch {
    return emptyCart
  }
}

const heroSlides = [
  {
    label: 'TECHSTORE SELECTION',
    title: 'Công nghệ tốt.',
    emphasis: 'Chọn thật dễ.',
    description: 'Khám phá thiết bị phù hợp với công việc, học tập và những điều bạn yêu thích.',
  },
  {
    label: 'CHỌN ĐÚNG NHU CẦU',
    title: 'Tìm thiết bị hợp.',
    emphasis: 'Dùng trọn đam mê.',
    description: 'Lọc sản phẩm theo danh mục và tìm lựa chọn phù hợp với cách bạn sử dụng.',
  },
  {
    label: 'MINH BẠCH TỪ ĐẦU',
    title: 'Thông tin rõ ràng.',
    emphasis: 'Chọn mua tự tin.',
    description: 'Khám phá toàn bộ danh mục sản phẩm được lấy trực tiếp từ cơ sở dữ liệu cửa hàng.',
  },
]
const categoryIcons: Record<string, string> = {
  'Điện thoại': '▯',
  'Laptop': '▱',
  'Máy tính bảng': '▯',
  'Âm thanh': '♫',
  'Màn hình': '▤',
  'Máy ảnh / Camera': '◎',
  'Đồng hồ': '◷',
  'Tivi': '▣',
  'Điện gia dụng': '⌂',
  'Chuột / Bàn phím': '⌨',
  'Phụ kiện': '⌁',
  'Phụ kiện laptop': '⌁',
  'Máy in': '▧',
}

function productPhoto(product: Product, className?: string, loading: 'eager' | 'lazy' = 'lazy', sizes?: string) {
  return (
    <ProductPhoto
      key={`${product.id}:${product.images.map((image) => image.url).join('|')}`}
      name={product.name}
      images={product.images}
      fallbackIcon={categoryIcons[product.category ?? ''] ?? '◇'}
      className={className}
      sizes={sizes}
      loading={loading}
    />
  )
}

function describeError(reason: unknown, fallback: string): string {
  if (reason instanceof TypeError) return 'Không kết nối được với máy chủ cửa hàng. Vui lòng thử lại sau.'
  return reason instanceof Error ? reason.message : fallback
}

function App() {
  useDialogAccessibility()
  const [language, setLanguage] = useState<Language>(loadLanguage)
  const [languageMenuOpen, setLanguageMenuOpen] = useState(false)
  const languageMenuRef = useRef<HTMLDivElement>(null)
  const t = (text: string) => translate(language, text)
  const initialCatalog = readCatalogUrl()
  const [initialCategory] = useState(initialCatalog.category)
  const [categories, setCategories] = useState<string[]>([])
  const [categoriesError, setCategoriesError] = useState('')
  const [categoriesRetry, setCategoriesRetry] = useState(0)
  const [query, setQuery] = useState(initialCatalog.query)
  const [submittedQuery, setSubmittedQuery] = useState(initialCatalog.query)
  const [category, setCategory] = useState(initialCatalog.category)
  const [specificationFilters, setSpecificationFilters] = useState(initialCatalog.filters)
  const [appliedSpecificationFilters, setAppliedSpecificationFilters] = useState(initialCatalog.filters)
  const [sort, setSort] = useState(initialCatalog.sort)
  const [catalogs, setCatalogs] = useState<Record<string, {
    key: string
    products: Product[]
    total: number
    error: string
  }>>({})
  const [catalogRetry, setCatalogRetry] = useState(0)
  const [requestedCategories, setRequestedCategories] = useState<string[]>([])
  const catalogRequests = useRef(new Map<string, { key: string; controller: AbortController }>())
  const catalogCache = useRef(catalogs)
  const [loadingMore, setLoadingMore] = useState<Record<string, boolean>>({})
  const [selectedSlug, setSelectedSlug] = useState(() => new URLSearchParams(window.location.search).get('product'))
  const [detail, setDetail] = useState<{ slug: string; product: Product | null; error: string } | null>(null)
  const [menuOpen, setMenuOpen] = useState(false)
  const [heroSlide, setHeroSlide] = useState(0)
  const [authToken, setAuthToken] = useState<string | null>(() => sessionStorage.getItem('techstore-token'))
  const [customer, setCustomer] = useState<Customer | null>(null)
  const [cart, setCart] = useState<CartData>(loadGuestCart)
  const [authOpen, setAuthOpen] = useState(false)
  const [cartOpen, setCartOpen] = useState(false)
  const [commerceDialogsLoaded, setCommerceDialogsLoaded] = useState(false)
  const [ordersRequestId, setOrdersRequestId] = useState(0)
  const [pendingProductId, setPendingProductId] = useState<string | null>(null)
  const [commerceNotice, setCommerceNotice] = useState('')
  const [guestMergeInProgress, setGuestMergeInProgress] = useState(false)
  const [guestCartNeedsMerge, setGuestCartNeedsMerge] = useState(false)
  const [pendingCheckout, setPendingCheckout] = useState(false)
  const [resumeCheckout, setResumeCheckout] = useState(false)
  const openAuthDialog = useCallback(() => {
    setCommerceDialogsLoaded(true)
    setAuthOpen(true)
  }, [])
  const openCartDialog = useCallback(() => {
    setCommerceDialogsLoaded(true)
    setCartOpen(true)
  }, [])
  const openOrdersDialog = useCallback(() => {
    setCommerceDialogsLoaded(true)
    setOrdersRequestId((requestId) => requestId + 1)
  }, [])
  const checkoutResumed = useCallback(() => setResumeCheckout(false), [])
  const [productReviews, setProductReviews] = useState<ProductReview[]>([])
  const [reviewSummary, setReviewSummary] = useState({ average_rating: 0, review_count: 0, demo_review_count: 0 })
  const [reviewRating, setReviewRating] = useState(5)
  const [reviewContent, setReviewContent] = useState('')
  const [reviewMessage, setReviewMessage] = useState('')
  const [filterFacets, setFilterFacets] = useState<{ key: string; data: CatalogFacets; error: string } | null>(null)
  const [deliveryCity, setDeliveryCity] = useState(() => localStorage.getItem('techstore-delivery-city') ?? '')
  const [locationDraft, setLocationDraft] = useState('')
  const [locationOpen, setLocationOpen] = useState(false)
  const [searchSuggestions, setSearchSuggestions] = useState<Product[]>([])
  const [searchSuggestionError, setSearchSuggestionError] = useState('')
  const [favoriteProducts, setFavoriteProducts] = useState<Product[]>(() => readSavedProducts(favoriteStorageKey))
  const [showFavorites, setShowFavorites] = useState(false)
  const [compareProducts, setCompareProducts] = useState<Product[]>([])
  const [compareOpen, setCompareOpen] = useState(false)
  const [detailImageIndex, setDetailImageIndex] = useState(0)
  const catalogKey = JSON.stringify([sort, submittedQuery, appliedSpecificationFilters])
  const facetsKey = JSON.stringify([category, submittedQuery])
  const currentFacets = filterFacets?.key === facetsKey ? filterFacets.data : null
  const facetsError = filterFacets?.key === facetsKey ? filterFacets.error : ''
  const visibleCategories = category ? [category] : categories
  const visibleCatalogs = visibleCategories.map((name) => {
    const catalog = catalogs[name]?.key === catalogKey ? catalogs[name] : null
    return { name, catalog }
  })
  const hasCatalogCriteria = Boolean(submittedQuery) || Object.values(appliedSpecificationFilters).some(Boolean)
  const renderedCatalogs = visibleCatalogs.filter(({ catalog }) => (
    catalog === null || Boolean(catalog.error) || catalog.products.length > 0
  ))
  const loading = visibleCatalogs.some(({ name, catalog }) =>
    catalog === null && (Boolean(category) || requestedCategories.includes(name) || hasCatalogCriteria),
  )
  const total = visibleCatalogs.reduce((sum, { catalog }) => sum + (catalog?.total ?? 0), 0)
  const hasProducts = visibleCatalogs.some(({ catalog }) => (catalog?.products.length ?? 0) > 0)
  const detailProduct = detail?.slug === selectedSlug ? detail.product : null
  const detailError = detail?.slug === selectedSlug ? detail.error : ''
  const featuredCategories = categories.slice(0, 6)
  const activeSlide = heroSlides[heroSlide]
  const numberLocale = language === 'vi' ? 'vi-VN' : 'en-US'
  const relatedProducts = detailProduct
    ? Object.values(catalogs).flatMap((catalog) => catalog.products)
      .filter((product, index, products) => product.id !== detailProduct.id
        && product.category === detailProduct.category
        && products.findIndex((candidate) => candidate.id === product.id) === index)
      .slice(0, 4)
    : []

  useEffect(() => {
    localStorage.setItem(languageStorageKey, language)
    document.documentElement.lang = language
  }, [language])

  useEffect(() => {
    if (!languageMenuOpen) return
    const closeOnOutsideClick = (event: PointerEvent) => {
      if (event.target instanceof Node && !languageMenuRef.current?.contains(event.target)) {
        setLanguageMenuOpen(false)
      }
    }
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setLanguageMenuOpen(false)
    }
    document.addEventListener('pointerdown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [languageMenuOpen])

  function syncCatalogUrl(state: CatalogUrlState, replace = false) {
    const params = new URLSearchParams(window.location.search)
    for (const key of ['q', 'category', 'sort', 'brand', 'cpu', 'ram', 'rom', 'gpu', 'os', 'display', 'battery', 'min_price', 'max_price', 'on_sale', 'min_rating']) {
      params.delete(key)
    }
    if (state.query) params.set('q', state.query)
    if (state.category) params.set('category', state.category)
    if (state.sort !== 'featured') params.set('sort', state.sort)
    Object.entries(state.filters).forEach(([key, value]) => {
      if (value) params.set(key, value)
    })
    const search = params.toString()
    const url = `${window.location.pathname}${search ? `?${search}` : ''}${window.location.hash}`
    window.history[replace ? 'replaceState' : 'pushState'](null, '', url)
  }

  useEffect(() => {
    if (!authToken) return

    let active = true
    const accountRequest = apiRequest<{ data: Customer }>('/auth/me', authToken)
    const cartRequest = guestMergeInProgress || guestCartNeedsMerge || pendingProductId
      ? Promise.resolve(null)
      : apiRequest<{ data: CartData }>('/cart', authToken)
    Promise.all([accountRequest, cartRequest])
      .then(([accountResult, cartResult]) => {
        if (!active) return
        setCustomer(accountResult.data)
        if (cartResult) setCart(cartResult.data)
      })
      .catch((reason: unknown) => {
        if (!active) return
        if (reason instanceof Error && reason.message === 'Unauthenticated.') {
          sessionStorage.removeItem('techstore-token')
          setCustomer(null)
          setCart({ items: [], total: 0 })
          setAuthToken(null)
          return
        }
        setCommerceNotice(reason instanceof Error ? reason.message : 'Không tải được tài khoản và giỏ hàng.')
      })

    return () => { active = false }
  }, [authToken, guestCartNeedsMerge, guestMergeInProgress, pendingProductId])

  useEffect(() => {
    if (authToken) return
    localStorage.setItem(guestCartKey, JSON.stringify(cart))
  }, [authToken, cart])

  useEffect(() => {
    localStorage.setItem(favoriteStorageKey, JSON.stringify(favoriteProducts))
  }, [favoriteProducts])

  useEffect(() => {
    catalogCache.current = catalogs
  }, [catalogs])

  useEffect(() => {
    const term = query.trim()
    if (term.length < 2 || term === submittedQuery) return
    const controller = new AbortController()
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams({ q: term, per_page: '5', sort: 'featured' })
      fetch(`${apiUrl}/products?${params}`, { signal: controller.signal })
        .then(async (response) => {
          if (!response.ok) throw new Error(`Không tải được gợi ý tìm kiếm (HTTP ${response.status}).`)
          return response.json() as Promise<CatalogResponse>
        })
        .then((result) => {
          if (!controller.signal.aborted) {
            setSearchSuggestions(result.data)
            setSearchSuggestionError('')
          }
        })
        .catch((reason: unknown) => {
          if (reason instanceof DOMException && reason.name === 'AbortError') return
          if (!controller.signal.aborted) {
            setSearchSuggestions([])
            setSearchSuggestionError(describeError(reason, 'Không tải được gợi ý tìm kiếm.'))
          }
        })
    }, 250)
    return () => {
      window.clearTimeout(timer)
      controller.abort()
    }
  }, [query, submittedQuery])

  useEffect(() => {
    if (typeof IntersectionObserver === 'undefined') return
    const requestReachedCategories = () => {
      const reached = Array.from(document.querySelectorAll<HTMLElement>('.product-group[data-catalog-category]'))
        .filter((section) => section.getBoundingClientRect().top <= window.innerHeight + 500)
        .map((section) => section.dataset.catalogCategory)
        .filter((name): name is string => Boolean(name))
      if (reached.length > 0) {
        setRequestedCategories((current) => Array.from(new Set([...current, ...reached])))
      }
    }
    const observer = new IntersectionObserver((entries) => {
      const newlyVisible = entries
        .filter((entry) => entry.isIntersecting)
        .map((entry) => (entry.target as HTMLElement).dataset.catalogCategory)
        .filter((name): name is string => Boolean(name))
      if (newlyVisible.length > 0) {
        setRequestedCategories((current) => Array.from(new Set([...current, ...newlyVisible])))
      }
    }, { rootMargin: '500px 0px' })
    document.querySelectorAll<HTMLElement>('.product-group[data-catalog-category]').forEach((section) => {
      observer.observe(section)
    })
    window.addEventListener('scroll', requestReachedCategories, { passive: true })
    window.addEventListener('resize', requestReachedCategories)
    requestReachedCategories()
    return () => {
      observer.disconnect()
      window.removeEventListener('scroll', requestReachedCategories)
      window.removeEventListener('resize', requestReachedCategories)
    }
  }, [categories, category])

  useEffect(() => {
    const targetCategories = category ? [category] : hasCatalogCriteria ? categories : requestedCategories
    catalogRequests.current.forEach((request, categoryName) => {
      if (request.key !== catalogKey) {
        request.controller.abort()
        catalogRequests.current.delete(categoryName)
      }
    })

    targetCategories.forEach((categoryName) => {
      if (catalogCache.current[categoryName]?.key === catalogKey) return
      if (catalogRequests.current.get(categoryName)?.key === catalogKey) return
      catalogRequests.current.get(categoryName)?.controller.abort()
      const controller = new AbortController()
      const request = { key: catalogKey, controller }
      catalogRequests.current.set(categoryName, request)
      const params = new URLSearchParams({ per_page: '10', page: '1', sort, category: categoryName })
      if (submittedQuery) params.set('q', submittedQuery)
      Object.entries(appliedSpecificationFilters).forEach(([key, value]) => {
        if (value) params.set(key, value)
      })

      fetch(`${apiUrl}/products?${params}`, { signal: controller.signal })
        .then(async (response) => {
          if (!response.ok) throw new Error(`Không tải được sản phẩm (HTTP ${response.status}).`)
          return response.json() as Promise<CatalogResponse>
        })
        .then((result) => {
          if (controller.signal.aborted) return
          setCatalogs((current) => ({
            ...current,
            [categoryName]: { key: catalogKey, products: result.data, total: result.meta.total, error: '' },
          }))
        })
        .catch((reason: unknown) => {
          if (reason instanceof DOMException && reason.name === 'AbortError') return
          if (!controller.signal.aborted) {
            setCatalogs((current) => ({
              ...current,
              [categoryName]: {
                key: catalogKey,
                products: [],
                total: 0,
                error: describeError(reason, 'Đã xảy ra lỗi khi tải danh mục.'),
              },
            }))
          }
        })
        .finally(() => {
          if (catalogRequests.current.get(categoryName) === request) {
            catalogRequests.current.delete(categoryName)
          }
        })
    })
  }, [appliedSpecificationFilters, catalogKey, catalogRetry, categories, category, hasCatalogCriteria, requestedCategories, sort, submittedQuery])

  useEffect(() => () => {
    catalogRequests.current.forEach(({ controller }) => controller.abort())
    catalogRequests.current.clear()
  }, [])

  useEffect(() => {
    const controller = new AbortController()
    fetch(`${apiUrl}/categories`, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Không tải được danh mục (HTTP ${response.status}).`)
        return response.json() as Promise<{ data: string[] }>
      })
      .then((result) => {
        if (controller.signal.aborted) return
        setCategories(result.data)
        setRequestedCategories(typeof IntersectionObserver === 'undefined'
          ? result.data
          : Array.from(new Set([...result.data.slice(0, 2), ...(initialCategory ? [initialCategory] : [])])))
      })
      .catch((reason: unknown) => {
        if (reason instanceof DOMException && reason.name === 'AbortError') return
        if (controller.signal.aborted) return
        setCategoriesError(
          reason instanceof TypeError
            ? 'Không kết nối được API danh mục.'
            : describeError(reason, 'Không tải được danh mục.'),
        )
      })
    return () => controller.abort()
  }, [categoriesRetry, initialCategory])

  useEffect(() => {
    const controller = new AbortController()
    const params = new URLSearchParams()
    if (category) params.set('category', category)
    if (submittedQuery) params.set('q', submittedQuery)

    fetch(`${apiUrl}/filters?${params}`, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Không tải được bộ lọc (HTTP ${response.status}).`)
        return response.json() as Promise<{ data: CatalogFacets }>
      })
      .then((result) => {
        if (!controller.signal.aborted) setFilterFacets({ key: facetsKey, data: result.data, error: '' })
      })
      .catch((reason: unknown) => {
        if (reason instanceof DOMException && reason.name === 'AbortError') return
        if (!controller.signal.aborted) {
          setFilterFacets({
            key: facetsKey,
            data: { brands: [], specifications: [], price: { min: null, max: null } },
            error: describeError(reason, 'Không tải được tùy chọn bộ lọc.'),
          })
        }
      })

    return () => controller.abort()
  }, [category, facetsKey, submittedQuery])

  useEffect(() => {
    const handlePopState = () => {
      const params = new URLSearchParams(window.location.search)
      const state = readCatalogUrl()
      setQuery(state.query)
      setSubmittedQuery(state.query)
      setCategory(state.category)
      setSort(state.sort)
      setSpecificationFilters(state.filters)
      setAppliedSpecificationFilters(state.filters)
      setSelectedSlug(params.get('product'))
      setDetailImageIndex(0)
      if (state.category) {
        setRequestedCategories((current) => Array.from(new Set([...current, state.category])))
      }
    }
    window.addEventListener('popstate', handlePopState)
    return () => window.removeEventListener('popstate', handlePopState)
  }, [])

  useEffect(() => {
    const openChatProduct = (event: Event) => {
      const slug = (event as CustomEvent<{ slug?: string }>).detail?.slug
      if (!slug) return
      const params = new URLSearchParams(window.location.search)
      params.set('product', slug)
      window.history.pushState(null, '', `${window.location.pathname}?${params}${window.location.hash}`)
      setSelectedSlug(slug)
      setDetailImageIndex(0)
    }
    window.addEventListener('techstore:open-product', openChatProduct)
    return () => window.removeEventListener('techstore:open-product', openChatProduct)
  }, [])

  useEffect(() => {
    if (!selectedSlug) return

    const controller = new AbortController()
    fetch(`${apiUrl}/products/${encodeURIComponent(selectedSlug)}`, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Không tải được thông tin sản phẩm (HTTP ${response.status}).`)
        return response.json() as Promise<{ data: Product }>
      })
      .then((result) => {
        if (!controller.signal.aborted) setDetail({ slug: selectedSlug, product: result.data, error: '' })
      })
      .catch((reason: unknown) => {
        if (reason instanceof DOMException && reason.name === 'AbortError') return
        if (!controller.signal.aborted) {
          setDetail({
            slug: selectedSlug,
            product: null,
            error: describeError(reason, 'Không tải được sản phẩm.'),
          })
        }
      })
    return () => controller.abort()
  }, [selectedSlug])

  useEffect(() => {
    if (!selectedSlug) return
    const controller = new AbortController()
    fetch(`${apiUrl}/products/${encodeURIComponent(selectedSlug)}/reviews`, { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error(`Không tải được đánh giá (HTTP ${response.status}).`)
        return response.json() as Promise<{ data: ProductReview[]; meta: { average_rating: number; review_count: number; demo_review_count?: number } }>
      })
      .then((result) => {
        if (controller.signal.aborted) return
        setProductReviews(result.data)
        setReviewSummary({ average_rating: result.meta.average_rating, review_count: result.meta.review_count, demo_review_count: result.meta.demo_review_count ?? 0 })
        setReviewMessage('')
      })
      .catch((reason: unknown) => {
        if (reason instanceof DOMException && reason.name === 'AbortError') return
        if (!controller.signal.aborted) setReviewMessage(describeError(reason, 'Không tải được đánh giá sản phẩm.'))
      })
    return () => controller.abort()
  }, [selectedSlug])

  function search(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const nextQuery = query.trim()
    setSubmittedQuery(nextQuery)
    syncCatalogUrl({ query: nextQuery, category, sort, filters: appliedSpecificationFilters })
    setSearchSuggestions([])
    setMenuOpen(false)
    document.getElementById('products')?.scrollIntoView({ behavior: 'smooth' })
  }

  function selectSort(nextSort: string) {
    setSort(nextSort)
    syncCatalogUrl({ query: submittedQuery, category, sort: nextSort, filters: appliedSpecificationFilters })
  }

  function applySpecificationFilters(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setAppliedSpecificationFilters(specificationFilters)
    syncCatalogUrl({ query: submittedQuery, category, sort, filters: specificationFilters })
    document.getElementById('products')?.scrollIntoView({ behavior: 'smooth' })
  }

  function clearSpecificationFilters() {
    setSpecificationFilters(emptySpecificationFilters)
    setAppliedSpecificationFilters(emptySpecificationFilters)
    syncCatalogUrl({ query: submittedQuery, category, sort, filters: emptySpecificationFilters })
  }

  function resetCatalogCriteria() {
    setQuery('')
    setSubmittedQuery('')
    setCategory('')
    setSpecificationFilters(emptySpecificationFilters)
    setAppliedSpecificationFilters(emptySpecificationFilters)
    syncCatalogUrl({ query: '', category: '', sort, filters: emptySpecificationFilters })
  }

  function retryCatalog(categoryName: string) {
    catalogRequests.current.get(categoryName)?.controller.abort()
    catalogRequests.current.delete(categoryName)
    setCatalogs((current) => ({
      ...current,
      [categoryName]: { key: '', products: [], total: 0, error: '' },
    }))
    setCatalogRetry((attempt) => attempt + 1)
  }

  function changeCategory(nextCategory: string) {
    setCategory(nextCategory)
    if (nextCategory) {
      setRequestedCategories((current) => Array.from(new Set([...current, nextCategory])))
    }
    setSpecificationFilters({
      ...emptySpecificationFilters,
      min_price: specificationFilters.min_price,
      max_price: specificationFilters.max_price,
    })
    setAppliedSpecificationFilters({
      ...emptySpecificationFilters,
      min_price: appliedSpecificationFilters.min_price,
      max_price: appliedSpecificationFilters.max_price,
    })
    syncCatalogUrl({
      query: submittedQuery,
      category: nextCategory,
      sort,
      filters: { ...emptySpecificationFilters, min_price: appliedSpecificationFilters.min_price, max_price: appliedSpecificationFilters.max_price },
    })
  }

  function chooseCategory(item: string) {
    changeCategory(category === item ? '' : item)
    setMenuOpen(false)
    document.getElementById('products')?.scrollIntoView({ behavior: 'smooth' })
  }

  async function loadMoreProducts(categoryName: string) {
    const currentCatalog = catalogs[categoryName]
    const loadingKey = JSON.stringify([catalogKey, categoryName])
    if (!currentCatalog || currentCatalog.key !== catalogKey || loadingMore[loadingKey]) return

    const nextPage = Math.floor(currentCatalog.products.length / 5) + 1
    const params = new URLSearchParams({ per_page: '5', page: String(nextPage), sort, category: categoryName })
    if (submittedQuery) params.set('q', submittedQuery)
    Object.entries(appliedSpecificationFilters).forEach(([key, value]) => {
      if (value) params.set(key, value)
    })

    setLoadingMore((current) => ({ ...current, [loadingKey]: true }))
    try {
      const response = await fetch(`${apiUrl}/products?${params}`)
      if (!response.ok) throw new Error(`Không tải được sản phẩm (HTTP ${response.status}).`)
      const result = await response.json() as CatalogResponse
      setCatalogs((current) => {
        const latest = current[categoryName]
        if (!latest || latest.key !== catalogKey) return current
        return {
          ...current,
          [categoryName]: {
            ...latest,
            products: [...latest.products, ...result.data],
            total: result.meta.total,
            error: '',
          },
        }
      })
    } catch (reason) {
      setCatalogs((current) => {
        const latest = current[categoryName]
        if (!latest || latest.key !== catalogKey) return current
        return {
          ...current,
          [categoryName]: {
            ...latest,
            error: describeError(reason, 'Đã xảy ra lỗi khi tải thêm sản phẩm.'),
          },
        }
      })
    } finally {
      setLoadingMore((current) => ({ ...current, [loadingKey]: false }))
    }
  }

  function saveDeliveryCity(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const city = locationDraft.trim()
    if (!city) return
    localStorage.setItem('techstore-delivery-city', city)
    setDeliveryCity(city)
    setLocationOpen(false)
  }

  function openProduct(event: MouseEvent<HTMLAnchorElement>, slug: string) {
    event.preventDefault()
    const params = new URLSearchParams(window.location.search)
    params.set('product', slug)
    window.history.pushState(null, '', `${window.location.pathname}?${params}${window.location.hash}`)
    setSelectedSlug(slug)
    setDetailImageIndex(0)
  }

  function closeProduct() {
    const params = new URLSearchParams(window.location.search)
    params.delete('product')
    const search = params.toString()
    window.history.replaceState(null, '', `${window.location.pathname}${search ? `?${search}` : ''}${window.location.hash}`)
    setSelectedSlug(null)
  }

  function toggleFavorite(product: Product) {
    setFavoriteProducts((current) => current.some((item) => item.id === product.id)
      ? current.filter((item) => item.id !== product.id)
      : [...current, product])
  }

  function toggleComparison(product: Product) {
    setCompareProducts((current) => {
      if (current.some((item) => item.id === product.id)) return current.filter((item) => item.id !== product.id)
      if (current.length >= 4) {
        setCommerceNotice('So sánh tối đa 4 sản phẩm cùng lúc.')
        return current
      }
      return [...current, product]
    })
  }

  function openSuggestedProduct(product: Product) {
    const params = new URLSearchParams(window.location.search)
    params.set('product', product.slug)
    window.history.pushState(null, '', `${window.location.pathname}?${params}${window.location.hash}`)
    setSelectedSlug(product.slug)
    setDetailImageIndex(0)
    setSearchSuggestions([])
  }

  async function addToCart(productId: string, selectedProduct?: Product) {
    setCommerceNotice('')
    if (!authToken || guestCartNeedsMerge) {
      const product = selectedProduct
        ?? Object.values(catalogs).flatMap((item) => item.products).find((item) => item.id === productId)
        ?? detailProduct
      if (!product) {
        setCommerceNotice('Không tìm thấy thông tin sản phẩm để thêm vào giỏ.')
        return
      }
      const existing = cart.items.find((item) => item.product_id === productId)
      const quantity = (existing?.quantity ?? 0) + 1
      if (quantity > product.stock || quantity > 20) {
        setCommerceNotice('Số lượng trong giỏ đã đạt giới hạn tồn kho.')
        return
      }
      const item: CartItem = {
        product_id: product.id,
        slug: product.slug,
        name: product.name,
        price: product.price,
        stock: product.stock,
        quantity,
        line_total: product.price * quantity,
      }
      const items = existing
        ? cart.items.map((current) => current.product_id === productId ? item : current)
        : [...cart.items, item]
      setCart({ items, total: items.reduce((total, current) => total + current.line_total, 0) })
      setCommerceNotice('Đã thêm sản phẩm vào giỏ hàng.')
      return
    }

    try {
      const result = await apiRequest<{ data: CartData }>('/cart/items', authToken, {
        method: 'POST',
        body: JSON.stringify({ product_id: productId }),
      })
      setCart(result.data)
      setCommerceNotice('Đã thêm sản phẩm vào giỏ hàng.')
    } catch (reason) {
      setCommerceNotice(reason instanceof Error ? reason.message : 'Không thể thêm sản phẩm vào giỏ.')
    }
  }

  function authenticated(token: string, account: Customer) {
    setGuestMergeInProgress(true)
    const guestItems = cart.items.map(({ product_id, quantity }) => ({ product_id, quantity }))
    setGuestCartNeedsMerge(guestItems.length > 0)
    const mergeRequest = guestItems.length > 0
      ? apiRequest<{ data: CartData }>('/cart/merge', token, {
          method: 'POST',
          body: JSON.stringify({ items: guestItems }),
        })
      : apiRequest<{ data: CartData }>('/cart', token)
    let mergeSucceeded = false
    mergeRequest
      .then((result) => {
        mergeSucceeded = true
        sessionStorage.setItem('techstore-token', token)
        setAuthToken(token)
        setCustomer(account)
        setGuestCartNeedsMerge(false)
        setCart(result.data)
        localStorage.removeItem(guestCartKey)
        setCommerceNotice(`Xin chào ${account.name}!`)
      })
      .catch((reason: unknown) => {
        sessionStorage.removeItem('techstore-token')
        setAuthToken(null)
        setCustomer(null)
        setGuestCartNeedsMerge(false)
        setCommerceNotice(reason instanceof Error ? reason.message : 'Không thể đồng bộ giỏ hàng.')
      })
      .finally(() => {
        setGuestMergeInProgress(false)
        if (pendingCheckout && mergeSucceeded) {
          setPendingCheckout(false)
          setResumeCheckout(true)
          setCartOpen(false)
        } else if (pendingCheckout) {
          setPendingCheckout(false)
          setCartOpen(true)
        }
        setPendingProductId(null)
      })
  }

  async function logout() {
    if (!authToken) return
    try {
      await apiRequest('/auth/logout', authToken, { method: 'POST' })
      sessionStorage.removeItem('techstore-token')
      setAuthToken(null)
      setCustomer(null)
      setCart({ items: [], total: 0 })
      setCommerceNotice('Bạn đã đăng xuất.')
    } catch (reason) {
      setCommerceNotice(reason instanceof Error ? reason.message : 'Không thể đăng xuất.')
    }
  }

  async function updateCartQuantity(productId: string, quantity: number) {
    if (!authToken || guestCartNeedsMerge) {
      const items = cart.items.map((item) => item.product_id === productId
        ? { ...item, quantity, line_total: item.price * quantity }
        : item)
      setCart({ items, total: items.reduce((total, item) => total + item.line_total, 0) })
      return
    }
    const result = await apiRequest<{ data: CartData }>(`/cart/items/${encodeURIComponent(productId)}`, authToken, {
      method: 'PATCH',
      body: JSON.stringify({ quantity }),
    })
    setCart(result.data)
  }

  async function removeCartItem(productId: string) {
    if (!authToken || guestCartNeedsMerge) {
      const items = cart.items.filter((item) => item.product_id !== productId)
      setCart({ items, total: items.reduce((total, item) => total + item.line_total, 0) })
      return
    }
    const result = await apiRequest<{ data: CartData }>(`/cart/items/${encodeURIComponent(productId)}`, authToken, {
      method: 'DELETE',
    })
    setCart(result.data)
  }

  async function requireCheckoutAuthentication() {
    if (!authToken) {
      setPendingCheckout(true)
      setCartOpen(false)
      openAuthDialog()
      return
    }
    if (!guestCartNeedsMerge) return
    try {
      const result = await apiRequest<{ data: CartData }>('/cart/merge', authToken, {
        method: 'POST',
        body: JSON.stringify({ items: cart.items.map(({ product_id, quantity }) => ({ product_id, quantity })) }),
      })
      setCart(result.data)
      setGuestCartNeedsMerge(false)
      localStorage.removeItem(guestCartKey)
      setCartOpen(false)
      setResumeCheckout(true)
    } catch (reason) {
      setCommerceNotice(reason instanceof Error ? reason.message : 'Không thể đồng bộ giỏ hàng.')
    }
  }

  async function submitProductReview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!detailProduct) return
    if (!authToken) {
      setReviewMessage('Vui lòng đăng nhập để gửi đánh giá đã mua hàng.')
      openAuthDialog()
      return
    }
    try {
      await apiRequest(`/products/${encodeURIComponent(detailProduct.id)}/reviews`, authToken, {
        method: 'POST',
        body: JSON.stringify({ rating: reviewRating, content: reviewContent }),
      })
      setReviewContent('')
      setReviewMessage('Đánh giá đã được ghi nhận.')
      const result = await apiRequest<{ data: ProductReview[]; meta: { average_rating: number; review_count: number; demo_review_count?: number } }>(
        `/products/${encodeURIComponent(detailProduct.slug)}/reviews`,
      )
      setProductReviews(result.data)
      setReviewSummary({ average_rating: result.meta.average_rating, review_count: result.meta.review_count, demo_review_count: result.meta.demo_review_count ?? 0 })
    } catch (reason) {
      setReviewMessage(reason instanceof Error ? reason.message : 'Không thể gửi đánh giá.')
    }
  }

  function renderProductGrid(items: Product[]) {
    return (
      <div className="product-grid">
        {items.map((product, index) => {
          const productName = product.name?.trim() || t('Sản phẩm đang cập nhật')
          const productSlug = product.slug || product.id
          return (
            <article className="product-card" key={product.id || `${productName}-${index}`}>
              <a className={`product-image image-tone-${index % 4}`} href={`?product=${encodeURIComponent(productSlug ?? '')}`} onClick={(event) => openProduct(event, productSlug ?? '')} aria-label={`${language === 'vi' ? 'Xem' : 'View'} ${productName}`}>
                {productPhoto({ ...product, name: productName }, undefined, index < 2 ? 'eager' : 'lazy')}
                <span className="image-arrow" aria-hidden="true">{t('Xem chi tiết')} <b>↗</b></span>
                {product.price > 0 && product.list_price !== null && product.list_price > product.price && (
                  <span className="discount-badge" aria-label={language === 'vi' ? `Ưu đãi, giảm ${Math.round((1 - product.price / product.list_price) * 100)} phần trăm` : `Offer, ${Math.round((1 - product.price / product.list_price) * 100)}% off`}>
                    {language === 'vi' ? 'Ưu đãi · Giảm' : 'Offer ·'} {Math.round((1 - product.price / product.list_price) * 100)}%
                  </span>
                )}
              </a>
              <div className="product-meta"><span>{product.category ? translateCategory(language, product.category) : t('Sản phẩm')}</span><span>{product.brand}</span></div>
              <h3><a aria-label={`${t('Xem chi tiết')} ${productName}`} href={`?product=${encodeURIComponent(productSlug ?? '')}`} onClick={(event) => openProduct(event, productSlug ?? '')}>{productName}</a></h3>
              <div className="product-trust" aria-label={t('Thông tin đánh giá và bảo hành')}>
                {product.review_count
                  ? <span className="product-rating" aria-label={language === 'vi' ? `Đánh giá ${product.average_rating?.toFixed(1)} trên 5 từ ${product.review_count} khách hàng` : `${product.average_rating?.toFixed(1)} out of 5 from ${product.review_count} reviews`}>★ {product.average_rating?.toFixed(1)} · {product.review_count} {language === 'vi' ? 'đánh giá' : 'reviews'}</span>
                  : <span>{t('Chưa có đánh giá')}</span>}
                {product.sold_count ? <span>{product.sold_count} {language === 'vi' ? 'đã bán' : 'sold'}</span> : null}
                <span>{t('Bảo hành tra cứu theo serial')}</span>
              </div>
              <div className="product-footer">
                <div className="product-price-block">
                  <strong className="current-price">{product.price > 0 ? `${new Intl.NumberFormat(numberLocale).format(product.price)} ₫` : t('Đang cập nhật giá')}</strong>
                  {product.price > 0 && product.list_price !== null && product.list_price > product.price && (
                    <>
                      <del>{new Intl.NumberFormat(numberLocale).format(product.list_price)} ₫</del>
                      <span className="product-savings">{t('Tiết kiệm')} {new Intl.NumberFormat(numberLocale).format(product.list_price - product.price)} ₫</span>
                    </>
                  )}
                </div>
                <span className="stock-status"><i className={product.purchasable ? 'stock-available' : 'stock-unavailable'} />{product.purchasable ? `${language === 'vi' ? 'Còn' : 'In stock:'} ${product.stock} ${t('sản phẩm')}` : product.stock <= 0 ? t('Hết hàng') : t('Chưa mở bán')}</span>
              </div>
              <button className="add-cart-button" type="button" onClick={() => void addToCart(product.id, product)} disabled={!product.purchasable}>{product.purchasable ? t('Thêm vào giỏ') : t('Chưa thể mua')}</button>
              <div className="product-quick-actions">
                <button type="button" aria-label={favoriteProducts.some((item) => item.id === product.id) ? `${language === 'vi' ? 'Bỏ yêu thích' : 'Remove from favorites'} ${productName}` : `${language === 'vi' ? 'Thêm yêu thích' : 'Add to favorites'} ${productName}`} aria-pressed={favoriteProducts.some((item) => item.id === product.id)} onClick={() => toggleFavorite(product)}>
                  {favoriteProducts.some((item) => item.id === product.id) ? `♥ ${t('Đã lưu')}` : `♡ ${t('Yêu thích')}`}
                </button>
                <button type="button" aria-label={compareProducts.some((item) => item.id === product.id) ? `${language === 'vi' ? 'Bỏ so sánh' : 'Remove comparison'} ${productName}` : `${t('So sánh')} ${productName}`} aria-pressed={compareProducts.some((item) => item.id === product.id)} onClick={() => toggleComparison(product)}>
                  {compareProducts.some((item) => item.id === product.id) ? `✓ ${t('So sánh')}` : `⇄ ${t('So sánh')}`}
                </button>
              </div>
            </article>
          )
        })}
      </div>
    )
  }

  return (
    <div className="store" lang={language}>
      <a className="skip-link" href="#products">{t('Bỏ qua điều hướng, đến danh sách sản phẩm')}</a>
      <AppearanceSettings language={language} />
      <div className="service-bar">
        <div className="site-container service-inner">
          <span><b>✦</b> {t('TechStore — chọn thiết bị dễ dàng hơn')}</span>
          <div className="service-links">
            <a href="#products">{t('Danh mục sản phẩm')}</a>
            <button type="button" onClick={() => openSupportChat('Tư vấn cấu hình')}>{t('Hỗ trợ chọn cấu hình')}</button>
            <button type="button" onClick={() => openSupportChat()}>{t('Liên hệ & hỗ trợ')}</button>
          </div>
        </div>
      </div>

      <header className="masthead">
        <div className="site-container masthead-inner">
          <a className="brand-mark" href="/" aria-label={language === 'vi' ? 'TechStore trang chủ' : 'TechStore home'}>
            <img src="/techstore-mark.svg" alt="" fetchPriority="high" />
            <span>TECH<span>STORE</span></span>
          </a>
          <div className="category-menu-wrap">
            <button className={`category-trigger ${menuOpen ? 'is-open' : ''}`} onClick={() => setMenuOpen((open) => !open)} aria-label={t('Danh mục sản phẩm')} aria-expanded={menuOpen}>
              <span className="category-trigger-icon">☷</span>
              <span>{t('Danh mục')}</span>
              <span className="chevron">⌄</span>
            </button>
            {menuOpen && (
              <div className="category-popover">
                <strong>{t('Khám phá danh mục')}</strong>
                {categories.map((item) => (
                  <button key={item} onClick={() => chooseCategory(item)}>
                    <span>{categoryIcons[item] ?? '◇'}</span>{item}<span className="category-arrow">›</span>
                  </button>
                ))}
              </div>
            )}
          </div>
          <form className="header-search" onSubmit={search} role="search">
            <span aria-hidden="true">⌕</span>
            <input
              aria-label={t('Tìm kiếm sản phẩm')}
              placeholder={t('Bạn muốn tìm sản phẩm gì?')}
              value={query}
              onChange={(event) => {
                const nextQuery = event.target.value
                setQuery(nextQuery)
                if (nextQuery.trim().length < 2 || nextQuery.trim() === submittedQuery) {
                  setSearchSuggestions([])
                  setSearchSuggestionError('')
                }
              }}
            />
            {query && <button className="search-clear" type="button" aria-label={language === 'vi' ? 'Xóa nội dung tìm kiếm' : 'Clear search'} onClick={() => setQuery('')}>×</button>}
            <button className="search-submit" type="submit" aria-label={t('Tìm kiếm')}>{t('Tìm kiếm')}</button>
            {(searchSuggestions.length > 0 || searchSuggestionError) && (
              <div className="search-suggestions" role="listbox" aria-label={t('Gợi ý sản phẩm')}>
                {searchSuggestions.map((product) => (
                  <button type="button" role="option" aria-selected="false" key={product.id} onClick={() => openSuggestedProduct(product)}>
                    <span>{product.name}<small>{product.brand || product.category}</small></span>
                    <strong>{product.price > 0 ? `${new Intl.NumberFormat(language === 'vi' ? 'vi-VN' : 'en-US').format(product.price)} ₫` : t('Liên hệ')}</strong>
                  </button>
                ))}
                {searchSuggestionError && <p role="alert">{t(searchSuggestionError)}</p>}
              </div>
            )}
          </form>
          <button className="header-action location-action" type="button" aria-label={`${t('Khu vực giao hàng')}: ${deliveryCity || t('chưa chọn')}`} title={deliveryCity || t('Chọn tỉnh/thành')} onClick={() => { setLocationDraft(deliveryCity); setLocationOpen(true) }}>
            <span className="action-icon">⌖</span><span><small>{t('Khu vực giao hàng')}</small><b>{deliveryCity || t('Chọn tỉnh/thành')}</b></span>
          </button>
          <div className="account-controls">
            <button className="header-action account-action" type="button" onClick={() => customer ? void logout() : openAuthDialog()} aria-label={customer ? t('Đăng xuất') : t('Đăng nhập')}>
              <span className="action-icon">♙</span><span><small>{customer ? `${t('Xin chào')}, ${customer.name}` : t('Xin chào')}</small><b>{customer ? t('Đăng xuất') : t('Đăng nhập')}</b></span>
            </button>
          </div>
          {customer && <button className="orders-header-action" type="button" onClick={openOrdersDialog}>{t('Lịch sử mua hàng')}</button>}
          <button className="cart-action" type="button" aria-label={language === 'vi' ? `Mở giỏ hàng (${cart.items.length} sản phẩm)` : `Open cart (${cart.items.length} products)`} onClick={openCartDialog}>
            <span className="cart-icon">♧</span><span>{t('Giỏ hàng')}</span>{cart.items.length > 0 && <b className="cart-count">{cart.items.length}</b>}
          </button>
          <div className="language-switch-wrap" ref={languageMenuRef}>
            <button className="language-trigger" type="button" aria-label={language === 'vi' ? 'Chọn ngôn ngữ: Tiếng Việt' : 'Select language: English'} aria-haspopup="menu" aria-expanded={languageMenuOpen} onClick={() => setLanguageMenuOpen((open) => !open)}>
              <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7">
                <circle cx="12" cy="12" r="10" />
                <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20" />
              </svg>
              <span>{language === 'vi' ? 'Tiếng Việt' : 'English'}</span>
              <svg className="language-chevron" aria-hidden="true" viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="m3 6 5 5 5-5" />
              </svg>
            </button>
            {languageMenuOpen && <div className="language-menu" role="menu" aria-label={language === 'vi' ? 'Chọn ngôn ngữ' : 'Choose language'}>
              <button type="button" role="menuitemradio" aria-checked={language === 'vi'} onClick={() => { setLanguage('vi'); setLanguageMenuOpen(false) }}>Tiếng Việt</button>
              <button type="button" role="menuitemradio" aria-checked={language === 'en'} onClick={() => { setLanguage('en'); setLanguageMenuOpen(false) }}>English</button>
            </div>}
          </div>
        </div>
      </header>

      <nav className="quick-nav">
        <div className="site-container quick-nav-inner">
          <span className="nav-caption">{t('MUA SẮM THEO NHU CẦU')}</span>
          {featuredCategories.map((item) => (
            <button className={category === item ? 'quick-nav-active' : ''} key={item} onClick={() => chooseCategory(item)}>
              <span>{categoryIcons[item] ?? '◇'}</span>{translateCategory(language, item)}
            </button>
          ))}
          <a className="quick-nav-more" href="#products">{t('Tất cả sản phẩm')} <span>→</span></a>
        </div>
      </nav>

      <main className="site-container">
        <section className="home-layout" aria-label={language === 'vi' ? 'Khám phá TechStore' : 'Explore TechStore'}>
          <aside className="category-rail">
            <div className="rail-heading"><span>☷</span> {t('DANH MỤC SẢN PHẨM')}</div>
            {featuredCategories.map((item) => (
              <button className={category === item ? 'rail-item rail-item-active' : 'rail-item'} key={item} onClick={() => chooseCategory(item)}>
                <span className="rail-icon">{categoryIcons[item] ?? '◇'}</span><span>{translateCategory(language, item)}</span><span className="rail-arrow">›</span>
              </button>
            ))}
            {categoriesError && <span className="rail-note" role="alert">{t(categoriesError)}</span>}
            <a className="rail-all" href="#products">{t('Xem tất cả danh mục')} <span>→</span></a>
          </aside>

          <section className="hero-banner" aria-label={t('Giới thiệu TechStore')}>
            <div className="banner-grid" aria-hidden="true" />
            <div className="banner-copy">
              <span className="banner-kicker"><i /> {language === 'vi' ? activeSlide.label : ['TECHSTORE SELECTION', 'FIND WHAT FITS', 'CLEAR FROM THE START'][heroSlide]}</span>
              <h1>{language === 'vi' ? activeSlide.title : ['Great technology.', 'Find your fit.', 'Clear from the start.'][heroSlide]}<br /><span>{language === 'vi' ? activeSlide.emphasis : ['Easy choices.', 'Enjoy every moment.', 'Shop with confidence.'][heroSlide]}</span></h1>
              <p>{language === 'vi' ? activeSlide.description : [
                'Explore devices for work, study, and everything you love.',
                'Filter by category and find the right fit for the way you use your device.',
                'Explore the complete product catalog, sourced directly from the store database.',
              ][heroSlide]}</p>
              <a className="banner-cta" href="#products">{language === 'vi' ? 'Khám phá ngay' : 'Explore now'} <span>→</span></a>
              <div className="banner-progress" aria-label={t('Chọn banner giới thiệu')}>
                {heroSlides.map((slide, index) => (
                  <button
                    key={slide.label}
                    type="button"
                    className={heroSlide === index ? 'active' : ''}
                    aria-label={language === 'vi' ? `Hiển thị banner ${index + 1}: ${slide.label}` : `Show banner ${index + 1}`}
                    aria-current={heroSlide === index ? 'true' : undefined}
                    onClick={() => setHeroSlide(index)}
                  />
                ))}
              </div>
            </div>
            <div className="banner-art" aria-hidden="true">
              <div className="banner-halo" />
              <div className="floating-orb orb-left" />
              <div className="floating-orb orb-right" />
              <div className="laptop-illustration">
                <div className="laptop-screen">
                  <div className="laptop-light" />
                  <img src="/techstore-mark.svg" alt="" />
                  <span>FIND YOUR<br /><b>NEXT GEAR</b></span>
                </div>
                <div className="laptop-keyboard" />
              </div>
              <div className="art-label label-top">CURATED FOR YOU <span>✳</span></div>
              <div className="art-label label-bottom"><span className="label-dot" /> {t('CHỌN LỌC · MINH BẠCH · TIN CẬY')}</div>
            </div>
            <button className="banner-arrow banner-previous" type="button" aria-label={t('Banner trước')} onClick={() => setHeroSlide((heroSlide + heroSlides.length - 1) % heroSlides.length)}>‹</button>
            <button className="banner-arrow banner-next" type="button" aria-label={t('Banner tiếp theo')} onClick={() => setHeroSlide((heroSlide + 1) % heroSlides.length)}>›</button>
            <div className="banner-index"><b>{String(heroSlide + 1).padStart(2, '0')}</b><span> / 03</span></div>
          </section>

          <aside className="promo-rail">
            <div className="promo-card promo-blue">
              <div className="promo-orbit" aria-hidden="true">✳</div>
              <span className="promo-eyebrow">{t('CHỌN MUA AN TÂM')}</span>
              <h2>{t('Giá rõ ràng.')}<br />{t('Thông tin minh bạch.')}</h2>
              <a href="#products">{t('Tìm hiểu sản phẩm')} <span>→</span></a>
            </div>
            <div className="promo-card promo-light">
              <span className="promo-icon">✦</span>
              <span className="promo-eyebrow">{t('CHAT VỚI CHATBOT')}</span>
              <h2>{t('Cần tư vấn?')}<br />{t('TechStore luôn sẵn sàng.')}</h2>
              <button type="button" onClick={() => openSupportChat()}>{t('Bắt đầu trò chuyện')} <span aria-hidden="true">→</span></button>
            </div>
          </aside>
        </section>

        <section className="trust-strip" aria-label={t('Cam kết TechStore')}>
          <div><span className="trust-symbol">✓</span><span><b>{t('Thông tin rõ ràng')}</b><small>{t('Giá và tồn kho theo dữ liệu cửa hàng')}</small></span></div>
          <div><span className="trust-symbol">◇</span><span><b>{t('Đầy đủ danh mục')}</b><small>{t('Hiển thị sản phẩm từ cơ sở dữ liệu')}</small></span></div>
          <div><span className="trust-symbol">✳</span><span><b>{t('Tư vấn tận tâm')}</b><small>{t('Tìm thiết bị hợp với nhu cầu')}</small></span></div>
        </section>

        <section className="catalog-section" id="products">
          {commerceNotice && <div className="commerce-toast" role="status"><span>{commerceNotice}</span><button type="button" aria-label={t('Đóng thông báo')} onClick={() => setCommerceNotice('')}>×</button></div>}
          <div className="section-heading">
            <div>
              <span className="eyebrow">{t('KHÁM PHÁ CỬA HÀNG')}</span>
              <h2>{category ? translateCategory(language, category) : t('Tất cả sản phẩm')}</h2>
            </div>
            <div className="catalog-controls">
              <label className="filter-select">
                <span className="visually-hidden">{t('Lọc theo danh mục')}</span>
                <select value={category} onChange={(event) => changeCategory(event.target.value)}>
                  <option value="">{t('Tất cả danh mục')}</option>
                  {categories.map((item) => <option key={item} value={item}>{translateCategory(language, item)}</option>)}
                </select>
              </label>
              <label className="filter-select sort-select">
                <span className="visually-hidden">{t('Sắp xếp sản phẩm')}</span>
                <select value={sort} onChange={(event) => selectSort(event.target.value)}>
                  <option value="featured">{t('Sắp xếp: Nổi bật')}</option>
                  <option value="price_asc">{t('Giá tăng dần')}</option>
                  <option value="price_desc">{t('Giá giảm dần')}</option>
                  <option value="name">{t('Tên A–Z')}</option>
                  <option value="best_selling">{t('Bán chạy')}</option>
                  <option value="top_rated">{t('Đánh giá cao')}</option>
                </select>
              </label>
              <button className={showFavorites ? 'favorites-filter active' : 'favorites-filter'} type="button" aria-pressed={showFavorites} onClick={() => setShowFavorites((value) => !value)}>
                ♡ {t('Yêu thích')} ({favoriteProducts.length})
              </button>
            </div>
          </div>

          <form className="spec-filter-panel" onSubmit={applySpecificationFilters}>
            <div className="spec-filter-title"><strong>{category ? `${language === 'vi' ? 'Lọc' : 'Filter'} ${translateCategory(language, category)}` : language === 'vi' ? 'Lọc sản phẩm' : 'Filter products'}</strong><span>{language === 'vi' ? 'Thương hiệu và thông số được lấy từ sản phẩm trong cơ sở dữ liệu' : 'Brands and specifications are based on products in the store database'}</span></div>
            <div className="spec-filter-controls">
              {currentFacets?.brands.length ? (
                <label><span>{t('Thương hiệu')}</span><select value={specificationFilters.brand} onChange={(event) => setSpecificationFilters({ ...specificationFilters, brand: event.target.value })}>
                  <option value="">{t('Tất cả thương hiệu')}</option>
                  {currentFacets.brands.map((option) => <option key={option.value} value={option.value}>{option.value} ({option.count})</option>)}
                </select></label>
              ) : null}
              {currentFacets?.specifications.map((facet) => (
                facet.options.length > 0 && <label key={facet.key}><span>{facet.label}</span><select value={specificationFilters[facet.key]} onChange={(event) => setSpecificationFilters({ ...specificationFilters, [facet.key]: event.target.value })}>
                  <option value="">{language === 'vi' ? 'Tất cả' : 'All'}</option>
                  {facet.options.map((option) => <option key={option.value} value={option.value}>{option.value} ({option.count})</option>)}
                </select></label>
              ))}
              <label><span>{t('Giá từ (₫)')}</span><input type="number" min="0" step="100000" value={specificationFilters.min_price} onChange={(event) => setSpecificationFilters({ ...specificationFilters, min_price: event.target.value })} /></label>
              <label><span>{t('Đến (₫)')}</span><input type="number" min="0" step="100000" value={specificationFilters.max_price} onChange={(event) => setSpecificationFilters({ ...specificationFilters, max_price: event.target.value })} /></label>
              <label className="quick-filter-checkbox"><span>{t('Ưu đãi')}</span><span><input type="checkbox" checked={specificationFilters.on_sale === '1'} onChange={(event) => setSpecificationFilters({ ...specificationFilters, on_sale: event.target.checked ? '1' : '' })} /> {t('Đang giảm giá')}</span></label>
              <label><span>{t('Đánh giá tối thiểu')}</span><select value={specificationFilters.min_rating} onChange={(event) => setSpecificationFilters({ ...specificationFilters, min_rating: event.target.value })}>
                <option value="">{t('Mọi mức đánh giá')}</option>
                <option value="3">{t('Từ 3 sao')}</option>
                <option value="4">{t('Từ 4 sao')}</option>
                <option value="4.5">{t('Từ 4,5 sao')}</option>
              </select></label>
            </div>
            <div className="spec-filter-actions">
              <button className="filter-reset" type="button" onClick={clearSpecificationFilters}>{t('Xóa bộ lọc')}</button>
              <button className="filter-apply" type="submit">{t('Áp dụng bộ lọc')}</button>
            </div>
          </form>

          {categoriesError && <p className="tool-error" role="alert">{t(categoriesError)} <button type="button" className="inline-retry" onClick={() => { setCategoriesError(''); setCategoriesRetry((attempt) => attempt + 1) }}>{t('Thử tải lại')}</button></p>}
          {facetsError && <p className="tool-error" role="alert">{t(facetsError)}</p>}
          <div className="results-line" role="status" aria-live="polite">
            <span>{showFavorites ? `${favoriteProducts.length} ${t('sản phẩm yêu thích')}` : loading ? t('Đang tải sản phẩm…') : !category && requestedCategories.length < categories.length ? language === 'vi' ? `${total} sản phẩm đã tải · Cuộn để tải thêm danh mục` : `${total} products loaded · Scroll to load more categories` : `${total} ${t('sản phẩm')}`}</span>
            {submittedQuery && <button className="clear-search" onClick={() => {
              setQuery('')
              setSubmittedQuery('')
              syncCatalogUrl({ query: '', category, sort, filters: appliedSpecificationFilters })
            }}>{t('Xóa tìm kiếm')} ×</button>}
          </div>

          {!showFavorites && !loading && !hasProducts && !visibleCatalogs.some(({ catalog }) => catalog?.error)
            && (Boolean(category) || (hasCatalogCriteria && visibleCatalogs.length > 0)) && (
            <div className="state-message empty-state" role="status" aria-live="polite">
              <span className="empty-icon" aria-hidden="true">◇</span>
              <h3>{t('Không tìm thấy sản phẩm phù hợp')}</h3>
              <p>{submittedQuery ? language === 'vi' ? `Không có kết quả cho “${submittedQuery}”. ` : `No results for “${submittedQuery}”. ` : ''}{t('Thử từ khóa khác, chọn danh mục khác hoặc bỏ bớt bộ lọc.')}</p>
              <div className="empty-state-actions">
                {submittedQuery && <button className="empty-reset-button" type="button" onClick={() => {
                  setQuery('')
                  setSubmittedQuery('')
                  syncCatalogUrl({ query: '', category, sort, filters: appliedSpecificationFilters })
                }}>{t('Xóa tìm kiếm')}</button>}
                {Object.values(appliedSpecificationFilters).some(Boolean) && <button className="empty-reset-button" type="button" onClick={clearSpecificationFilters}>{t('Xóa bộ lọc')}</button>}
                <button className="empty-reset-button" type="button" onClick={resetCatalogCriteria}>{t('Xem tất cả sản phẩm')}</button>
              </div>
            </div>
          )}
          {showFavorites ? favoriteProducts.length ? (
            <section className="product-group" aria-label={t('Danh sách yêu thích')}>
              <h3 className="product-group-title">{t('Danh sách yêu thích')}</h3>
              {renderProductGrid(favoriteProducts)}
            </section>
          ) : <div className="state-message empty-state"><h3>{t('Chưa có sản phẩm yêu thích')}</h3><p>{t('Nhấn biểu tượng trái tim trên sản phẩm để lưu vào danh sách này.')}</p></div> : visibleCategories.length > 0 && (
            <>
              {renderedCatalogs.map(({ name, catalog: groupCatalog }) => {
                const loadingKey = JSON.stringify([catalogKey, name])
                return <section className="product-group" data-catalog-category={name} key={name} aria-label={name}>
                  <h3 className="product-group-title">{translateCategory(language, name)}</h3>
                  {groupCatalog?.error && <p className="tool-error" role="alert">{t(groupCatalog.error)} <button type="button" className="inline-retry" onClick={() => retryCatalog(name)}>{language === 'vi' ? `Tải lại danh mục ${name}` : `Reload ${translateCategory(language, name)} category`}</button></p>}
                  {groupCatalog?.products.length
                    ? renderProductGrid(groupCatalog.products)
                    : requestedCategories.includes(name) || category === name
                      ? <div className="product-grid" aria-label={`${language === 'vi' ? 'Đang tải' : 'Loading'} ${translateCategory(language, name)}`}>{Array.from({ length: 4 }, (_, index) => <div className="skeleton-card" key={index} />)}</div>
                      : <div className="catalog-deferred" aria-label={language === 'vi' ? `Danh mục ${name} sẽ tải khi cuộn` : `${translateCategory(language, name)} will load as you scroll`}>{language === 'vi' ? `Cuộn xuống để tải sản phẩm ${name}` : `Scroll down to load ${translateCategory(language, name)}`}</div>}
                  {(groupCatalog?.products.length ?? 0) < (groupCatalog?.total ?? 0) && (
                    <div className="load-more">
                      <button
                        className="load-more-button"
                        aria-label={language === 'vi' ? `Xem thêm sản phẩm ${name}` : `Load more ${translateCategory(language, name)} products`}
                        disabled={loadingMore[loadingKey] ?? false}
                        onClick={() => void loadMoreProducts(name)}
                      >
                        {loadingMore[loadingKey] ? '…' : language === 'vi' ? 'Xem thêm' : 'Load more'}
                      </button>
                    </div>
                  )}
                </section>
              })}
            </>
          )}
        </section>

        <section className="about-strip" id="about">
          <span>{t('TECHSTORE — CÔNG NGHỆ GẦN HƠN VỚI BẠN')}</span>
          <p>{t('Trợ lý sẽ phân loại nhu cầu, ngân sách và mục đích sử dụng để tìm sản phẩm đang bán; cần thêm trợ giúp, bạn có thể chuyển tiếp cho nhân viên.')}</p>
          <div className="about-actions">
            <button type="button" onClick={() => openSupportChat('Tư vấn cấu hình')}>{t('Bắt đầu tư vấn cấu hình')}</button>
            <button type="button" onClick={() => openSupportChat()}>{t('Chat với hỗ trợ')}</button>
          </div>
          <span className="about-star" aria-hidden="true">✳</span>
        </section>
      </main>

      {selectedSlug && (
        <div className="detail-backdrop" role="presentation" onClick={closeProduct}>
          <section className="detail-dialog" role="dialog" aria-modal="true" aria-label={t('Chi tiết sản phẩm')} onClick={(event) => event.stopPropagation()}>
            <button className="detail-close" onClick={closeProduct} aria-label={`${t('Đóng')} ${t('Chi tiết sản phẩm')}`}>×</button>
            {!detailProduct && !detailError && <p className="detail-status">{t('Đang tải thông tin sản phẩm…')}</p>}
            {detailError && <p className="detail-status error-state" role="alert">{t(detailError)}</p>}
            {detailProduct && (
              <div className="detail-content">
                <div className="detail-image">
                  {productPhoto({ ...detailProduct, images: detailProduct.images[detailImageIndex] ? [detailProduct.images[detailImageIndex]] : [] }, undefined, 'eager', '(max-width: 560px) 90vw, 360px')}
                  {detailProduct.images.length > 1 && <div className="detail-thumbnails" aria-label={t('Ảnh sản phẩm')}>
                    {detailProduct.images.map((image, index) => <button type="button" key={`${image.url}-${index}`} className={detailImageIndex === index ? 'active' : ''} aria-label={`${language === 'vi' ? 'Xem ảnh' : 'View image'} ${index + 1}`} aria-pressed={detailImageIndex === index} onClick={() => setDetailImageIndex(index)}><img src={image.url} alt={image.alt ?? ''} loading="lazy" /></button>)}
                  </div>}
                </div>
                <div className="detail-copy">
                  <span className="eyebrow">{detailProduct.category && translateCategory(language, detailProduct.category)} {detailProduct.brand && `· ${detailProduct.brand}`}</span>
                  <h2>{detailProduct.name}</h2>
                  <strong>{detailProduct.price > 0 ? `${new Intl.NumberFormat(numberLocale).format(detailProduct.price)} ₫` : t('Đang cập nhật giá')}</strong>
                  {detailProduct.list_price !== null && detailProduct.list_price > detailProduct.price && (
                    <>
                      <del>{new Intl.NumberFormat(numberLocale).format(detailProduct.list_price)} ₫</del>
                      <span className="detail-discount">{t('Giảm')} {Math.round((1 - detailProduct.price / detailProduct.list_price) * 100)}% · {t('Tiết kiệm')} {new Intl.NumberFormat(numberLocale).format(detailProduct.list_price - detailProduct.price)} ₫</span>
                    </>
                  )}
                  <p>{detailProduct.description || t('Thông tin mô tả đang được cập nhật.')}</p>
                  <span className="detail-stock"><i className={detailProduct.purchasable ? 'stock-available' : 'stock-unavailable'} />{detailProduct.purchasable ? `${t('Còn hàng')} · ${detailProduct.stock} ${t('sản phẩm')}` : detailProduct.stock <= 0 ? t('Hết hàng') : t('Sản phẩm chưa mở bán')}</span>
                  <div className="product-delivery-info">
                    <p><strong>{t('Bảo hành')}</strong><span>{t('Thời hạn bảo hành được kích hoạt và tra cứu theo serial sau khi đơn hàng hoàn tất.')}</span></p>
                    <p><strong>{t('Giao hàng')}</strong><span>{t('Phí và ngày giao dự kiến sẽ được cửa hàng xác nhận sau khi tiếp nhận đơn.')}</span></p>
                  </div>
                  <div className="detail-actions">
                    <button type="button" aria-pressed={favoriteProducts.some((item) => item.id === detailProduct.id)} onClick={() => toggleFavorite(detailProduct)}>{favoriteProducts.some((item) => item.id === detailProduct.id) ? `♥ ${t('Đã yêu thích')}` : `♡ ${t('Yêu thích')}`}</button>
                    <button type="button" aria-pressed={compareProducts.some((item) => item.id === detailProduct.id)} onClick={() => toggleComparison(detailProduct)}>{compareProducts.some((item) => item.id === detailProduct.id) ? `✓ ${t('Đang so sánh')}` : `⇄ ${t('So sánh')}`}</button>
                  </div>
                  <button className="primary-button detail-add-cart" type="button" disabled={!detailProduct.purchasable} onClick={() => { void addToCart(detailProduct.id, detailProduct); closeProduct() }}>{detailProduct.purchasable ? t('Thêm vào giỏ hàng') : t('Chưa thể mua')}</button>
                  <section className="product-reviews" aria-label={t('Đánh giá sản phẩm')}>
                    <h3>{t('Đánh giá khách hàng')}</h3>
                    <p>{reviewSummary.review_count ? `${reviewSummary.average_rating}/5 · ${reviewSummary.review_count} ${language === 'vi' ? 'đánh giá khách hàng' : 'customer reviews'}` : t('Chưa có đánh giá khách hàng thật')}</p>
                    {reviewSummary.demo_review_count > 0 && <p>{language === 'vi' ? `Hiển thị ${reviewSummary.demo_review_count} đánh giá mẫu; nội dung này không phải phản hồi khách hàng thật.` : `Showing ${reviewSummary.demo_review_count} sample reviews; these are not real customer feedback.`}</p>}
                    {reviewMessage && <p role="status">{t(reviewMessage)}</p>}
                    {productReviews.map((review, index) => (
                      <article className="product-review" key={`${review.created_at}-${index}`}>
                        <strong>{review.name || 'Khách hàng'}{review.is_demo && ' · MẪU'} · {review.rating}/5</strong>
                        <p>{review.content}</p>
                        <time>{new Date(review.created_at).toLocaleDateString(numberLocale)}</time>
                      </article>
                    ))}
                    {customer && (
                      <form className="review-form" onSubmit={(event) => void submitProductReview(event)}>
                        <label>{t('Chấm điểm')}
                          <select value={reviewRating} onChange={(event) => setReviewRating(Number(event.target.value))}>
                            {[5, 4, 3, 2, 1].map((rating) => <option key={rating} value={rating}>{rating} {language === 'vi' ? 'sao' : 'stars'}</option>)}
                          </select>
                        </label>
                        <label>{t('Nội dung đánh giá')}<textarea required minLength={3} maxLength={2000} value={reviewContent} onChange={(event) => setReviewContent(event.target.value)} /></label>
                        <button className="primary-button" type="submit">{t('Gửi đánh giá')}</button>
                      </form>
                    )}
                  </section>
                  {detailProduct.specifications && Object.entries(detailProduct.specifications).filter(([, value]) => value).length > 0 && (
                    <dl className="product-specifications">
                      {Object.entries(detailProduct.specifications)
                        .filter(([key, value]) => key !== 'raw' && value)
                        .map(([key, value]) => (
                          <div key={key}><dt>{key}</dt><dd>{value}</dd></div>
                        ))}
                    </dl>
                  )}
                </div>
              </div>
            )}
            {detailProduct && relatedProducts.length > 0 && <section className="related-products"><h3>{t('Sản phẩm liên quan')}</h3>{renderProductGrid(relatedProducts)}</section>}
          </section>
        </div>
      )}

      {compareProducts.length > 0 && <aside className="compare-tray" aria-label={language === 'vi' ? 'Thanh so sánh sản phẩm' : 'Product comparison tray'}>
        <span>{t('Đang chọn so sánh:')} <strong>{compareProducts.length}/4</strong></span>
        <button type="button" onClick={() => setCompareOpen(true)} disabled={compareProducts.length < 2}>{t('So sánh ngay')}</button>
        <button type="button" onClick={() => setCompareProducts([])}>{t('Xóa')}</button>
      </aside>}
      {compareOpen && <Suspense fallback={null}>
        <ProductComparisonDialog products={compareProducts} onClose={() => setCompareOpen(false)} language={language} />
      </Suspense>}

      <footer className="site-footer">
        <div className="footer-main">
          <div className="footer-brand-column">
            <a className="brand-mark" href="/"><img src="/techstore-mark.svg" alt="" /><span>TECH<span>STORE</span></span></a>
            <p>{t('Thiết bị công nghệ cho học tập, công việc và giải trí. Chọn đúng sản phẩm, an tâm theo dõi đơn hàng.')}</p>
          </div>
          <nav className="footer-column" aria-label={language === 'vi' ? 'Khám phá TechStore' : 'Explore TechStore'}>
            <strong>{t('Khám phá')}</strong>
            <a href="#products">{t('Tất cả sản phẩm')}</a>
            <a href="#products" onClick={() => changeCategory('Laptop')}>Laptop</a>
            <button type="button" onClick={() => customer ? openOrdersDialog() : openAuthDialog()}>{t('Lịch sử mua hàng')}</button>
          </nav>
          <nav className="footer-column" aria-label={t('Hỗ trợ khách hàng')}>
            <strong>{t('Hỗ trợ khách hàng')}</strong>
            <button type="button" onClick={() => openSupportChat()}>{t('Liên hệ tư vấn')}</button>
            <button type="button" onClick={() => openSupportChat('Hỗ trợ đơn hàng')}>{t('Hỗ trợ đơn hàng')}</button>
            <button type="button" onClick={() => openSupportChat('Chính sách đổi trả')}>{t('Đổi trả và bảo hành')}</button>
          </nav>
          <div className="footer-column">
            <strong>TechStore</strong>
            <a href="#about">{t('Về chúng tôi')}</a>
            <a href="/admin">{t('Quản trị viên')}</a>
          </div>
        </div>
        <div className="footer-bottom">
          <span>© 2026 TechStore. {t('Đồ án cửa hàng thiết bị công nghệ.')}</span>
          <span>{t('Giá và tình trạng sản phẩm được cập nhật theo dữ liệu cửa hàng.')}</span>
        </div>
      </footer>
      {(commerceDialogsLoaded || authOpen || cartOpen || pendingCheckout || resumeCheckout) && <Suspense fallback={null}>
        <CommerceDialogs
          language={language}
          token={authToken}
          customer={customer}
          cart={cart}
          deliveryCity={deliveryCity}
          authOpen={authOpen}
          cartOpen={cartOpen}
          ordersRequestId={ordersRequestId}
          onCloseAuth={() => setAuthOpen(false)}
          onCloseCart={() => setCartOpen(false)}
          onAuthenticated={authenticated}
          onRequireAuthCheckout={() => { void requireCheckoutAuthentication() }}
          guestCartNeedsMerge={guestCartNeedsMerge}
          resumeCheckout={resumeCheckout}
          onCheckoutResumed={checkoutResumed}
          onCartChanged={setCart}
          onUpdateQuantity={updateCartQuantity}
          onRemoveItem={removeCartItem}
        />
      </Suspense>}
      <ChatWidget language={language} />
      {locationOpen && (
        <div className="commerce-backdrop" role="presentation" onClick={() => setLocationOpen(false)}>
          <section className="commerce-dialog location-dialog" role="dialog" aria-modal="true" aria-labelledby="location-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={() => setLocationOpen(false)} aria-label={t('Đóng')}>×</button>
            <span className="eyebrow">{t('ĐỊA CHỈ NHẬN HÀNG')}</span>
            <h2 id="location-title">{t('Chọn khu vực giao hàng')}</h2>
            <p className="commerce-intro">{t('Chọn tỉnh/thành để điền nhanh khi đặt hàng. Khu vực chưa dùng để tính phí giao hàng hay tồn kho.')}</p>
            <form className="commerce-form" onSubmit={saveDeliveryCity}>
              <label>{t('Tỉnh / thành phố')}<input required maxLength={100} autoComplete="address-level1" value={locationDraft} onChange={(event) => setLocationDraft(event.target.value)} placeholder={t('Ví dụ: Thành phố Hồ Chí Minh')} /></label>
              <button className="primary-button" type="submit">{t('Lưu khu vực')}</button>
            </form>
          </section>
        </div>
      )}
    </div>
  )
}

export default App

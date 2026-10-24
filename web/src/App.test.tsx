// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import axe from 'axe-core'
import App from './App'
import { AppearanceSettings } from './AppearanceSettings'
import { ChatWidget } from './ChatWidget'

const laptop = {
  id: 'LAPTOP01',
  slug: 'laptop-a',
  name: 'Laptop A',
  category: 'Laptop',
  brand: 'Brand A',
  description: null,
  price: 0,
  list_price: 0,
  stock: 0,
  purchasable: false,
  images: [
    { url: 'http://localhost/missing-laptop.jpg', alt: 'Laptop A' },
    { url: 'http://localhost/next-matched-image.jpg', alt: 'Laptop A' },
  ],
}
const phone = {
  ...laptop,
  id: 'PHONE01',
  slug: 'phone-a',
  name: 'Điện thoại A',
  category: 'Điện thoại',
  images: [],
}

function jsonResponse(data: unknown): Response {
  return new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

async function expectBasicAccessibility(target: HTMLElement): Promise<void> {
  const results = await axe.run(target, {
    runOnly: { type: 'rule', values: ['button-name', 'label', 'link-name', 'landmark-one-main', 'region'] },
  })
  expect(results.violations).toEqual([])
}

beforeEach(() => {
  HTMLElement.prototype.scrollIntoView = vi.fn()
  localStorage.clear()
  window.history.replaceState(null, '', window.location.pathname)
  vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
    const url = new URL(String(input))
    if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Điện thoại', 'Laptop'] })
    if (url.pathname.endsWith('/filters')) {
      return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
    }
    if (url.pathname.endsWith('/products')) {
      const isLaptop = url.searchParams.get('category') === 'Laptop'
      const perPage = Number(url.searchParams.get('per_page') ?? 10)
      return jsonResponse({
        data: Array.from({ length: perPage }, (_, index) => {
          const product = isLaptop ? laptop : phone
          return {
            ...product,
            id: `${product.id}-${index + 1}`,
            slug: `${product.slug}-${index + 1}`,
            name: index === 0 ? product.name : `${product.name.replace(/ [A-Z]$/, '')} ${index + 1}`,
          }
        }),
        meta: { current_page: Number(url.searchParams.get('page') ?? 1), last_page: 1, per_page: perPage, total: isLaptop ? 392 : 4191 },
      })
    }
    return jsonResponse({ data: null })
  }))
})

afterEach(() => {
  cleanup()
  localStorage.clear()
  sessionStorage.clear()
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('appearance settings', () => {
  it('applies and persists the selected theme and palette', () => {
    render(<AppearanceSettings />)

    fireEvent.click(screen.getByRole('button', { name: 'Tùy chỉnh giao diện' }))
    fireEvent.click(screen.getByRole('button', { name: 'Tối' }))
    fireEvent.click(screen.getByRole('button', { name: 'Màu chủ đạo: Tím' }))

    expect(document.documentElement.dataset.theme).toBe('dark')
    expect(document.documentElement.dataset.palette).toBe('violet')
    expect(localStorage.getItem('techstore-appearance')).toBe(JSON.stringify({ mode: 'dark', paletteId: 'violet' }))
    expect(screen.getByRole('button', { name: 'Tối' }).getAttribute('aria-pressed')).toBe('true')
  })
})

describe('storefront catalog', () => {
  it('switches the storefront language and persists the selection', async () => {
    const { unmount } = render(<App />)

    fireEvent.click(screen.getByRole('button', { name: 'Chọn ngôn ngữ: Tiếng Việt' }))
    fireEvent.click(screen.getByRole('menuitemradio', { name: 'English' }))

    expect(document.documentElement.lang).toBe('en')
    expect(localStorage.getItem('techstore-language')).toBe('en')
    expect(screen.getByText('EXPLORE THE STORE')).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Select language: English' })).toBeTruthy()

    unmount()
    render(<App />)

    expect(document.documentElement.lang).toBe('en')
    expect(screen.getByRole('button', { name: 'Select language: English' })).toBeTruthy()
  })

  it('opens order history directly from the header for signed-in customers', async () => {
    sessionStorage.setItem('techstore-token', 'test-token')
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/auth/me')) {
        return jsonResponse({ data: { id: 'KH0000000001', name: 'Test Customer', email: 'customer@example.test', phone: '0901234567' } })
      }
      if (url.pathname.endsWith('/cart')) return jsonResponse({ data: { items: [], total: 0 } })
      if (url.pathname.endsWith('/orders') || url.pathname.endsWith('/notifications') || url.pathname.endsWith('/returns')) {
        return jsonResponse({ data: [] })
      }
      if (url.pathname.endsWith('/vouchers')) return jsonResponse({ data: [] })
      if (url.pathname.endsWith('/education-discount')) return jsonResponse({ data: { verified: false } })
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        return jsonResponse({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Lịch sử mua hàng' }))

    expect(await screen.findByRole('heading', { name: 'Đơn hàng của tôi' })).toBeTruthy()
    expect(await screen.findByText('Bạn chưa có đơn hàng nào.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Mã giảm giá' }))
    expect(await screen.findByText('Hiện chưa có mã giảm giá khả dụng.')).toBeTruthy()
  })

  it('provides a keyboard-accessible skip link to the product catalog', () => {
    render(<App />)

    const skipLink = screen.getByRole('link', { name: 'Bỏ qua điều hướng, đến danh sách sản phẩm' })
    skipLink.focus()
    expect(document.activeElement).toBe(skipLink)
    expect(skipLink.getAttribute('href')).toBe('#products')
  })

  it('saves favorites and compares selected products', async () => {
    render(<App />)
    await screen.findByText('4583 sản phẩm')

    const favoriteButtons = await screen.findAllByRole('button', { name: /Thêm yêu thích/ })
    fireEvent.click(favoriteButtons[0])
    expect(JSON.parse(localStorage.getItem('techstore-favorites') ?? '[]')).toHaveLength(1)
    fireEvent.click(screen.getByRole('button', { name: /Yêu thích \(1\)/ }))
    expect(screen.getByRole('heading', { name: 'Danh sách yêu thích' })).toBeTruthy()
    expect(screen.getAllByRole('button', { name: /Bỏ yêu thích/ })).toHaveLength(1)

    fireEvent.click(screen.getByRole('button', { name: /Yêu thích \(1\)/ }))
    const compareButtons = await screen.findAllByRole('button', { name: /^So sánh / })
    fireEvent.click(compareButtons[0])
    fireEvent.click(compareButtons[1])
    fireEvent.click(screen.getByRole('button', { name: 'So sánh ngay' }))
    expect(await screen.findByRole('dialog', { name: 'Đặt cạnh nhau để chọn dễ hơn' })).toBeTruthy()
  })

  it('keeps catalog filters in the URL for navigation and reloads', async () => {
    render(<App />)
    await screen.findByText('4583 sản phẩm')

    fireEvent.change(screen.getByLabelText('Sắp xếp sản phẩm'), { target: { value: 'price_asc' } })
    expect(new URLSearchParams(window.location.search).get('sort')).toBe('price_asc')
    fireEvent.change(screen.getByLabelText('Lọc theo danh mục'), { target: { value: 'Laptop' } })
    expect(new URLSearchParams(window.location.search).get('category')).toBe('Laptop')
    expect(new URLSearchParams(window.location.search).get('sort')).toBe('price_asc')
  })

  it('applies the sale and rating filters and persists them in the URL', async () => {
    render(<App />)
    await screen.findByText('4583 sản phẩm')

    fireEvent.click(screen.getByRole('checkbox', { name: /Đang giảm giá/ }))
    fireEvent.change(screen.getByLabelText('Đánh giá tối thiểu'), { target: { value: '4' } })
    fireEvent.click(screen.getByRole('button', { name: 'Áp dụng bộ lọc' }))

    expect(new URLSearchParams(window.location.search).get('on_sale')).toBe('1')
    expect(new URLSearchParams(window.location.search).get('min_rating')).toBe('4')
    await waitFor(() => {
      const requests = vi.mocked(fetch).mock.calls.map(([input]) => new URL(String(input)))
      expect(requests.some((url) => url.searchParams.get('on_sale') === '1' && url.searchParams.get('min_rating') === '4')).toBe(true)
    })
  })

  it('passes the catalog page WCAG accessibility checks', async () => {
    const { container } = render(<App />)
    await screen.findByText('4583 sản phẩm')

    await expectBasicAccessibility(container)
  })

  it('shows Laptop separately from the remaining catalog', async () => {
    render(<App />)

    expect(await screen.findByText('4583 sản phẩm')).toBeTruthy()
    expect(screen.getByRole('region', { name: 'Laptop' }).textContent).toContain('Laptop A')
    expect(screen.getByRole('region', { name: 'Điện thoại' }).textContent).toContain('Điện thoại A')
    expect(screen.queryByText('0', { exact: true })).toBeNull()
  })

  it('highlights sale prices and product trust information on product cards', async () => {
    const discountedLaptop = {
      ...laptop,
      images: [{ url: 'http://localhost/laptop.webp', alt: 'Laptop A', srcset: 'http://localhost/laptop-320.webp 320w, http://localhost/laptop-960.webp 960w' }],
      price: 9000000,
      list_price: 12000000,
      stock: 4,
      purchasable: true,
      average_rating: 4.6,
      review_count: 12,
      sold_count: 38,
    }
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        return jsonResponse({
          data: [discountedLaptop],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    const card = await screen.findByRole('article')
    expect(within(card).getByRole('img', { name: 'Laptop A' }).getAttribute('srcset')).toContain('320w')
    expect(within(card).getByText('Ưu đãi · Giảm 25%')).toBeTruthy()
    expect(within(card).getByText('9.000.000 ₫')).toBeTruthy()
    expect(within(card).getByText('12.000.000 ₫')).toBeTruthy()
    expect(within(card).getByText('Tiết kiệm 3.000.000 ₫')).toBeTruthy()
    expect(within(card).getByText('★ 4.6 · 12 đánh giá')).toBeTruthy()
    expect(within(card).getByText('38 đã bán')).toBeTruthy()
    expect(within(card).getByText('Bảo hành tra cứu theo serial')).toBeTruthy()
    expect(within(card).getByText('Còn 4 sản phẩm')).toBeTruthy()
  })

  it('shows useful empty-search actions only after all matching categories load', async () => {
    window.history.replaceState(null, '', `${window.location.pathname}?q=not-found`)
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Điện thoại', 'Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        const data = url.searchParams.has('q') ? [] : [{ ...laptop, id: 'LAPTOP-RESULT' }]
        return jsonResponse({
          data,
          meta: { current_page: 1, last_page: 1, per_page: 10, total: data.length },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    expect(await screen.findByText('Không có kết quả cho “not-found”. Thử từ khóa khác, chọn danh mục khác hoặc bỏ bớt bộ lọc.')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: 'Xóa tìm kiếm' }))

    await waitFor(() => expect(new URLSearchParams(window.location.search).has('q')).toBe(false))
    expect((await screen.findAllByText('Laptop A')).length).toBeGreaterThan(0)
  })

  it('offers a retry when a category request fails', async () => {
    let productRequests = 0
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        productRequests += 1
        if (productRequests === 1) return new Response(JSON.stringify({ message: 'Tạm thời không khả dụng' }), { status: 503 })
        return jsonResponse({
          data: [{ ...laptop, id: 'LAPTOP-RETRY' }],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Tải lại danh mục Laptop' }))
    expect((await screen.findAllByText('Laptop A')).length).toBeGreaterThan(0)
    expect(productRequests).toBe(2)
  })

  it('shows all 14 categories separately with 10 products each', async () => {
    const categoryNames = [
      'Laptop', 'Điện thoại', 'Âm thanh', 'Màn hình', 'Máy ảnh / Camera', 'Điện gia dụng',
      'Phụ kiện', 'Chuột / Bàn phím', 'Máy tính bảng', 'Tivi', 'Đồng hồ', 'Phụ kiện laptop',
      'Máy in', 'Khác',
    ]
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: categoryNames })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        const categoryName = url.searchParams.get('category') ?? ''
        const product = categoryName === 'Laptop' ? laptop : { ...phone, category: categoryName }
        const perPage = Number(url.searchParams.get('per_page') ?? 10)
        return jsonResponse({
          data: Array.from({ length: perPage }, (_, index) => ({
            ...product,
            id: `${categoryName}-${index + 1}`,
            slug: `${categoryName}-${index + 1}`,
          })),
          meta: { current_page: 1, last_page: 2, per_page: perPage, total: 15 },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    await screen.findByRole('region', { name: 'Khác' })
    await waitFor(() => {
      expect(document.querySelectorAll('.product-group')).toHaveLength(14)
      for (const name of categoryNames) {
        expect(screen.getByRole('region', { name }).querySelectorAll('.product-card')).toHaveLength(10)
        expect(screen.getByRole('button', { name: `Xem thêm sản phẩm ${name}` })).toBeTruthy()
      }
    })
  })

  it('hides category sections that have no products matching the current filters', async () => {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop', 'Điện thoại'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        const categoryName = url.searchParams.get('category')
        return jsonResponse({
          data: categoryName === 'Laptop' ? [] : [{ ...phone, id: 'PHONE01' }],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: categoryName === 'Laptop' ? 0 : 1 },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    expect(await screen.findByRole('region', { name: 'Điện thoại' })).toBeTruthy()
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Laptop' })).toBeNull())
    expect(screen.queryByRole('heading', { name: 'Không tìm thấy sản phẩm phù hợp' })).toBeNull()
  })

  it('loads five more products only in the clicked category section', async () => {
    render(<App />)

    const laptopGroup = await screen.findByRole('region', { name: 'Laptop' })
    const phoneGroup = await screen.findByRole('region', { name: 'Điện thoại' })
    await waitFor(() => {
      expect(laptopGroup.querySelectorAll('.product-card')).toHaveLength(10)
      expect(phoneGroup.querySelectorAll('.product-card')).toHaveLength(10)
    })

    fireEvent.click(screen.getByRole('button', { name: 'Xem thêm sản phẩm Laptop' }))
    await waitFor(() => expect(laptopGroup.querySelectorAll('.product-card')).toHaveLength(15))
    expect(phoneGroup.querySelectorAll('.product-card')).toHaveLength(10)
  })

  it('loads the next five products for a filtered category', async () => {
    const requestedPages: Array<{ page: string | null; perPage: string | null; category: string | null }> = []
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Điện thoại', 'Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        const page = url.searchParams.get('page')
        const perPage = url.searchParams.get('per_page')
        const selectedCategory = url.searchParams.get('category')
        requestedPages.push({ page, perPage, category: selectedCategory })
        const pageNumber = Number(page)
        const count = Number(perPage)
        const offset = pageNumber === 1 ? 0 : (pageNumber - 1) * count
        return jsonResponse({
          data: Array.from({ length: count }, (_, index) => ({
            ...phone,
            id: `PHONE-${offset + index + 1}`,
            slug: `phone-${offset + index + 1}`,
            name: `Điện thoại ${offset + index + 1}`,
          })),
          meta: { current_page: pageNumber, last_page: 4, per_page: count, total: 20 },
        })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    fireEvent.change(await screen.findByLabelText('Lọc theo danh mục'), { target: { value: 'Điện thoại' } })
    const categoryGroup = await screen.findByRole('region', { name: 'Điện thoại' })
    await waitFor(() => expect(categoryGroup.querySelectorAll('.product-card')).toHaveLength(10))
    fireEvent.click(screen.getByRole('button', { name: 'Xem thêm sản phẩm Điện thoại' }))
    await waitFor(() => expect(categoryGroup.querySelectorAll('.product-card')).toHaveLength(15))
    expect(requestedPages).toContainEqual({ page: '3', perPage: '5', category: 'Điện thoại' })
    fireEvent.click(screen.getByRole('button', { name: 'Xem thêm sản phẩm Điện thoại' }))
    await waitFor(() => expect(categoryGroup.querySelectorAll('.product-card')).toHaveLength(20))
    expect(requestedPages).toContainEqual({ page: '4', perPage: '5', category: 'Điện thoại' })
    expect(screen.queryByRole('button', { name: 'Xem thêm sản phẩm Điện thoại' })).toBeNull()
  })

  it('uses the next matched product image or a clear fallback when images fail', async () => {
    render(<App />)

    const image = (await screen.findAllByRole('img', { name: 'Laptop A' }))[0]
    fireEvent.error(image)

    await waitFor(() => {
      expect(screen.getAllByRole('img', { name: 'Laptop A' })[0].getAttribute('src')).toBe('http://localhost/next-matched-image.jpg')
    })
    fireEvent.error(screen.getAllByRole('img', { name: 'Laptop A' })[0])
    await waitFor(() => {
      expect(screen.getAllByRole('img', { name: 'Ảnh chưa có: Laptop A' }).length).toBeGreaterThan(0)
    })
  })

  it('keeps the support chat closed until requested and prevents purchasing unpublished products', async () => {
    render(<App />)

    const purchaseButtons = await screen.findAllByRole('button', { name: 'Chưa thể mua' })
    expect(purchaseButtons.every((button) => (button as HTMLButtonElement).disabled)).toBe(true)
    expect(screen.getByRole('button', { name: 'Tư vấn' })).toBeTruthy()
    expect(screen.queryByRole('region', { name: 'Trò chuyện với TechStore' })).toBeNull()
  })

  it('opens the shared chatbot with the configuration prompt from the service link', async () => {
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Hỗ trợ chọn cấu hình' }))
    expect(await screen.findByRole('region', { name: 'Trò chuyện với TechStore' })).toBeTruthy()
    expect((screen.getByRole('textbox', { name: 'Tin nhắn' }) as HTMLInputElement).value).toBe('Tư vấn cấu hình')
  })

  it('opens contact support in the chatbot and shows guided support topics', async () => {
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Liên hệ & hỗ trợ' }))
    const chat = await screen.findByRole('region', { name: 'Trò chuyện với TechStore' })
    expect(within(chat).getByRole('button', { name: 'Tư vấn cấu hình' })).toBeTruthy()
    expect(within(chat).getByRole('button', { name: 'Tìm sản phẩm' })).toBeTruthy()
    expect(within(chat).getByRole('button', { name: 'Hỗ trợ đơn hàng' })).toBeTruthy()
    expect(within(chat).getByRole('button', { name: 'Gặp nhân viên' })).toBeTruthy()
  })

  it('opens the chatbot from the home page chat promotion', async () => {
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Bắt đầu trò chuyện' }))
    expect(await screen.findByRole('region', { name: 'Trò chuyện với TechStore' })).toBeTruthy()
  })

  it('shows matching chatbot products with a working product detail action', async () => {
    const reply = 'Mình tìm thấy sản phẩm đang bán phù hợp.'
    const conversation = {
      id: '123e4567-e89b-42d3-a456-426614174000',
      status: 'bot' as const,
      messages: [
        { id: 1, sender: 'visitor' as const, message: 'Laptop' },
        { id: 2, sender: 'bot' as const, message: reply },
      ],
    }
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname === '/api/chat') {
        return jsonResponse({
          conversationId: conversation.id,
          status: 'bot',
          reply,
          products: [{ id: laptop.id, name: laptop.name, price: 12000000 }],
          conversation,
        })
      }
      if (url.pathname === `/api/chat/${conversation.id}`) return jsonResponse(conversation)
      if (url.pathname.endsWith(`/products/${laptop.id}/reviews`)) {
        return jsonResponse({ data: [], meta: { average_rating: 0, review_count: 0, demo_review_count: 0 } })
      }
      if (url.pathname.endsWith(`/products/${laptop.id}`)) return jsonResponse({ data: { ...laptop, price: 12000000, stock: 2, purchasable: true } })
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        return jsonResponse({ data: [laptop], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } })
      }
      return jsonResponse({ data: null })
    }))
    render(<App />)

    fireEvent.click(await screen.findByRole('button', { name: 'Tư vấn' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'Tin nhắn' }), { target: { value: 'Laptop' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gửi tin nhắn' }))

    const chat = await screen.findByRole('region', { name: 'Trò chuyện với TechStore' })
    fireEvent.click(await within(chat).findByRole('button', { name: 'Xem chi tiết Laptop A' }))
    const detailDialog = await screen.findByRole('dialog', { name: 'Chi tiết sản phẩm' })
    expect(within(detailDialog).getByRole('heading', { name: 'Laptop A' })).toBeTruthy()
  })

  it('uses the chatbot session capability to load conversation history', async () => {
    const conversationId = '123e4567-e89b-42d3-a456-426614174000'
    const sessionToken = 'a'.repeat(64)
    const conversation = { id: conversationId, status: 'bot' as const, messages: [] }
    const historyHeaders: HeadersInit[] = []
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input))
      if (url.pathname === '/api/chat') {
        return jsonResponse({ conversationId, sessionToken, status: 'bot', conversation, products: [] })
      }
      if (url.pathname === `/api/chat/${conversationId}`) {
        historyHeaders.push(init?.headers ?? {})
        return jsonResponse(conversation)
      }
      return jsonResponse({ data: [] })
    }))
    render(<ChatWidget />)

    fireEvent.click(screen.getByRole('button', { name: 'Tư vấn' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'Tin nhắn' }), { target: { value: 'Laptop' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gửi tin nhắn' }))

    await waitFor(() => expect(historyHeaders.length).toBeGreaterThan(0))
    expect(new Headers(historyHeaders[0]).get('Authorization')).toBe(`Bearer ${sessionToken}`)
    expect(sessionStorage.getItem('techstore-chat-access-token')).toBe(sessionToken)
  })

  it('shows a localized message when the chatbot cannot be reached', async () => {
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname === '/api/chat') throw new TypeError('Failed to fetch')
      return jsonResponse({ data: [] })
    }))
    render(<ChatWidget />)

    fireEvent.click(await screen.findByRole('button', { name: 'Tư vấn' }))
    fireEvent.change(screen.getByRole('textbox', { name: 'Tin nhắn' }), { target: { value: 'Laptop' } })
    fireEvent.click(screen.getByRole('button', { name: 'Gửi tin nhắn' }))

    expect(await screen.findByText('Chưa kết nối được chatbot. Vui lòng thử lại sau.')).toBeTruthy()
  })

  it('keeps a guest cart locally and asks for sign-in when checkout starts', async () => {
    const available = { ...laptop, price: 12000000, stock: 3, purchasable: true }
    const serverCart = {
      items: [{
        product_id: available.id,
        slug: available.slug,
        name: available.name,
        price: available.price,
        stock: available.stock,
        quantity: 1,
        line_total: available.price,
      }],
      total: available.price,
    }
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
      const url = new URL(String(input))
      if (url.pathname.endsWith('/auth/register')) {
        return jsonResponse({ token: 'guest-checkout-token', data: { id: 'KH0000000001', name: 'Test Customer', email: 'customer@example.test', phone: '0901234567' } })
      }
      if (url.pathname.endsWith('/cart/merge') || url.pathname.endsWith('/cart')) {
        return jsonResponse({ data: serverCart })
      }
      if (url.pathname.endsWith('/auth/me')) {
        return jsonResponse({ data: { id: 'KH0000000001', name: 'Test Customer', email: 'customer@example.test', phone: '0901234567' } })
      }
      if (url.pathname.endsWith('/addresses')) return jsonResponse({ data: [] })
      if (url.pathname.endsWith('/categories')) return jsonResponse({ data: ['Laptop'] })
      if (url.pathname.endsWith('/filters')) {
        return jsonResponse({ data: { brands: [], specifications: [], price: { min: null, max: null } } })
      }
      if (url.pathname.endsWith('/products')) {
        return jsonResponse({ data: [available], meta: { current_page: 1, last_page: 1, per_page: 12, total: 1 } })
      }
      return jsonResponse({ data: null })
    }))

    render(<App />)
    fireEvent.click((await screen.findAllByRole('button', { name: 'Thêm vào giỏ' }))[0])
    expect(localStorage.getItem('techstore-guest-cart')).toContain('LAPTOP01')

    fireEvent.click(screen.getByRole('button', { name: 'Mở giỏ hàng (1 sản phẩm)' }))
    fireEvent.click(await screen.findByRole('button', { name: `Tăng số lượng ${available.name}` }))
    await expectBasicAccessibility(screen.getByRole('dialog', { name: /Giỏ hàng/ }))
    expect((await screen.findAllByText('24.000.000 ₫')).length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: `Giảm số lượng ${available.name}` }))
    expect((await screen.findAllByText('12.000.000 ₫')).length).toBeGreaterThan(0)
    fireEvent.click(screen.getByRole('button', { name: 'Tiến hành đặt hàng' }))
    expect(await screen.findByRole('heading', { name: 'Đăng nhập' })).toBeTruthy()
    await expectBasicAccessibility(screen.getByRole('dialog', { name: 'Đăng nhập' }))
    fireEvent.click(screen.getByRole('button', { name: 'Đăng ký ngay' }))
    fireEvent.change(screen.getByLabelText('Họ và tên'), { target: { value: 'Test Customer' } })
    fireEvent.change(screen.getByLabelText('Tên đăng nhập'), { target: { value: 'customer' } })
    fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'customer@example.test' } })
    fireEvent.change(screen.getByLabelText('Số điện thoại'), { target: { value: '0901234567' } })
    fireEvent.change(screen.getByLabelText('Mật khẩu'), { target: { value: 'strong-password' } })
    fireEvent.change(screen.getByLabelText('Nhập lại mật khẩu'), { target: { value: 'strong-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'Đăng ký' }))
    expect(await screen.findByRole('heading', { name: 'Thông tin giao hàng' })).toBeTruthy()
    await expectBasicAccessibility(screen.getByRole('dialog', { name: 'Thông tin giao hàng' }))
    expect(screen.getByRole('list', { name: 'Các bước đặt hàng' }).textContent).toContain('Chọn thanh toán')
    expect(screen.getByText(/Tổng tiền hàng · chưa gồm phí giao/)).toBeTruthy()
    expect(screen.getByText(/Cửa hàng cần xác nhận phí giao hàng/)).toBeTruthy()
    await waitFor(() => expect(localStorage.getItem('techstore-guest-cart')).toBeNull())
  })

  it('lets guests change quantities and remove items from their local cart', async () => {
    const available = { ...laptop, price: 12000000, stock: 3, purchasable: true }
    localStorage.setItem('techstore-guest-cart', JSON.stringify({
      items: [{
        product_id: available.id,
        slug: available.slug,
        name: available.name,
        price: available.price,
        stock: available.stock,
        quantity: 1,
        line_total: available.price,
      }],
      total: available.price,
    }))
    render(<App />)
    fireEvent.click(await screen.findByRole('button', { name: 'Mở giỏ hàng (1 sản phẩm)' }))

    fireEvent.click(screen.getByRole('button', { name: `Tăng số lượng ${available.name}` }))
    expect((await screen.findAllByText('24.000.000 ₫')).length).toBeGreaterThan(0)
    expect(localStorage.getItem('techstore-guest-cart')).toContain('"quantity":2')

    fireEvent.click(screen.getByRole('button', { name: 'Xóa' }))
    expect(await screen.findByText('Giỏ hàng đang trống')).toBeTruthy()
    expect(localStorage.getItem('techstore-guest-cart')).toContain('"items":[]')
  })
})

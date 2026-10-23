import { expect, test } from '@playwright/test'

const productId = 'E2EPHONE01'
const orderId = 'ORDER-E2E-001'
const token = 'customer-e2e-token'
const adminToken = 'admin-e2e-token'
const product = {
  id: productId,
  slug: 'e2e-phone',
  name: 'Điện thoại kiểm thử',
  category: 'Điện thoại',
  brand: 'TechStore',
  description: 'Sản phẩm dùng trong kiểm thử luồng đặt hàng.',
  price: 5_000_000,
  list_price: null,
  stock: 5,
  purchasable: true,
  images: [],
}

test('customer checkout, voucher, admin payment confirmation, and order status', async ({ page }) => {
  let cart = { items: [] as { product_id: string; slug: string; name: string; price: number; stock: number; quantity: number; line_total: number }[], total: 0 }
  let paid = false
  let delivering = false
  let createdOrder = false

  await page.route('**/api/v1/**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname.replace('/api/v1', '')
    const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })

    if (path === '/categories') return json({ data: ['Điện thoại'] })
    if (path === '/filters') return json({ data: { brands: [], specifications: [], price: { min: 1, max: 9_000_000 } } })
    if (path === '/products' && request.method() === 'GET') return json({ data: [product], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } })
    if (path === '/auth/register') return json({ data: { id: 'CUSTOMER001', name: 'Khách kiểm thử', email: 'e2e@example.test', phone: '0901234567' }, token }, 201)
    if (path === '/auth/me') return json({ data: { id: 'CUSTOMER001', name: 'Khách kiểm thử', email: 'e2e@example.test', phone: '0901234567' } })
    if (path === '/returns' && request.method() === 'GET') return json({ data: [] })
    if (path === '/cart/merge' && request.method() === 'POST') {
      const body = request.postDataJSON() as { items: { product_id: string; quantity: number }[] }
      cart = {
        items: body.items.map((item) => ({ product_id: item.product_id, slug: product.slug, name: product.name, price: product.price, stock: product.stock, quantity: item.quantity, line_total: product.price * item.quantity })),
        total: body.items.reduce((sum, item) => sum + product.price * item.quantity, 0),
      }
      return json({ data: cart })
    }
    if (path === '/cart' && request.method() === 'GET') return json({ data: cart })
    if (path === '/addresses') return json({ data: [] })
    if (path === '/vouchers/validate') return json({ data: { code: 'E2E10', discount: 500_000, total: 4_500_000 } })
    if (path === '/orders' && request.method() === 'POST') {
      createdOrder = true
      cart = { items: [], total: 0 }
      return json({ data: { id: orderId, total: 4_500_000, replayed: false } }, 201)
    }
    if (path === '/orders' && request.method() === 'GET') return json({ data: createdOrder ? [{ ma_dh: orderId, ngay_dat: new Date().toISOString(), tong_tien: 4_500_000, trang_thai: delivering ? 'Dang Giao' : 'Cho xac nhan' }] : [] })
    if (path === `/orders/${orderId}`) return json({ data: {
      order: { ma_dh: orderId, ngay_dat: new Date().toISOString(), tong_tien: 4_500_000, trang_thai: delivering ? 'Dang Giao' : 'Cho xac nhan', ten_nguoi_nhan: 'Khách kiểm thử', dien_thoai_nguoi_nhan: '0901234567', dia_chi_giao_hang: '1 Đường Test, Quận 1, TP Hồ Chí Minh' },
      payment: { phuong_thuc: 'MoMo', trang_thai: paid ? 'Da Thanh Toan' : 'Chua Thanh Toan' },
      items: [{ ma_sp: productId, ten_sp: product.name, so_luong: 1, don_gia: product.price }],
      history: [],
    } })
    if (path === '/notifications') return json({ data: paid ? [{ id: 1, loai: 'payment.confirmed', tieu_de: 'Đã xác nhận thanh toán', noi_dung: `Cửa hàng đã xác nhận thanh toán cho đơn ${orderId}.`, ma_dh: orderId, read_at: null, created_at: new Date().toISOString() }] : [] })
    if (path === '/auth/admin/login') return json({ data: { id: 'ADMIN001', name: 'Admin kiểm thử', email: 'admin@example.test', role: 'chu_quan', branch_id: null }, token: adminToken })
    if (path === '/admin/products') return json({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    if (path === '/admin/orders' && request.method() === 'GET') return json({ data: createdOrder ? [{ ma_dh: orderId, ngay_dat: new Date().toISOString(), tong_tien: 4_500_000, trang_thai: paid ? 'Dang Giao' : 'Cho xac nhan', customer_email: 'e2e@example.test', customer_username: 'e2e-customer', payment_method: 'MoMo', payment_status: paid ? 'Da Thanh Toan' : 'Chua Thanh Toan' }] : [], meta: { current_page: 1, last_page: 1, total: createdOrder ? 1 : 0 } })
    if (path === `/admin/orders/${orderId}` && request.method() === 'PATCH') {
      const body = request.postDataJSON() as { action: string; status?: string }
      if (body.action === 'mark_paid') paid = true
      if (body.action === 'status' && body.status === 'Dang Giao') delivering = true
      return json({ message: 'Đã xác nhận thanh toán.' })
    }

    return json({ message: `Unexpected API request: ${request.method()} ${path}` }, 500)
  })

  await page.goto('/')
  await page.getByRole('button', { name: /Thêm vào giỏ/ }).first().click()
  await page.getByRole('button', { name: /Mở giỏ hàng/ }).click()
  await page.getByRole('button', { name: /Tiến hành đặt hàng/ }).click()
  await page.getByRole('button', { name: 'Đăng ký ngay' }).click()
  await page.getByLabel('Họ và tên').fill('Khách kiểm thử')
  await page.getByLabel('Tên đăng nhập').fill('e2e-customer')
  await page.getByLabel('Email').fill('e2e@example.test')
  await page.getByLabel('Số điện thoại').fill('0901234567')
  await page.getByLabel('Mật khẩu', { exact: true }).fill('strong-password')
  await page.getByLabel('Nhập lại mật khẩu').fill('strong-password')
  await page.getByRole('button', { name: 'Đăng ký', exact: true }).click()

  await expect(page.getByRole('heading', { name: 'Thông tin giao hàng' })).toBeVisible()
  await page.getByLabel('Địa chỉ', { exact: true }).fill('1 Đường Test')
  await page.getByLabel('Quận / huyện').fill('Quận 1')
  await page.getByLabel('Tỉnh / thành phố').fill('TP Hồ Chí Minh')
  await page.getByLabel('Tên địa chỉ').fill('Nhà')
  await page.getByLabel('Mã giảm giá').fill('E2E10')
  await page.getByRole('button', { name: 'Áp dụng mã' }).click()
  await expect(page.getByText('Tổng tiền sản phẩm')).toBeVisible()
  await expect(page.getByText('4.500.000 ₫').first()).toBeVisible()
  await page.getByLabel('Chuyển khoản Agribank qua QR').check()
  await expect(page.getByRole('img', { name: 'Mã QR chuyển khoản Agribank do cửa hàng cung cấp' })).toBeVisible()
  await page.getByLabel('MoMo qua mã QR').check()
  await expect(page.getByRole('img', { name: 'Mã QR MoMo do cửa hàng cung cấp' })).toBeVisible()
  await page.getByRole('button', { name: 'Xác nhận đặt hàng' }).click()
  await expect(page.getByText(orderId)).toBeVisible()

  await page.goto('/admin')
  await page.getByLabel('Tài khoản hoặc email').fill('admin01')
  await page.getByLabel('Mật khẩu').fill('admin-password')
  await page.getByRole('button', { name: 'Đăng nhập' }).click()
  await page.getByRole('button', { name: 'Đơn hàng', exact: true }).click()
  await expect(page.getByText(orderId)).toBeVisible()
  await page.getByRole('button', { name: 'Xác nhận đã thanh toán' }).click()
  await expect.poll(() => paid).toBe(true)
  await page.getByLabel(`Trạng thái đơn ${orderId}`).selectOption('Dang Giao')
  await page.getByRole('button', { name: 'Cập nhật' }).click()
  await expect.poll(() => delivering).toBe(true)

  await page.goto('/')
  await page.getByRole('button', { name: /Mở giỏ hàng/ }).click()
  await page.getByRole('button', { name: /Theo dõi đơn hàng của tôi/ }).click()
  await expect(page.getByText('Đã xác nhận thanh toán', { exact: true }).first()).toBeVisible()
  await page.getByRole('button', { name: 'Xem chi tiết' }).click()
  await expect(page.getByText(/Dang Giao/)).toBeVisible()
  await expect(page.getByText(/Da Thanh Toan/)).toBeVisible()
})

test('mobile storefront keeps product discovery and comparison usable', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 })
  const products = ['PHONEE2E01', 'PHONEE2E02'].map((id, index) => ({
    id,
    slug: `mobile-phone-${index + 1}`,
    name: `Điện thoại Mobile ${index + 1}`,
    category: 'Điện thoại',
    brand: 'TechStore',
    description: 'Sản phẩm kiểm thử giao diện điện thoại.',
    price: 5_000_000 + index * 500_000,
    list_price: null,
    stock: 4,
    purchasable: true,
    specifications: { ram: `${8 + index * 8} GB`, storage: '256 GB' },
    images: [],
  }))
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: { data: ['Điện thoại'] } })
    if (url.pathname.endsWith('/filters')) return route.fulfill({ json: { data: { brands: [], specifications: [], price: { min: 5_000_000, max: 5_500_000 } } } })
    if (url.pathname.endsWith('/products')) return route.fulfill({ json: { data: products, meta: { current_page: 1, last_page: 1, per_page: 10, total: 2 } } })
    return route.fulfill({ json: { message: 'Not found' }, status: 404 })
  })

  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Điện thoại' }).first()).toBeVisible()
  await page.getByRole('button', { name: 'So sánh Điện thoại Mobile 1' }).click()
  await page.getByRole('button', { name: 'So sánh Điện thoại Mobile 2' }).click()
  await page.getByRole('button', { name: 'So sánh ngay' }).click()
  await expect(page.getByRole('dialog', { name: 'Đặt cạnh nhau để chọn dễ hơn' })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})

test('catalog loads nearby categories first and defers the rest until scrolling', async ({ page }) => {
  const categories = ['Laptop', 'Điện thoại', 'Âm thanh', 'Màn hình', 'Máy ảnh', 'Tivi', 'Đồng hồ', 'Máy in']
  const loadedCategories = new Set<string>()
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: { data: categories } })
    if (url.pathname.endsWith('/filters')) return route.fulfill({ json: { data: { brands: [], specifications: [], price: { min: null, max: null } } } })
    if (url.pathname.endsWith('/products')) {
      const category = url.searchParams.get('category') ?? ''
      loadedCategories.add(category)
      return route.fulfill({
        json: {
          data: [{
            id: `CAT-${categories.indexOf(category)}`,
            slug: `catalog-${categories.indexOf(category)}`,
            name: `Sản phẩm ${category}`,
            category,
            brand: 'TechStore',
            description: null,
            price: 1_000_000,
            list_price: null,
            stock: 2,
            purchasable: true,
            images: [],
            average_rating: 4.5,
            review_count: 2,
            sold_count: 3,
          }],
          meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 },
        },
      })
    }
    return route.fulfill({ json: { data: null } })
  })

  await page.goto('/')
  await expect.poll(() => loadedCategories.size).toBeGreaterThanOrEqual(2)
  expect(loadedCategories.size).toBeLessThan(categories.length)
  const desktopColumns = await page.locator('.product-grid').first().evaluate((grid) => (
    getComputedStyle(grid).gridTemplateColumns.split(' ').length
  ))
  expect(desktopColumns).toBe(5)
  await expect(page.getByText('★ 4.5 · 2 đánh giá').first()).toBeVisible()
  await expect(page.getByText('3 đã bán').first()).toBeVisible()
  await expect(page.getByText('Bảo hành theo serial').first()).toBeVisible()
  for (const category of categories) {
    await page.locator(`[data-catalog-category="${category}"]`).scrollIntoViewIfNeeded()
    await expect.poll(() => loadedCategories.has(category), { timeout: 10_000 }).toBe(true)
  }
})

test('product dialog supports Escape and keeps keyboard focus inside', async ({ page }) => {
  const productData = {
    id: 'LAPTOP001',
    slug: 'laptop-test',
    name: 'Laptop kiểm thử bàn phím',
    category: 'Laptop',
    brand: 'TechStore',
    description: null,
    price: 10_000_000,
    list_price: 12_000_000,
    stock: 2,
    purchasable: true,
    images: [],
    average_rating: 0,
    review_count: 0,
    sold_count: 0,
  }
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: { data: ['Laptop'] } })
    if (url.pathname.endsWith('/filters')) return route.fulfill({ json: { data: { brands: [], specifications: [], price: { min: null, max: null } } } })
    if (url.pathname.endsWith('/reviews')) return route.fulfill({ json: { data: [], meta: { average_rating: 0, review_count: 0 } } })
    if (url.pathname.endsWith('/products/laptop-test')) return route.fulfill({ json: { data: productData } })
    if (url.pathname.endsWith('/products')) return route.fulfill({ json: { data: [productData], meta: { current_page: 1, last_page: 1, per_page: 10, total: 1 } } })
    return route.fulfill({ json: { data: null } })
  })

  await page.goto('/')
  const productLink = page.getByRole('link', { name: 'Xem Laptop kiểm thử bàn phím' })
  await productLink.click()
  const dialog = page.getByRole('dialog', { name: 'Chi tiết sản phẩm' })
  await expect(dialog).toBeVisible()
  await page.keyboard.press('Tab')
  await expect.poll(() => dialog.evaluate((element) => element.contains(document.activeElement))).toBe(true)
  await expect(dialog).toContainText('Giảm 17%')
  await page.keyboard.press('Escape')
  await expect(dialog).toHaveCount(0)
  await expect(productLink).toBeFocused()
})

test('empty search results explain next steps and reset active catalog filters', async ({ page }) => {
  const productData = {
    id: 'LAPTOP002',
    slug: 'laptop-reset',
    name: 'Laptop sau khi xóa bộ lọc',
    category: 'Laptop',
    brand: 'TechStore',
    description: null,
    price: 10_000_000,
    list_price: null,
    stock: 2,
    purchasable: true,
    images: [],
  }
  await page.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/categories')) return route.fulfill({ json: { data: ['Laptop'] } })
    if (url.pathname.endsWith('/filters')) return route.fulfill({ json: { data: { brands: [], specifications: [], price: { min: null, max: null } } } })
    if (url.pathname.endsWith('/products')) {
      const empty = url.searchParams.has('q') || url.searchParams.has('brand')
      return route.fulfill({ json: { data: empty ? [] : [productData], meta: { current_page: 1, last_page: 1, per_page: 10, total: empty ? 0 : 1 } } })
    }
    return route.fulfill({ json: { data: null } })
  })

  await page.goto('/?q=missing&category=Laptop&brand=Nope&min_price=9000000')
  await expect(page.getByRole('heading', { name: 'Không tìm thấy sản phẩm phù hợp' })).toBeVisible()
  await expect(page.getByText(/Thử từ khóa khác/)).toBeVisible()
  await page.getByRole('button', { name: 'Xóa từ khóa và bộ lọc' }).click()
  await expect(page).toHaveURL(/^(?!.*(?:q|category|brand|min_price)=)/)
  await expect(page.getByRole('heading', { name: productData.name })).toBeVisible()
})

import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import './App.css'
import { AppearanceSettings } from './AppearanceSettings'
import { apiDownload, apiRequest } from './commerce'

type AdminProduct = {
  ma_sp: string
  ten_sp: string | null
  loai: string | null
  thuong_hieu: string | null
  mo_ta: string | null
  gia_ban: number | string | null
  gia_niem_yet: number | string | null
  so_luong: number
  trang_thai_hien_thi: 'draft' | 'published'
  la_hang_cu?: boolean | 0 | 1
  specifications: Record<string, string>
  images: string[]
  deleted_at?: string | null
}
type AdminOrder = {
  ma_dh: string
  ngay_dat: string
  tong_tien: number | string
  trang_thai: string
  customer_email: string | null
  customer_username: string | null
  payment_method: string | null
  payment_status: string | null
  don_vi_van_chuyen?: string | null
  ma_van_don?: string | null
  ngay_giao_du_kien?: string | null
}
type AdminOrderDetail = {
  order: {
    ma_dh: string
    ngay_dat: string
    tong_tien: number | string
    trang_thai: string
    ten_nguoi_nhan: string | null
    dien_thoai_nguoi_nhan: string | null
    dia_chi_giao_hang: string | null
    don_vi_van_chuyen: string | null
    ma_van_don: string | null
    ngay_giao_du_kien: string | null
  }
  customer: { ten_dang_nhap: string; email: string; ten_khach_hang: string | null } | null
  payment: { phuong_thuc: string; trang_thai: string } | null
  items: { ma_sp: string; ten_sp: string | null; so_luong: number; don_gia: number | string }[]
  history: { trang_thai: string; ghi_chu: string | null; thoi_gian_tao: string }[]
}
type AdminCustomer = {
  id: string
  username: string
  name: string | null
  email: string
  order_count: number
  cart_count: number
}
type AdminConversation = {
  id: string
  status: 'bot' | 'waiting_admin' | 'closed'
  created_at: string
  updated_at: string
  latest_message: string | null
  latest_sender: string | null
  assigned_admin_id: string | null
  waiting_minutes: number
}
type SupportStaff = { id: string; name: string; role: string }
type AdminChatDetail = AdminConversation & {
  messages: { id: number; sender: 'visitor' | 'bot' | 'admin'; message: string; created_at: string }[]
}
type TrainingIntent = 'greeting' | 'payment' | 'checkout' | 'order' | 'delivery' | 'warranty'
type AdminTrainingExample = {
  id: number
  y_dinh: TrainingIntent
  cau_hoi: string
  phan_hoi: string | null
  trang_thai: boolean | 0 | 1
}
type AdminTab = 'reports' | 'products' | 'trash' | 'inventory' | 'vouchers' | 'warranties' | 'orders' | 'returns' | 'education-discounts' | 'customers' | 'conversations' | 'training'
type AdminReturnRequest = { id: number; ma_dh: string; customer_email: string | null; loai_yeu_cau: 'return' | 'exchange'; ly_do: string; mo_ta: string | null; trang_thai: string; ghi_chu_nhan_vien: string | null; created_at: string }
type AdminEducationRequest = { customer_id: string; email: string; audience: string; discount_percent: number | null; status: 'pending' | 'approved' | 'rejected'; verified_at: string; created_at: string; review_note: string | null; has_evidence: boolean }
type AdminReports = { orders_total: number; orders_pending: number; orders_shipping: number; sales_total: number; sales_month: number; low_stock: { ma_sp: string; ten_sp: string; so_luong: number }[]; top_products: { ma_sp: string; ten_sp: string; quantity_sold: number; revenue: number }[]; voucher_effectiveness: { code: string; order_count: number; total_discount: number }[] }
const returnStatusLabels: Record<string, string> = { 'Cho xu ly': 'Chờ xử lý', Approved: 'Đã chấp thuận', Rejected: 'Đã từ chối', Completed: 'Đã hoàn tất' }
type AdminVoucher = { code: string; discount_type: 'percent' | 'fixed'; discount_value: number; minimum_order_value: number; maximum_discount: number | null; usage_count: number; usage_limit: number | null; starts_at: string | null; expires_at: string | null; is_active: boolean }
type StockReceipt = { id: string | number; reference: string | null; received_at: string; supplier: string }
type Warranty = { ma_dh: string; starts_at: string; expires_at: string; status: string; serial_number: string; ten_sp: string; email: string }
type ProductForm = {
  name: string
  category: string
  brand: string
  description: string
  price: string
  list_price: string
  stock: string
  status: 'draft' | 'published'
  is_used: boolean
  image_url: string
  specifications: string
}
type PageInfo = { current_page: number; last_page: number; total: number }
type AdminFilters = { q?: string; status?: string; assigned_to?: string; unassigned?: boolean }

const emptyProduct: ProductForm = {
  name: '',
  category: '',
  brand: '',
  description: '',
  price: '',
  list_price: '',
  stock: '0',
  status: 'draft',
  is_used: false,
  image_url: '',
  specifications: '{}',
}
const adminTokenKey = 'techstore-admin-token'
const formatMoney = (value: number | string) => `${new Intl.NumberFormat('vi-VN').format(Number(value))} ₫`
const trainingIntents: { value: TrainingIntent; label: string }[] = [
  { value: 'greeting', label: 'Chào hỏi' },
  { value: 'payment', label: 'Thanh toán' },
  { value: 'checkout', label: 'Đặt hàng' },
  { value: 'order', label: 'Đơn hàng' },
  { value: 'delivery', label: 'Giao hàng' },
  { value: 'warranty', label: 'Bảo hành / đổi trả' },
]

async function loadAdminTab<T>(tab: AdminTab, token: string, page: number, filters: AdminFilters): Promise<{ data: T[]; meta: PageInfo }> {
  const endpoints: Record<AdminTab, string> = {
    reports: '/admin/reports',
    products: '/admin/products',
    trash: '/admin/products/trash',
    inventory: '/admin/stock-receipts',
    vouchers: '/admin/vouchers',
    warranties: '/admin/warranties',
    orders: '/admin/orders',
    returns: '/admin/returns',
    'education-discounts': '/admin/education-discounts',
    customers: '/admin/customers',
    conversations: '/admin/conversations',
    training: '/admin/chatbot-training',
  }
  const endpoint = endpoints[tab]
  const query = new URLSearchParams({ page: String(page), per_page: '20' })
  if (filters.q?.trim()) query.set('q', filters.q.trim())
  if (filters.status) query.set('status', filters.status)
  if (filters.assigned_to) query.set('assigned_to', filters.assigned_to)
  if (filters.unassigned) query.set('unassigned', '1')
  return apiRequest<{ data: T[]; meta: PageInfo }>(`${endpoint}?${query}`, token)
}

function AdminPagination({ meta, onChange }: { meta: PageInfo; onChange: (page: number) => void }) {
  return (
    <div className="admin-pagination">
      <span>{meta.total} mục · Trang {meta.current_page}/{meta.last_page}</span>
      <div>
        <button type="button" disabled={meta.current_page <= 1} onClick={() => onChange(meta.current_page - 1)}>Trước</button>
        <button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onChange(meta.current_page + 1)}>Sau</button>
      </div>
    </div>
  )
}

export default function AdminApp() {
  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(adminTokenKey))
  const [tab, setTab] = useState<AdminTab>('products')
  const [products, setProducts] = useState<AdminProduct[]>([])
  const [trashedProducts, setTrashedProducts] = useState<AdminProduct[]>([])
  const [stockReceipts, setStockReceipts] = useState<StockReceipt[]>([])
  const [vouchers, setVouchers] = useState<AdminVoucher[]>([])
  const [warranties, setWarranties] = useState<Warranty[]>([])
  const [orders, setOrders] = useState<AdminOrder[]>([])
  const [returnRequests, setReturnRequests] = useState<AdminReturnRequest[]>([])
  const [educationRequests, setEducationRequests] = useState<AdminEducationRequest[]>([])
  const [reportsData, setReportsData] = useState<AdminReports | null>(null)
  const [customers, setCustomers] = useState<AdminCustomer[]>([])
  const [conversations, setConversations] = useState<AdminConversation[]>([])
  const [supportStaff, setSupportStaff] = useState<SupportStaff[]>([])
  const [conversationAssigneeFilter, setConversationAssigneeFilter] = useState('')
  const [trainingExamples, setTrainingExamples] = useState<AdminTrainingExample[]>([])
  const [trainingPageMeta, setTrainingPageMeta] = useState<PageInfo>({ current_page: 1, last_page: 1, total: 0 })
  const [trainingForm, setTrainingForm] = useState<{ y_dinh: TrainingIntent; cau_hoi: string; phan_hoi: string; trang_thai: boolean }>({
    y_dinh: 'payment',
    cau_hoi: '',
    phan_hoi: '',
    trang_thai: true,
  })
  const [editingTrainingId, setEditingTrainingId] = useState<number | null>(null)
  const [selectedConversation, setSelectedConversation] = useState<AdminChatDetail | null>(null)
  const [chatReply, setChatReply] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [loginBusy, setLoginBusy] = useState(false)
  const [formOpen, setFormOpen] = useState(false)
  const [editingProduct, setEditingProduct] = useState<AdminProduct | null>(null)
  const [productForm, setProductForm] = useState<ProductForm>(emptyProduct)
  const [formError, setFormError] = useState('')
  const [filterText, setFilterText] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [productPageMeta, setProductPageMeta] = useState<PageInfo>({ current_page: 1, last_page: 1, total: 0 })
  const [orderPageMeta, setOrderPageMeta] = useState<PageInfo>({ current_page: 1, last_page: 1, total: 0 })
  const [customerPageMeta, setCustomerPageMeta] = useState<PageInfo>({ current_page: 1, last_page: 1, total: 0 })
  const [conversationPageMeta, setConversationPageMeta] = useState<PageInfo>({ current_page: 1, last_page: 1, total: 0 })
  const [orderPage, setOrderPage] = useState(1)
  const [orderQuery, setOrderQuery] = useState('')
  const [orderStatusFilter, setOrderStatusFilter] = useState('')
  const [customerQuery, setCustomerQuery] = useState('')
  const [orderStatus, setOrderStatus] = useState<Record<string, string>>({})
  const [selectedOrder, setSelectedOrder] = useState<AdminOrderDetail | null>(null)
  const [supplierName, setSupplierName] = useState('')
  const [receiptReference, setReceiptReference] = useState('')
  const [receiptProductId, setReceiptProductId] = useState('')
  const [receiptUnitCost, setReceiptUnitCost] = useState('')
  const [receiptSerials, setReceiptSerials] = useState('')
  const [voucherForm, setVoucherForm] = useState({ code: '', discount_type: 'fixed' as 'fixed' | 'percent', discount_value: '', minimum_order_value: '', maximum_discount: '', usage_limit: '', starts_at: '', expires_at: '' })
  const [trackingForm, setTrackingForm] = useState({ carrier: '', tracking_code: '', estimated_delivery: '' })
  const [returnNotes, setReturnNotes] = useState<Record<number, string>>({})
  const [returnStatuses, setReturnStatuses] = useState<Record<number, string>>({})
  const [educationReviewNotes, setEducationReviewNotes] = useState<Record<string, string>>({})

  async function refresh(target = tab, accessToken = token, page = 1, filtersOverride?: AdminFilters) {
    if (!accessToken) return
    setLoading(true)
    setError('')
    try {
      const filters: AdminFilters = filtersOverride ?? (target === 'products'
        ? { q: filterText, status: filterStatus }
        : target === 'orders'
          ? { q: orderQuery, status: orderStatusFilter }
          : target === 'conversations'
            ? conversationAssigneeFilter === '__unassigned'
              ? { unassigned: true }
              : conversationAssigneeFilter ? { assigned_to: conversationAssigneeFilter } : {}
          : target === 'customers'
            ? { q: customerQuery }
            : {})
      if (target === 'products') {
        const result = await loadAdminTab<AdminProduct>(target, accessToken, page, filters)
        setProducts(result.data)
        setProductPageMeta(result.meta)
      }
      if (target === 'orders') {
        const result = await loadAdminTab<AdminOrder>(target, accessToken, page, filters)
        setOrders(result.data)
        setOrderPageMeta(result.meta)
        setOrderPage(result.meta.current_page)
      }
      if (target === 'returns') {
        const result = await apiRequest<{ data: AdminReturnRequest[] }>('/admin/returns', accessToken)
        setReturnRequests(result.data)
      }
      if (target === 'education-discounts') {
        const result = await apiRequest<{ data: AdminEducationRequest[] }>('/admin/education-discounts', accessToken)
        setEducationRequests(result.data)
      }
      if (target === 'reports') {
        const result = await apiRequest<{ data: AdminReports }>('/admin/reports', accessToken)
        setReportsData(result.data)
      }
      if (target === 'customers') {
        const result = await loadAdminTab<AdminCustomer>(target, accessToken, page, filters)
        setCustomers(result.data)
        setCustomerPageMeta(result.meta)
      }
      if (target === 'conversations') {
        const [result, staffResult] = await Promise.all([
          loadAdminTab<AdminConversation>(target, accessToken, page, filters),
          apiRequest<{ data: SupportStaff[] }>('/admin/support-staff', accessToken),
        ])
        setConversations(result.data)
        setConversationPageMeta(result.meta)
        setSupportStaff(staffResult.data)
      }
      if (target === 'training') {
        const result = await loadAdminTab<AdminTrainingExample>(target, accessToken, page, filters)
        setTrainingExamples(result.data)
        setTrainingPageMeta(result.meta)
      }
      if (target === 'trash') {
        const result = await loadAdminTab<AdminProduct>(target, accessToken, page, filters)
        setTrashedProducts(result.data)
        setProductPageMeta(result.meta)
      }
      if (target === 'inventory') {
        const result = await loadAdminTab<StockReceipt>(target, accessToken, page, filters)
        setStockReceipts(result.data)
        setProductPageMeta(result.meta)
      }
      if (target === 'vouchers') {
        const result = await apiRequest<{ data: AdminVoucher[] }>('/admin/vouchers', accessToken)
        setVouchers(result.data)
      }
      if (target === 'warranties') {
        const result = await apiRequest<{ data: Warranty[] }>('/admin/warranties', accessToken)
        setWarranties(result.data)
      }
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'Không tải được dữ liệu quản trị.'
      setError(message)
      if (message === 'Unauthenticated.') {
        sessionStorage.removeItem(adminTokenKey)
        setToken(null)
      }
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    if (!token) return
    let active = true
    void loadAdminTab<AdminProduct>('products', token, 1, {})
      .then((data) => {
        if (active) {
          setProducts(data.data)
          setProductPageMeta(data.meta)
        }
      })
      .catch((reason: unknown) => {
        if (!active) return
        const message = reason instanceof Error ? reason.message : 'Không tải được dữ liệu quản trị.'
        setError(message)
        if (message === 'Unauthenticated.') {
          sessionStorage.removeItem(adminTokenKey)
          setToken(null)
        }
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => {
      active = false
    }
  }, [token])

  const selectedConversationId = selectedConversation?.id
  const selectedConversationStatus = selectedConversation?.status
  useEffect(() => {
    if (!token || !selectedConversationId || tab !== 'conversations' || selectedConversationStatus === 'closed') return
    let active = true
    async function pollChat() {
      try {
        const result = await apiRequest<{ data: AdminChatDetail }>(`/admin/conversations/${selectedConversationId}`, token)
        if (active) setSelectedConversation(result.data)
      } catch (reason) {
        if (active) setError(reason instanceof Error ? reason.message : 'Không tải được hội thoại.')
      }
    }
    const interval = window.setInterval(() => void pollChat(), 4000)
    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [token, tab, selectedConversationId, selectedConversationStatus])

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setLoginBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      const result = await apiRequest<{ token: string }>('/auth/admin/login', null, {
        method: 'POST',
        body: JSON.stringify({
          identifier: String(form.get('identifier') ?? '').trim(),
          password: String(form.get('password') ?? ''),
        }),
      })
      sessionStorage.setItem(adminTokenKey, result.token)
      setToken(result.token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Đăng nhập quản trị thất bại.')
    } finally {
      setLoginBusy(false)
    }
  }

  async function logout() {
    if (token) {
      try {
        await apiRequest('/auth/admin/logout', token, { method: 'POST' })
      } catch (reason) {
        setError(reason instanceof Error ? reason.message : 'Không thể đăng xuất.')
        return
      }
    }
    sessionStorage.removeItem(adminTokenKey)
    setToken(null)
    setProducts([])
    setOrders([])
    setCustomers([])
    setConversations([])
    setTrainingExamples([])
    setSelectedConversation(null)
  }

  function selectTab(next: AdminTab) {
    setTab(next)
    void refresh(next, token, 1)
  }

  function startCreate() {
    setEditingProduct(null)
    setProductForm(emptyProduct)
    setFormError('')
    setFormOpen(true)
  }

  function startEdit(product: AdminProduct) {
    setEditingProduct(product)
    setProductForm({
      name: product.ten_sp ?? '',
      category: product.loai ?? '',
      brand: product.thuong_hieu ?? '',
      description: product.mo_ta ?? '',
      price: String(product.gia_ban ?? ''),
      list_price: product.gia_niem_yet === null ? '' : String(product.gia_niem_yet),
      stock: String(product.so_luong),
      status: product.trang_thai_hien_thi,
      is_used: Boolean(product.la_hang_cu) || / - cũ(?:\s|$)/iu.test(product.ten_sp ?? ''),
      image_url: product.images[0] ?? '',
      specifications: JSON.stringify(product.specifications ?? {}, null, 2),
    })
    setFormError('')
    setFormOpen(true)
  }

  async function saveProduct(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return
    setFormError('')
    let specifications: Record<string, string>
    try {
      specifications = JSON.parse(productForm.specifications) as Record<string, string>
      if (typeof specifications !== 'object' || specifications === null || Array.isArray(specifications)) throw new Error()
    } catch {
      setFormError('Thông số kỹ thuật phải là một đối tượng JSON hợp lệ.')
      return
    }

    const payload = {
      name: productForm.name.trim(),
      category: productForm.category.trim(),
      brand: productForm.brand.trim() || null,
      description: productForm.description.trim() || null,
      price: Number(productForm.price),
      list_price: productForm.list_price ? Number(productForm.list_price) : null,
      stock: Number(productForm.stock),
      status: productForm.status,
      is_used: productForm.is_used,
      image_url: productForm.image_url.trim() || null,
      specifications,
    }
    try {
      await apiRequest(editingProduct ? `/admin/products/${editingProduct.ma_sp}` : '/admin/products', token, {
        method: editingProduct ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      setFormOpen(false)
      await refresh('products', token, productPageMeta.current_page)
    } catch (reason) {
      setFormError(reason instanceof Error ? reason.message : 'Không lưu được sản phẩm.')
    }
  }

  async function deleteProduct(product: AdminProduct) {
    if (!token || !window.confirm(`Xóa sản phẩm "${product.ten_sp}"?`)) return
    try {
      await apiRequest(`/admin/products/${product.ma_sp}`, token, { method: 'DELETE' })
      await refresh('products', token, productPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể xóa sản phẩm.')
    }
  }

  async function restoreProduct(product: AdminProduct) {
    if (!token) return
    try {
      await apiRequest(`/admin/products/${product.ma_sp}/restore`, token, { method: 'POST' })
      await refresh('trash', token, productPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể khôi phục sản phẩm.')
    }
  }

  async function forceDeleteProduct(product: AdminProduct) {
    if (!token || !window.confirm(`Xóa vĩnh viễn "${product.ten_sp}"? Thao tác này không thể hoàn tác.`)) return
    try {
      await apiRequest(`/admin/products/${product.ma_sp}/force`, token, { method: 'DELETE' })
      await refresh('trash', token, productPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể xóa vĩnh viễn sản phẩm.')
    }
  }

  async function submitStockReceipt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return
    const serials = receiptSerials.split(/\r?\n/).map((serial) => serial.trim()).filter(Boolean)
    if (serials.length === 0 || new Set(serials).size !== serials.length) {
      setError('Nhập ít nhất một serial duy nhất, mỗi serial trên một dòng.')
      return
    }
    try {
      await apiRequest('/admin/stock-receipts', token, {
        method: 'POST',
        body: JSON.stringify({
          supplier: supplierName.trim(),
          reference: receiptReference.trim() || null,
          items: [{ product_id: receiptProductId.trim(), unit_cost: Number(receiptUnitCost), serials }],
        }),
      })
      setSupplierName('')
      setReceiptReference('')
      setReceiptProductId('')
      setReceiptUnitCost('')
      setReceiptSerials('')
      await refresh('inventory', token, 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể tạo phiếu nhập kho.')
    }
  }

  async function submitVoucher(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return
    try {
      await apiRequest('/admin/vouchers', token, {
        method: 'POST',
        body: JSON.stringify({
          code: voucherForm.code.trim(),
          discount_type: voucherForm.discount_type,
          discount_value: Number(voucherForm.discount_value),
          minimum_order_value: Number(voucherForm.minimum_order_value || 0),
          maximum_discount: voucherForm.maximum_discount ? Number(voucherForm.maximum_discount) : null,
          usage_limit: voucherForm.usage_limit ? Number(voucherForm.usage_limit) : null,
          starts_at: voucherForm.starts_at || null,
          expires_at: voucherForm.expires_at || null,
        }),
      })
      setVoucherForm({ code: '', discount_type: 'fixed', discount_value: '', minimum_order_value: '', maximum_discount: '', usage_limit: '', starts_at: '', expires_at: '' })
      await refresh('vouchers', token, 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể tạo voucher.')
    }
  }

  async function toggleVoucher(voucher: AdminVoucher) {
    if (!token) return
    try {
      await apiRequest(`/admin/vouchers/${encodeURIComponent(voucher.code)}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ is_active: !voucher.is_active }),
      })
      await refresh('vouchers', token, 1)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể cập nhật voucher.')
    }
  }

  async function orderAction(order: AdminOrder, action: 'mark_paid' | 'status') {
    if (!token) return
    try {
      await apiRequest(`/admin/orders/${order.ma_dh}`, token, {
        method: 'PATCH',
        body: JSON.stringify({
          action,
          ...(action === 'status' ? { status: orderStatus[order.ma_dh] ?? order.trang_thai } : {}),
        }),
      })
      await refresh('orders', token, orderPage)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể cập nhật đơn hàng.')
    }
  }

  async function updateTracking() {
    if (!token || !selectedOrder) return
    try {
      await apiRequest(`/admin/orders/${selectedOrder.order.ma_dh}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'tracking', ...trackingForm }),
      })
      const result = await apiRequest<{ data: AdminOrderDetail }>(`/admin/orders/${selectedOrder.order.ma_dh}`, token)
      setSelectedOrder(result.data)
      await refresh('orders', token, orderPage)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không lưu được thông tin vận chuyển.')
    }
  }

  async function updateReturnRequest(item: AdminReturnRequest) {
    if (!token) return
    try {
      await apiRequest(`/admin/returns/${item.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({
          status: returnStatuses[item.id] ?? item.trang_thai,
          note: returnNotes[item.id] ?? item.ghi_chu_nhan_vien ?? '',
        }),
      })
      await refresh('returns', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không cập nhật được yêu cầu đổi/trả.')
    }
  }

  async function reviewEducationRequest(item: AdminEducationRequest, status: 'approved' | 'rejected') {
    if (!token) return
    try {
      await apiRequest(`/admin/education-discounts/${encodeURIComponent(item.customer_id)}`, token, {
        method: 'PATCH',
        body: JSON.stringify({
          status,
          note: educationReviewNotes[item.customer_id] ?? '',
        }),
      })
      await refresh('education-discounts', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không cập nhật được hồ sơ ưu đãi.')
    }
  }

  async function downloadEducationEvidence(item: AdminEducationRequest) {
    if (!token) return
    try {
      const { blob, filename } = await apiDownload(
        `/admin/education-discounts/${encodeURIComponent(item.customer_id)}/evidence`,
        token,
      )
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = filename
      document.body.append(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không tải được minh chứng.')
    }
  }

  async function openOrder(order: AdminOrder) {
    if (!token) return
    setError('')
    try {
      const result = await apiRequest<{ data: AdminOrderDetail }>(`/admin/orders/${order.ma_dh}`, token)
      setSelectedOrder(result.data)
      setTrackingForm({
        carrier: result.data.order.don_vi_van_chuyen ?? '',
        tracking_code: result.data.order.ma_van_don ?? '',
        estimated_delivery: result.data.order.ngay_giao_du_kien ?? '',
      })
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không tải được chi tiết đơn hàng.')
    }
  }

  async function openConversation(conversation: AdminConversation) {
    if (!token) return
    setError('')
    try {
      const result = await apiRequest<{ data: AdminChatDetail }>(`/admin/conversations/${conversation.id}`, token)
      setSelectedConversation(result.data)
      setChatReply('')
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không tải được hội thoại.')
    }
  }

  async function replyToConversation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !selectedConversation || !chatReply.trim()) return
    try {
      await apiRequest(`/admin/conversations/${selectedConversation.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'reply', message: chatReply.trim() }),
      })
      setChatReply('')
      await openConversation(selectedConversation)
      await refresh('conversations', token, conversationPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không gửi được phản hồi.')
    }
  }

  async function resolveConversation() {
    if (!token || !selectedConversation || !window.confirm('Kết thúc cuộc trò chuyện này?')) return
    try {
      await apiRequest(`/admin/conversations/${selectedConversation.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'resolve' }),
      })
      await openConversation(selectedConversation)
      await refresh('conversations', token, conversationPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể kết thúc hội thoại.')
    }
  }

  async function assignConversation(assignedTo: string) {
    if (!token || !selectedConversation) return
    try {
      await apiRequest(`/admin/conversations/${selectedConversation.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'assign', assigned_to: assignedTo || null }),
      })
      await refresh('conversations', token, conversationPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể phân công hội thoại.')
    }
  }

  function editTrainingExample(example: AdminTrainingExample) {
    setEditingTrainingId(example.id)
    setTrainingForm({
      y_dinh: example.y_dinh,
      cau_hoi: example.cau_hoi,
      phan_hoi: example.phan_hoi ?? '',
      trang_thai: Boolean(example.trang_thai),
    })
  }

  async function saveTrainingExample(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return
    setError('')
    try {
      await apiRequest(
        editingTrainingId ? `/admin/chatbot-training/${editingTrainingId}` : '/admin/chatbot-training',
        token,
        {
          method: editingTrainingId ? 'PATCH' : 'POST',
          body: JSON.stringify({
            ...trainingForm,
            cau_hoi: trainingForm.cau_hoi.trim(),
            phan_hoi: trainingForm.phan_hoi.trim(),
          }),
        },
      )
      setEditingTrainingId(null)
      setTrainingForm({ y_dinh: 'payment', cau_hoi: '', phan_hoi: '', trang_thai: true })
      await refresh('training', token, trainingPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không lưu được dữ liệu huấn luyện.')
    }
  }

  async function toggleTrainingExample(example: AdminTrainingExample) {
    if (!token) return
    try {
      await apiRequest(`/admin/chatbot-training/${example.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ trang_thai: !example.trang_thai }),
      })
      await refresh('training', token, trainingPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không cập nhật được trạng thái ví dụ.')
    }
  }

  async function deleteTrainingExample(example: AdminTrainingExample) {
    if (!token || !window.confirm('Xóa ví dụ hỏi–đáp này khỏi dữ liệu huấn luyện?')) return
    try {
      await apiRequest(`/admin/chatbot-training/${example.id}`, token, { method: 'DELETE' })
      if (editingTrainingId === example.id) {
        setEditingTrainingId(null)
        setTrainingForm({ y_dinh: 'payment', cau_hoi: '', phan_hoi: '', trang_thai: true })
      }
      await refresh('training', token, trainingPageMeta.current_page)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không xóa được ví dụ huấn luyện.')
    }
  }

  if (!token) {
    return (
      <main className="admin-login-page">
        <AppearanceSettings />
        <form className="admin-login-card" onSubmit={login}>
          <a className="brand-mark" href="/"><img src="/techstore-mark.svg" alt="" /><span>TECH<span>STORE</span></span></a>
          <span className="eyebrow">KHU VỰC QUẢN TRỊ</span>
          <h1>Đăng nhập Admin</h1>
          <label>Tài khoản hoặc email<input name="identifier" required autoComplete="username" /></label>
          <label>Mật khẩu<input name="password" type="password" required autoComplete="current-password" /></label>
          {error && <p className="commerce-error" role="alert">{error}</p>}
          <button className="primary-button" type="submit" disabled={loginBusy}>{loginBusy ? 'Đang đăng nhập…' : 'Đăng nhập'}</button>
          <a className="admin-home-link" href="/">← Về trang cửa hàng</a>
        </form>
      </main>
    )
  }

  return (
    <main className="admin-app">
      <AppearanceSettings />
      <header className="admin-header">
        <a className="brand-mark" href="/"><img src="/techstore-mark.svg" alt="" /><span>TECH<span>STORE</span></span></a>
        <div><span className="eyebrow">BẢNG ĐIỀU KHIỂN</span><button type="button" onClick={() => void logout()}>Đăng xuất</button></div>
      </header>
      <div className="admin-layout">
        <nav className="admin-sidebar" aria-label="Chức năng quản trị">
          <h1>Quản trị cửa hàng</h1>
          {([
            ['reports', 'Tổng quan'],
            ['products', 'Sản phẩm'],
            ['trash', 'Thùng rác'],
            ['inventory', 'Nhập kho / serial'],
            ['vouchers', 'Mã giảm giá'],
            ['warranties', 'Bảo hành'],
            ['orders', 'Đơn hàng'],
            ['returns', 'Đổi trả'],
            ['education-discounts', 'Duyệt ưu đãi HSSV–GV'],
            ['customers', 'Khách hàng'],
            ['conversations', 'Chat hỗ trợ'],
            ['training', 'Huấn luyện chatbot'],
          ] as const).map(([key, label]) => (
            <button className={tab === key ? 'admin-tab active' : 'admin-tab'} type="button" key={key} onClick={() => selectTab(key)}>{label}</button>
          ))}
          <a href="/">← Xem cửa hàng</a>
        </nav>
        <section className="admin-content">
          <div className="admin-page-heading">
            <div><span className="eyebrow">TECHSTORE ADMIN</span><h2>{({
              reports: 'Tổng quan vận hành',
              products: 'Quản lý sản phẩm',
              trash: 'Thùng rác sản phẩm',
              inventory: 'Nhập kho và serial',
              vouchers: 'Mã giảm giá',
              warranties: 'Bảo hành',
              orders: 'Quản lý đơn hàng',
              returns: 'Yêu cầu đổi trả',
              'education-discounts': 'Duyệt ưu đãi HSSV–GV',
              customers: 'Khách hàng',
              conversations: 'Chat hỗ trợ',
              training: 'Huấn luyện chatbot',
            })[tab]}</h2></div>
            {tab === 'products' && <button className="primary-button" type="button" onClick={startCreate}>+ Thêm sản phẩm</button>}
          </div>
          {error && <p className="commerce-error admin-alert" role="alert">{error}</p>}
          {tab === 'reports' && reportsData && (
            <div className="admin-reports">
              <div className="admin-report-cards">
                <article><span>Doanh thu đã giao</span><strong>{formatMoney(reportsData.sales_total)}</strong></article>
                <article><span>Doanh thu tháng này</span><strong>{formatMoney(reportsData.sales_month)}</strong></article>
                <article><span>Tổng đơn hàng</span><strong>{reportsData.orders_total}</strong></article>
                <article><span>Chờ xử lý / đang giao</span><strong>{reportsData.orders_pending} / {reportsData.orders_shipping}</strong></article>
              </div>
              <section><h3>Sản phẩm bán chạy</h3><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Đã bán</th><th>Doanh thu</th></tr></thead><tbody>{reportsData.top_products.map((item) => <tr key={item.ma_sp}><td>{item.ten_sp}<small>{item.ma_sp}</small></td><td>{item.quantity_sold}</td><td>{formatMoney(item.revenue)}</td></tr>)}{reportsData.top_products.length === 0 && <tr><td colSpan={3}>Chưa có đơn giao thành công.</td></tr>}</tbody></table></div></section>
              <section><h3>Cảnh báo tồn kho thấp (≤ 5)</h3><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Tồn kho</th></tr></thead><tbody>{reportsData.low_stock.map((item) => <tr key={item.ma_sp}><td>{item.ten_sp}<small>{item.ma_sp}</small></td><td>{item.so_luong}</td></tr>)}{reportsData.low_stock.length === 0 && <tr><td colSpan={2}>Không có sản phẩm tồn thấp.</td></tr>}</tbody></table></div></section>
              <section><h3>Hiệu quả mã khuyến mãi</h3><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã</th><th>Đơn đã dùng</th><th>Tổng giảm giá</th></tr></thead><tbody>{reportsData.voucher_effectiveness.map((item) => <tr key={item.code}><td>{item.code}</td><td>{item.order_count}</td><td>{formatMoney(item.total_discount)}</td></tr>)}{reportsData.voucher_effectiveness.length === 0 && <tr><td colSpan={3}>Chưa có lượt dùng voucher.</td></tr>}</tbody></table></div></section>
            </div>
          )}
          {tab === 'reports' && loading && !reportsData && <p className="detail-status">Đang tải báo cáo…</p>}
          {tab === 'returns' && (
            <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Đơn hàng / khách</th><th>Yêu cầu</th><th>Lý do</th><th>Ngày gửi</th><th>Trạng thái</th><th>Xử lý</th></tr></thead><tbody>
              {returnRequests.map((item) => <tr key={item.id}>
                <td>{item.ma_dh}<small>{item.customer_email || '—'}</small></td>
                <td>{item.loai_yeu_cau === 'return' ? 'Đổi/trả' : 'Đổi sản phẩm'}<small>{item.mo_ta || ''}</small></td>
                <td>{item.ly_do}</td><td>{new Date(item.created_at).toLocaleString('vi-VN')}</td>
                <td><span className="admin-badge draft">{returnStatusLabels[item.trang_thai] ?? item.trang_thai}</span><select aria-label={`Trạng thái yêu cầu đổi trả ${item.ma_dh}`} value={returnStatuses[item.id] ?? item.trang_thai} disabled={item.trang_thai === 'Rejected' || item.trang_thai === 'Completed'} onChange={(event) => setReturnStatuses({ ...returnStatuses, [item.id]: event.target.value })}>
                  {item.trang_thai === 'Cho xu ly' ? <><option value="Cho xu ly" disabled>Chờ xử lý</option><option value="Approved">Chấp thuận</option><option value="Rejected">Từ chối</option></> : item.trang_thai === 'Approved' ? <><option value="Approved" disabled>Đã chấp thuận</option><option value="Completed">Hoàn tất</option></> : <option value={item.trang_thai}>{returnStatusLabels[item.trang_thai] ?? item.trang_thai}</option>}
                </select></td>
                <td><input aria-label={`Ghi chú xử lý ${item.ma_dh}`} maxLength={2000} placeholder="Ghi chú cho khách" value={returnNotes[item.id] ?? item.ghi_chu_nhan_vien ?? ''} onChange={(event) => setReturnNotes({ ...returnNotes, [item.id]: event.target.value })} /><button type="button" onClick={() => void updateReturnRequest(item)}>Lưu</button></td>
              </tr>)}
              {!loading && returnRequests.length === 0 && <tr><td colSpan={6}>Chưa có yêu cầu đổi/trả.</td></tr>}
            </tbody></table></div>
          )}
          {tab === 'education-discounts' && (
            <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Khách hàng / email</th><th>Đối tượng</th><th>Minh chứng</th><th>Gửi lúc</th><th>Trạng thái</th><th>Xử lý</th></tr></thead><tbody>
              {educationRequests.map((item) => <tr key={item.customer_id}>
                <td>{item.customer_id}<small>{item.email}</small></td>
                <td>{item.audience}<small>Đề nghị giảm {item.discount_percent}%</small></td>
                <td>{item.has_evidence ? <button type="button" onClick={() => void downloadEducationEvidence(item)}>Tải minh chứng</button> : 'Không có tệp'}</td>
                <td>{new Date(item.created_at).toLocaleString('vi-VN')}</td>
                <td><span className={`admin-badge ${item.status === 'approved' ? 'published' : 'draft'}`}>{item.status === 'pending' ? 'Chờ duyệt' : item.status === 'approved' ? 'Đã duyệt' : 'Từ chối'}</span>{item.review_note && <small>{item.review_note}</small>}</td>
                <td>{item.status === 'pending' ? <><input aria-label={`Ghi chú duyệt hồ sơ ${item.customer_id}`} maxLength={2000} placeholder="Ghi chú; bắt buộc nếu từ chối" value={educationReviewNotes[item.customer_id] ?? ''} onChange={(event) => setEducationReviewNotes({ ...educationReviewNotes, [item.customer_id]: event.target.value })} /><button type="button" disabled={!item.has_evidence} onClick={() => void reviewEducationRequest(item, 'approved')}>Duyệt</button><button className="admin-delete" type="button" onClick={() => void reviewEducationRequest(item, 'rejected')}>Từ chối</button></> : '—'}</td>
              </tr>)}
              {!loading && educationRequests.length === 0 && <tr><td colSpan={6}>Chưa có hồ sơ ưu đãi cần xử lý.</td></tr>}
            </tbody></table></div>
          )}
          {tab === 'products' && (
            <>
              <form className="admin-filters" onSubmit={(event) => { event.preventDefault(); void refresh('products', token, 1) }}>
                <input aria-label="Tìm sản phẩm quản trị" placeholder="Tìm tên, mã hoặc thương hiệu" value={filterText} onChange={(event) => setFilterText(event.target.value)} />
                <select aria-label="Lọc trạng thái sản phẩm" value={filterStatus} onChange={(event) => setFilterStatus(event.target.value)}><option value="">Mọi trạng thái</option><option value="draft">Bản nháp</option><option value="published">Đang bán</option></select>
                <button type="submit">Tìm kiếm</button>
              </form>
              <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Danh mục / thương hiệu</th><th>Giá</th><th>Tồn kho</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
                {products.map((product) => <tr key={product.ma_sp}><td><strong>{product.ten_sp}</strong><small>{product.ma_sp}</small></td><td>{product.loai}<small>{product.thuong_hieu}</small></td><td>{formatMoney(product.gia_ban ?? 0)}</td><td>{product.so_luong}</td><td><span className={`admin-badge ${product.trang_thai_hien_thi}`}>{product.trang_thai_hien_thi === 'published' ? 'Đang bán' : 'Bản nháp'}</span></td><td><button type="button" onClick={() => startEdit(product)}>Sửa</button><button className="admin-delete" type="button" onClick={() => void deleteProduct(product)}>Xóa</button></td></tr>)}
                {!loading && products.length === 0 && <tr><td colSpan={6}>Chưa có sản phẩm phù hợp.</td></tr>}
                {loading && <tr><td colSpan={6}>Đang tải…</td></tr>}
              </tbody></table></div>
              <AdminPagination meta={productPageMeta} onChange={(page) => void refresh('products', token, page)} />
            </>
          )}
          {tab === 'trash' && (
            <>
              <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Danh mục</th><th>Ngày xóa</th><th>Thao tác</th></tr></thead><tbody>
                {trashedProducts.map((product) => <tr key={product.ma_sp}><td><strong>{product.ten_sp}</strong><small>{product.ma_sp}</small></td><td>{product.loai}</td><td>{product.deleted_at ?? '—'}</td><td><button type="button" onClick={() => void restoreProduct(product)}>Khôi phục</button><button className="admin-delete" type="button" onClick={() => void forceDeleteProduct(product)}>Xóa vĩnh viễn</button></td></tr>)}
                {!loading && trashedProducts.length === 0 && <tr><td colSpan={4}>Thùng rác đang trống.</td></tr>}
                {loading && <tr><td colSpan={4}>Đang tải…</td></tr>}
              </tbody></table></div>
              <AdminPagination meta={productPageMeta} onChange={(page) => void refresh('trash', token, page)} />
            </>
          )}
          {tab === 'inventory' && (
            <>
              <form className="admin-inventory-form" onSubmit={(event) => void submitStockReceipt(event)}>
                <label>Nhà cung cấp<input required maxLength={200} value={supplierName} onChange={(event) => setSupplierName(event.target.value)} /></label>
                <label>Mã phiếu / tham chiếu<input maxLength={100} value={receiptReference} onChange={(event) => setReceiptReference(event.target.value)} /></label>
                <label>Mã sản phẩm<input required maxLength={10} value={receiptProductId} onChange={(event) => setReceiptProductId(event.target.value.toUpperCase())} /></label>
                <label>Giá nhập mỗi sản phẩm<input required min="0" step="1" type="number" value={receiptUnitCost} onChange={(event) => setReceiptUnitCost(event.target.value)} /></label>
                <label className="inventory-serials">Serial (mỗi dòng một mã)<textarea required rows={4} value={receiptSerials} onChange={(event) => setReceiptSerials(event.target.value)} /></label>
                <button className="primary-button" type="submit">Tạo phiếu nhập</button>
              </form>
              <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã phiếu</th><th>Nhà cung cấp</th><th>Tham chiếu</th><th>Thời gian nhập</th></tr></thead><tbody>
                {stockReceipts.map((receipt) => <tr key={receipt.id}><td>{receipt.id}</td><td>{receipt.supplier}</td><td>{receipt.reference || '—'}</td><td>{receipt.received_at}</td></tr>)}
                {!loading && stockReceipts.length === 0 && <tr><td colSpan={4}>Chưa có phiếu nhập kho.</td></tr>}
              </tbody></table></div>
              <AdminPagination meta={productPageMeta} onChange={(page) => void refresh('inventory', token, page)} />
            </>
          )}
          {tab === 'vouchers' && (
            <>
              <form className="admin-inventory-form" onSubmit={(event) => void submitVoucher(event)}>
                <label>Mã voucher<input required minLength={3} maxLength={40} value={voucherForm.code} onChange={(event) => setVoucherForm({ ...voucherForm, code: event.target.value.toUpperCase() })} /></label>
                <label>Loại giảm giá<select value={voucherForm.discount_type} onChange={(event) => setVoucherForm({ ...voucherForm, discount_type: event.target.value as 'fixed' | 'percent' })}><option value="fixed">Số tiền (₫)</option><option value="percent">Phần trăm (%)</option></select></label>
                <label>Giá trị<input required type="number" min="1" value={voucherForm.discount_value} onChange={(event) => setVoucherForm({ ...voucherForm, discount_value: event.target.value })} /></label>
                <label>Đơn tối thiểu<input type="number" min="0" value={voucherForm.minimum_order_value} onChange={(event) => setVoucherForm({ ...voucherForm, minimum_order_value: event.target.value })} /></label>
                <label>Giảm tối đa<input type="number" min="1" value={voucherForm.maximum_discount} onChange={(event) => setVoucherForm({ ...voucherForm, maximum_discount: event.target.value })} /></label>
                <label>Giới hạn lượt dùng<input type="number" min="1" value={voucherForm.usage_limit} onChange={(event) => setVoucherForm({ ...voucherForm, usage_limit: event.target.value })} /></label>
                <label>Bắt đầu<input type="datetime-local" value={voucherForm.starts_at} onChange={(event) => setVoucherForm({ ...voucherForm, starts_at: event.target.value })} /></label>
                <label>Kết thúc<input type="datetime-local" value={voucherForm.expires_at} onChange={(event) => setVoucherForm({ ...voucherForm, expires_at: event.target.value })} /></label>
                <button className="primary-button" type="submit">Tạo voucher</button>
              </form>
              <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã</th><th>Giảm</th><th>Đơn tối thiểu</th><th>Thời hạn</th><th>Lượt dùng</th></tr></thead><tbody>
                {vouchers.map((voucher) => <tr key={voucher.code}><td>{voucher.code}<small>{voucher.is_active ? 'Đang hoạt động' : 'Đã ngừng'}</small></td><td>{voucher.discount_type === 'percent' ? `${voucher.discount_value}%` : formatMoney(voucher.discount_value)}</td><td>{formatMoney(voucher.minimum_order_value)}</td><td>{voucher.starts_at || 'Ngay lập tức'} – {voucher.expires_at || 'Không hết hạn'}</td><td>{voucher.usage_count}{voucher.usage_limit ? ` / ${voucher.usage_limit}` : ''}<button type="button" onClick={() => void toggleVoucher(voucher)}>{voucher.is_active ? 'Ngừng' : 'Kích hoạt'}</button></td></tr>)}
                {vouchers.length === 0 && <tr><td colSpan={5}>Chưa có voucher.</td></tr>}
              </tbody></table></div>
            </>
          )}
          {tab === 'warranties' && (
            <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Serial</th><th>Đơn hàng</th><th>Khách hàng</th><th>Hiệu lực</th></tr></thead><tbody>
              {warranties.map((warranty) => <tr key={warranty.serial_number}><td>{warranty.ten_sp}</td><td>{warranty.serial_number}</td><td>{warranty.ma_dh}</td><td>{warranty.email}</td><td>{warranty.starts_at} – {warranty.expires_at}</td></tr>)}
              {warranties.length === 0 && <tr><td colSpan={5}>Chưa có bảo hành được kích hoạt.</td></tr>}
            </tbody></table></div>
          )}
          {tab === 'orders' && (
            <>
            <form className="admin-filters" onSubmit={(event) => { event.preventDefault(); void refresh('orders', token, 1) }}>
              <input aria-label="Tìm đơn hàng" placeholder="Tìm mã đơn hoặc email khách" value={orderQuery} onChange={(event) => setOrderQuery(event.target.value)} />
              <select aria-label="Lọc trạng thái đơn hàng" value={orderStatusFilter} onChange={(event) => setOrderStatusFilter(event.target.value)}><option value="">Mọi trạng thái</option><option value="Cho xac nhan">Chờ xác nhận</option><option value="Dang Giao">Đang giao</option><option value="Da Giao">Đã giao</option><option value="huy">Đã hủy</option></select>
              <button type="submit">Tìm kiếm</button>
            </form>
            <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã đơn / ngày</th><th>Khách hàng</th><th>Tổng tiền</th><th>Thanh toán</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
              {orders.map((order) => <tr key={order.ma_dh}><td><button className="admin-order-link" type="button" onClick={() => void openOrder(order)}>{order.ma_dh}</button><small>{order.ngay_dat}</small>{order.ma_van_don && <small>{order.don_vi_van_chuyen} · {order.ma_van_don}</small>}</td><td>{order.customer_username || '—'}<small>{order.customer_email}</small></td><td>{formatMoney(order.tong_tien)}</td><td>{order.payment_method === 'Chuyen khoan' ? 'Agribank QR' : order.payment_method === 'MoMo' ? 'MoMo QR' : 'Tiền mặt'}<small>{order.payment_status || 'Chưa rõ'}</small></td><td><select aria-label={`Trạng thái đơn ${order.ma_dh}`} value={orderStatus[order.ma_dh] ?? order.trang_thai} onChange={(event) => setOrderStatus({ ...orderStatus, [order.ma_dh]: event.target.value })}><option value="Cho xac nhan">Chờ xác nhận</option><option value="Dang Giao">Đang giao</option><option value="Da Giao">Đã giao</option><option value="huy">Đã hủy</option></select></td><td className="admin-order-actions">{order.payment_status !== 'Da Thanh Toan' && (['Chuyen khoan', 'MoMo'].includes(order.payment_method ?? '') || (order.payment_method === 'Tien mat' && order.trang_thai === 'Da Giao')) && <button type="button" onClick={() => void orderAction(order, 'mark_paid')}>Xác nhận đã thanh toán</button>}<button type="button" onClick={() => void orderAction(order, 'status')}>Cập nhật</button></td></tr>)}
              {!loading && orders.length === 0 && <tr><td colSpan={6}>Chưa có đơn hàng.</td></tr>}
              {loading && <tr><td colSpan={6}>Đang tải…</td></tr>}
            </tbody></table></div>
            <AdminPagination meta={orderPageMeta} onChange={(page) => void refresh('orders', token, page)} />
            </>
          )}
          {tab === 'customers' && (
            <>
            <form className="admin-filters" onSubmit={(event) => { event.preventDefault(); void refresh('customers', token, 1) }}>
              <input aria-label="Tìm khách hàng" placeholder="Tìm tên, tài khoản hoặc email" value={customerQuery} onChange={(event) => setCustomerQuery(event.target.value)} />
              <button type="submit">Tìm kiếm</button>
            </form>
            <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã khách hàng</th><th>Khách hàng</th><th>Email</th><th>Đơn hàng</th><th>Giỏ hàng</th></tr></thead><tbody>
              {customers.map((customer) => <tr key={customer.id}><td>{customer.id}</td><td>{customer.name || customer.username}<small>{customer.username}</small></td><td>{customer.email}</td><td>{customer.order_count}</td><td>{customer.cart_count}</td></tr>)}
              {!loading && customers.length === 0 && <tr><td colSpan={5}>Chưa có khách hàng.</td></tr>}
              {loading && <tr><td colSpan={5}>Đang tải…</td></tr>}
            </tbody></table></div>
            <AdminPagination meta={customerPageMeta} onChange={(page) => void refresh('customers', token, page)} />
            </>
          )}
          {tab === 'conversations' && (
            <div className="admin-chat-layout">
              <aside className="admin-chat-list">
                <div className="admin-chat-list-heading"><strong>Hàng đợi hỗ trợ</strong><button type="button" onClick={() => void refresh('conversations')}>Tải lại</button></div>
                <select aria-label="Lọc nhân viên phụ trách" value={conversationAssigneeFilter} onChange={(event) => {
                  const value = event.target.value
                  setConversationAssigneeFilter(value)
                  void refresh('conversations', token, 1, value === '__unassigned' ? { unassigned: true } : value ? { assigned_to: value } : {})
                }}>
                  <option value="">Tất cả phân công</option><option value="__unassigned">Chưa phân công</option>
                  {supportStaff.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}
                </select>
                {conversations.map((conversation) => (
                  <button
                    className={selectedConversation?.id === conversation.id ? 'admin-chat-list-item selected' : 'admin-chat-list-item'}
                    key={conversation.id}
                    type="button"
                    onClick={() => void openConversation(conversation)}
                  >
                    <span className={`admin-chat-status ${conversation.status}`}>{conversation.status === 'waiting_admin' ? 'Cần hỗ trợ' : conversation.status === 'closed' ? 'Đã kết thúc' : 'Bot'}</span>
                    <strong>{conversation.latest_message || 'Chưa có tin nhắn'}</strong>
                    <small>{conversation.id.slice(0, 8)} · Chờ {conversation.waiting_minutes} phút · {conversation.assigned_admin_id ? `NV ${conversation.assigned_admin_id}` : 'Chưa phân công'}</small>
                  </button>
                ))}
                {!loading && conversations.length === 0 && <p className="admin-chat-empty">Không có hội thoại đang chờ.</p>}
                <AdminPagination meta={conversationPageMeta} onChange={(page) => void refresh('conversations', token, page)} />
              </aside>
              <section className="admin-chat-detail">
                {selectedConversation ? (
                  <>
                    <header><div><strong>Hội thoại {selectedConversation.id.slice(0, 8)}</strong><small>{selectedConversation.status === 'closed' ? 'Đã kết thúc' : selectedConversation.status === 'waiting_admin' ? 'Đang chờ nhân viên' : 'Bot đang hỗ trợ'}</small></div><div className="admin-chat-assignment">{selectedConversation.status !== 'closed' && <select aria-label="Phân công nhân viên" value={conversations.find((conversation) => conversation.id === selectedConversation.id)?.assigned_admin_id ?? ''} onChange={(event) => void assignConversation(event.target.value)}><option value="">Chưa phân công</option>{supportStaff.map((member) => <option key={member.id} value={member.id}>{member.name}</option>)}</select>}{selectedConversation.status !== 'closed' && <button type="button" onClick={() => void resolveConversation()}>Kết thúc</button>}</div></header>
                    <div className="admin-chat-messages">
                      {selectedConversation.messages.map((message) => <article className={`admin-chat-message ${message.sender}`} key={message.id}><span>{message.sender === 'visitor' ? 'Khách hàng' : message.sender === 'admin' ? 'Nhân viên' : 'Bot'}</span><p>{message.message}</p><time>{message.created_at}</time></article>)}
                    </div>
                    <form className="admin-chat-reply" onSubmit={replyToConversation}>
                      <textarea aria-label="Nội dung trả lời khách hàng" maxLength={2000} required disabled={selectedConversation.status === 'closed'} placeholder="Nhập phản hồi cho khách hàng…" value={chatReply} onChange={(event) => setChatReply(event.target.value)} />
                      <button className="primary-button" type="submit" disabled={selectedConversation.status === 'closed' || !chatReply.trim()}>Gửi phản hồi</button>
                    </form>
                  </>
                ) : <p className="admin-chat-empty">Chọn một hội thoại để xem và phản hồi khách hàng.</p>}
              </section>
            </div>
          )}
          {tab === 'training' && (
            <div className="admin-training">
              <p className="admin-training-note">
                Thêm các cặp câu hỏi–trả lời đã được kiểm duyệt. Bot sẽ đối sánh câu khách với ví dụ đang bật; đây là học theo ví dụ, không tự huấn luyện mô hình AI.
              </p>
              <form className="admin-training-form" onSubmit={saveTrainingExample}>
                <label>Ý định
                  <select value={trainingForm.y_dinh} onChange={(event) => setTrainingForm({ ...trainingForm, y_dinh: event.target.value as TrainingIntent })}>
                    {trainingIntents.map((intent) => <option key={intent.value} value={intent.value}>{intent.label}</option>)}
                  </select>
                </label>
                <label>Câu hỏi mẫu
                  <textarea required minLength={3} maxLength={500} value={trainingForm.cau_hoi} onChange={(event) => setTrainingForm({ ...trainingForm, cau_hoi: event.target.value })} placeholder="Ví dụ: Tôi có thể trả tiền khi nhận hàng không?" />
                </label>
                <label>Câu trả lời
                  <textarea required minLength={2} maxLength={2000} value={trainingForm.phan_hoi} onChange={(event) => setTrainingForm({ ...trainingForm, phan_hoi: event.target.value })} placeholder="Viết câu trả lời chính xác, không đưa thông tin chưa xác minh." />
                </label>
                <label className="admin-training-active"><input type="checkbox" checked={trainingForm.trang_thai} onChange={(event) => setTrainingForm({ ...trainingForm, trang_thai: event.target.checked })} /> Dùng ví dụ này để trả lời</label>
                <div className="admin-training-actions">
                  <button className="primary-button" type="submit">{editingTrainingId ? 'Lưu ví dụ' : 'Thêm ví dụ huấn luyện'}</button>
                  {editingTrainingId !== null && <button type="button" onClick={() => { setEditingTrainingId(null); setTrainingForm({ y_dinh: 'payment', cau_hoi: '', phan_hoi: '', trang_thai: true }) }}>Hủy sửa</button>}
                </div>
              </form>
              <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Ý định</th><th>Câu hỏi mẫu / câu trả lời</th><th>Trạng thái</th><th>Thao tác</th></tr></thead><tbody>
                {trainingExamples.map((example) => (
                  <tr key={example.id}>
                    <td>{trainingIntents.find((intent) => intent.value === example.y_dinh)?.label ?? example.y_dinh}</td>
                    <td><strong>{example.cau_hoi}</strong><small>{example.phan_hoi}</small></td>
                    <td><span className={`admin-badge ${example.trang_thai ? 'published' : 'draft'}`}>{example.trang_thai ? 'Đang dùng' : 'Đã tắt'}</span></td>
                    <td><button type="button" onClick={() => editTrainingExample(example)}>Sửa</button><button type="button" onClick={() => void toggleTrainingExample(example)}>{example.trang_thai ? 'Tắt' : 'Bật'}</button><button className="admin-delete" type="button" onClick={() => void deleteTrainingExample(example)}>Xóa</button></td>
                  </tr>
                ))}
                {!loading && trainingExamples.length === 0 && <tr><td colSpan={4}>Chưa có ví dụ huấn luyện.</td></tr>}
                {loading && <tr><td colSpan={4}>Đang tải…</td></tr>}
              </tbody></table></div>
              <AdminPagination meta={trainingPageMeta} onChange={(page) => void refresh('training', token, page)} />
            </div>
          )}
        </section>
      </div>

      {formOpen && (
        <div className="commerce-backdrop" role="presentation" onClick={() => setFormOpen(false)}>
          <section className="commerce-dialog admin-product-dialog" role="dialog" aria-modal="true" aria-labelledby="product-form-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={() => setFormOpen(false)} aria-label="Đóng">×</button>
            <span className="eyebrow">QUẢN LÝ DANH MỤC</span><h2 id="product-form-title">{editingProduct ? 'Cập nhật sản phẩm' : 'Thêm sản phẩm'}</h2>
            <form className="commerce-form checkout-form" onSubmit={saveProduct}>
              <label>Tên sản phẩm<input required maxLength={255} value={productForm.name} onChange={(event) => setProductForm({ ...productForm, name: event.target.value })} /></label>
              <label>Danh mục<input required maxLength={100} placeholder="Laptop, Điện thoại…" value={productForm.category} onChange={(event) => setProductForm({ ...productForm, category: event.target.value })} /></label>
              <label>Thương hiệu<input maxLength={100} value={productForm.brand} onChange={(event) => setProductForm({ ...productForm, brand: event.target.value })} /></label>
              <label>Trạng thái<select value={productForm.status} onChange={(event) => setProductForm({ ...productForm, status: event.target.value as ProductForm['status'] })}><option value="draft">Bản nháp</option><option value="published">Đang bán</option></select></label>
              <label className="used-product-toggle checkout-wide"><input type="checkbox" checked={productForm.is_used} onChange={(event) => setProductForm({ ...productForm, is_used: event.target.checked })} /> Hàng cũ (ẩn khỏi cửa hàng)</label>
              <label>Giá bán<input required type="number" min="0" step="1" value={productForm.price} onChange={(event) => setProductForm({ ...productForm, price: event.target.value })} /></label>
              <label>Giá niêm yết<input type="number" min="0" step="1" value={productForm.list_price} onChange={(event) => setProductForm({ ...productForm, list_price: event.target.value })} /></label>
              <label>Tồn kho<input required type="number" min="0" step="1" value={productForm.stock} onChange={(event) => setProductForm({ ...productForm, stock: event.target.value })} /></label>
              <label className="checkout-wide">URL ảnh<input type="url" maxLength={2048} placeholder="https://…" value={productForm.image_url} onChange={(event) => setProductForm({ ...productForm, image_url: event.target.value })} /></label>
              <label className="checkout-wide">Mô tả<textarea maxLength={10000} rows={3} value={productForm.description} onChange={(event) => setProductForm({ ...productForm, description: event.target.value })} /></label>
              <label className="checkout-wide">Thông số kỹ thuật (JSON)<textarea rows={5} value={productForm.specifications} onChange={(event) => setProductForm({ ...productForm, specifications: event.target.value })} /></label>
              <p className="payment-notice checkout-wide">Chỉ xuất bản sản phẩm sau khi xác minh giá, tồn kho và quyền sử dụng ảnh.</p>
              {formError && <p className="commerce-error checkout-wide" role="alert">{formError}</p>}
              <button className="primary-button checkout-wide" type="submit">{editingProduct ? 'Lưu thay đổi' : 'Tạo sản phẩm'}</button>
            </form>
          </section>
        </div>
      )}
      {selectedOrder && (
        <div className="commerce-backdrop" role="presentation" onClick={() => setSelectedOrder(null)}>
          <section className="commerce-dialog admin-order-dialog" role="dialog" aria-modal="true" aria-labelledby="admin-order-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={() => setSelectedOrder(null)} aria-label="Đóng">×</button>
            <span className="eyebrow">CHI TIẾT ĐƠN HÀNG</span>
            <h2 id="admin-order-title">Đơn {selectedOrder.order.ma_dh}</h2>
            <div className="admin-order-facts">
              <p><strong>Người nhận:</strong> {selectedOrder.order.ten_nguoi_nhan || selectedOrder.customer?.ten_khach_hang || selectedOrder.customer?.ten_dang_nhap || '—'}</p>
              <p><strong>Điện thoại:</strong> {selectedOrder.order.dien_thoai_nguoi_nhan || '—'}</p>
              <p><strong>Email:</strong> {selectedOrder.customer?.email || '—'}</p>
              <p><strong>Ngày đặt:</strong> {selectedOrder.order.ngay_dat}</p>
              <p><strong>Địa chỉ:</strong> {selectedOrder.order.dia_chi_giao_hang || '—'}</p>
              <p><strong>Thanh toán:</strong> {selectedOrder.payment?.phuong_thuc === 'MoMo' ? 'MoMo QR' : selectedOrder.payment?.phuong_thuc === 'Chuyen khoan' ? 'Agribank QR' : selectedOrder.payment?.phuong_thuc || '—'} · {selectedOrder.payment?.trang_thai || '—'}</p>
              <p><strong>Trạng thái:</strong> {selectedOrder.order.trang_thai}</p>
              <p><strong>Tổng đơn:</strong> {formatMoney(selectedOrder.order.tong_tien)}</p>
              {selectedOrder.order.ma_van_don && <p><strong>Vận chuyển:</strong> {selectedOrder.order.don_vi_van_chuyen} · {selectedOrder.order.ma_van_don}{selectedOrder.order.ngay_giao_du_kien ? ` · Dự kiến ${selectedOrder.order.ngay_giao_du_kien}` : ''}</p>}
            </div>
            {selectedOrder.order.trang_thai === 'Dang Giao' && <form className="admin-tracking-form" onSubmit={(event) => { event.preventDefault(); void updateTracking() }}>
              <h3>Cập nhật vận chuyển</h3>
              <label>Đơn vị vận chuyển<input required maxLength={100} value={trackingForm.carrier} onChange={(event) => setTrackingForm({ ...trackingForm, carrier: event.target.value })} /></label>
              <label>Mã vận đơn<input required maxLength={100} value={trackingForm.tracking_code} onChange={(event) => setTrackingForm({ ...trackingForm, tracking_code: event.target.value })} /></label>
              <label>Ngày giao dự kiến (nếu đã được xác nhận)<input type="date" value={trackingForm.estimated_delivery} onChange={(event) => setTrackingForm({ ...trackingForm, estimated_delivery: event.target.value })} /></label>
              <p>Chỉ nhập ngày giao khi đã được hãng vận chuyển xác nhận; phí giao hàng không được ước tính tự động.</p>
              <button className="primary-button" type="submit">Lưu vận đơn</button>
            </form>}
            <h3>Sản phẩm</h3>
            <div className="admin-order-lines">{selectedOrder.items.map((item) => <div key={item.ma_sp}><span>{item.ten_sp || item.ma_sp} × {item.so_luong}</span><strong>{formatMoney(Number(item.don_gia) * item.so_luong)}</strong></div>)}</div>
            <h3>Lịch sử trạng thái</h3>
            <ol className="admin-order-history">{selectedOrder.history.map((entry, index) => <li key={`${entry.thoi_gian_tao}-${index}`}><strong>{entry.trang_thai}</strong><span>{entry.ghi_chu}</span><time>{entry.thoi_gian_tao}</time></li>)}</ol>
          </section>
        </div>
      )}
    </main>
  )
}

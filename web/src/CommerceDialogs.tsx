import { useCallback, useEffect, useRef, useState } from 'react'
import type { FormEvent } from 'react'
import { apiRequest } from './commerce'
import type { CartData, Customer } from './commerce'
import { translate } from './language'
import type { Language } from './language'

type AuthResponse = { data: Customer; token: string }
type CheckoutResponse = { data: { id: string; total: number } }
type OrderSummary = {
  ma_dh: string
  ngay_dat: string
  tong_tien: number
  trang_thai: string
  don_vi_van_chuyen?: string | null
  ma_van_don?: string | null
  ngay_giao_du_kien?: string | null
}
type OrderDetail = {
  order: OrderSummary & { ten_nguoi_nhan: string | null; dien_thoai_nguoi_nhan: string | null; dia_chi_giao_hang: string | null }
  payment: { phuong_thuc: string; trang_thai: string } | null
  items: { ma_sp: string; ten_sp: string; so_luong: number; don_gia: number }[]
  history: { trang_thai: string; ghi_chu: string | null; thoi_gian_tao: string }[]
}
type CustomerReturnRequest = { id: number; ma_dh: string; loai_yeu_cau: string; ly_do: string; mo_ta: string | null; trang_thai: string; ghi_chu_nhan_vien: string | null; created_at: string }
const returnStatusLabels: Record<string, string> = { 'Cho xu ly': 'Chờ xử lý', Approved: 'Đã chấp thuận', Rejected: 'Đã từ chối', Completed: 'Đã hoàn tất' }
type CustomerNotification = {
  id: number
  loai: string
  tieu_de: string
  noi_dung: string
  ma_dh: string | null
  read_at: string | null
  created_at: string
}
type CustomerVoucher = {
  code: string
  discount_type: 'percent' | 'fixed'
  discount_value: number
  minimum_order_value: number
  maximum_discount: number | null
  expires_at: string | null
  claimed: boolean
}
type EducationBenefit = {
  status: 'not_submitted' | 'pending' | 'approved' | 'rejected'
  verified: boolean
  email: string
  audience: string
  discount_percent: number | null
  review_note?: string | null
}
const educationLevels = [
  ['student_middle_high', 'Học sinh THCS/THPT · giảm 5%'],
  ['student_college', 'Sinh viên cao đẳng · giảm 7%'],
  ['student_university', 'Sinh viên đại học · giảm 9%'],
  ['student_masters', 'Học viên thạc sĩ · giảm 11%'],
  ['student_doctorate', 'Nghiên cứu sinh tiến sĩ · giảm 13%'],
  ['teacher', 'Giáo viên · giảm 15%'],
  ['lecturer_college', 'Giảng viên cao đẳng · giảm 17%'],
  ['lecturer_university', 'Giảng viên đại học · giảm 20%'],
] as const
type CheckoutForm = {
  recipient_name: string
  phone: string
  address: string
  ward: string
  district: string
  city: string
  payment_method: 'transfer' | 'momo' | 'cash'
}
type SavedAddress = {
  loai_dia_chi: string
  ten_nguoi_nhan: string | null
  so_dien_thoai: string | null
  chi_tiet_dia_chi: string
  phuong_xa: string | null
  quan_huyen: string | null
  thanh_pho: string | null
}

type Props = {
  language: Language
  token: string | null
  customer: Customer | null
  cart: CartData
  deliveryCity: string
  authOpen: boolean
  cartOpen: boolean
  ordersRequestId: number
  onCloseAuth: () => void
  onCloseCart: () => void
  onAuthenticated: (token: string, customer: Customer) => void
  onRequireAuthCheckout: () => void
  guestCartNeedsMerge: boolean
  resumeCheckout: boolean
  onCheckoutResumed: () => void
  onCartChanged: (cart: CartData) => void
  onUpdateQuantity: (productId: string, quantity: number) => Promise<void>
  onRemoveItem: (productId: string) => Promise<void>
}

const money = (amount: number) => `${new Intl.NumberFormat('vi-VN').format(amount)} ₫`
const emptyCart: CartData = { items: [], total: 0 }
const createIdempotencyKey = () => crypto.randomUUID()

export function CommerceDialogs({
  language,
  token,
  customer,
  cart,
  deliveryCity,
  authOpen,
  cartOpen,
  ordersRequestId,
  onCloseAuth,
  onCloseCart,
  onAuthenticated,
  onRequireAuthCheckout,
  guestCartNeedsMerge,
  resumeCheckout,
  onCheckoutResumed,
  onCartChanged,
  onUpdateQuantity,
  onRemoveItem,
}: Props) {
  const t = (text: string) => translate(language, text)
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login')
  const [authBusy, setAuthBusy] = useState(false)
  const [authError, setAuthError] = useState('')
  const [checkoutOpen, setCheckoutOpen] = useState(false)
  const [checkoutBusy, setCheckoutBusy] = useState(false)
  const checkoutKey = useRef<string | null>(null)
  const [checkoutError, setCheckoutError] = useState('')
  const [placedOrder, setPlacedOrder] = useState<{ id: string; total: number; payment: string } | null>(null)
  const [ordersOpen, setOrdersOpen] = useState(false)
  const [ordersLoading, setOrdersLoading] = useState(false)
  const [ordersError, setOrdersError] = useState('')
  const [orders, setOrders] = useState<OrderSummary[]>([])
  const [accountPanel, setAccountPanel] = useState<'orders' | 'vouchers' | 'education'>('orders')
  const [customerVouchers, setCustomerVouchers] = useState<CustomerVoucher[]>([])
  const [educationBenefit, setEducationBenefit] = useState<EducationBenefit | null>(null)
  const [educationEmail, setEducationEmail] = useState('')
  const [educationAudience, setEducationAudience] = useState<string>(educationLevels[0][0])
  const [educationEvidence, setEducationEvidence] = useState<File | null>(null)
  const [educationEvidenceInputKey, setEducationEvidenceInputKey] = useState(0)
  const [educationCode, setEducationCode] = useState('')
  const [educationCodeSent, setEducationCodeSent] = useState(false)
  const [promotionBusy, setPromotionBusy] = useState(false)
  const [promotionError, setPromotionError] = useState('')
  const [promotionMessage, setPromotionMessage] = useState('')
  const [ordersLoadedRequestId, setOrdersLoadedRequestId] = useState(0)
  const [closedOrdersRequestId, setClosedOrdersRequestId] = useState(0)
  const [returnRequests, setReturnRequests] = useState<CustomerReturnRequest[]>([])
  const [notifications, setNotifications] = useState<CustomerNotification[]>([])
  const [selectedOrder, setSelectedOrder] = useState<OrderDetail | null>(null)
  const [savedAddresses, setSavedAddresses] = useState<SavedAddress[]>([])
  const [addressLabel, setAddressLabel] = useState('Nha')
  const [addressMessage, setAddressMessage] = useState('')
  const [voucherCode, setVoucherCode] = useState('')
  const [appliedVoucher, setAppliedVoucher] = useState<{ code: string; discount: number; total: number } | null>(null)
  const [voucherError, setVoucherError] = useState('')
  const [voucherBusy, setVoucherBusy] = useState(false)
  const [returnType, setReturnType] = useState<'return' | 'exchange'>('return')
  const [returnReason, setReturnReason] = useState('damaged')
  const [returnDescription, setReturnDescription] = useState('')
  const [returnBusy, setReturnBusy] = useState(false)
  const [checkout, setCheckout] = useState<CheckoutForm>({
    recipient_name: customer?.name ?? '',
    phone: customer?.phone ?? '',
    address: '',
    ward: '',
    district: '',
    city: '',
    payment_method: 'transfer',
  })
  const selectedReturn = selectedOrder
    ? returnRequests.find((item) => item.ma_dh === selectedOrder.order.ma_dh)
    : undefined
  const canRequestReturn = selectedOrder?.order.trang_thai === 'Da Giao'
  const educationDiscountPreview = educationBenefit?.verified && educationBenefit.discount_percent !== null
    ? Math.min(2_000_000, Math.floor(cart.total * educationBenefit.discount_percent / 100))
    : 0
  const voucherDiscountPreview = appliedVoucher?.discount ?? 0
  const bestDiscountPreview = Math.max(educationDiscountPreview, voucherDiscountPreview)

  async function submitAuth(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (authBusy) return
    setAuthBusy(true)
    setAuthError('')
    const form = new FormData(event.currentTarget)
    const body = authMode === 'login'
      ? { identifier: String(form.get('identifier') ?? '').trim(), password: String(form.get('password') ?? '') }
      : {
          name: String(form.get('name') ?? '').trim(),
          username: String(form.get('username') ?? '').trim(),
          email: String(form.get('email') ?? '').trim(),
          phone: String(form.get('phone') ?? '').trim(),
          password: String(form.get('password') ?? ''),
          password_confirmation: String(form.get('password_confirmation') ?? ''),
        }

    try {
      const result = await apiRequest<AuthResponse>(
        authMode === 'login' ? '/auth/login' : '/auth/register',
        null,
        { method: 'POST', body: JSON.stringify(body) },
      )
      setCheckout((current) => ({
        ...current,
        recipient_name: result.data.name,
        phone: result.data.phone ?? current.phone,
        city: current.city || deliveryCity,
      }))
      onAuthenticated(result.token, result.data)
      onCloseAuth()
    } catch (reason) {
      setAuthError(reason instanceof Error ? reason.message : 'Không thể xác thực tài khoản.')
    } finally {
      setAuthBusy(false)
    }
  }

  async function updateQuantity(productId: string, quantity: number) {
    try {
      await onUpdateQuantity(productId, quantity)
    } catch (reason) {
      setCheckoutError(reason instanceof Error ? reason.message : 'Không thể cập nhật giỏ hàng.')
    }
  }

  async function removeItem(productId: string) {
    try {
      await onRemoveItem(productId)
      setCheckoutError('')
    } catch (reason) {
      setCheckoutError(reason instanceof Error ? reason.message : 'Không thể xóa sản phẩm khỏi giỏ.')
    }
  }

  async function submitCheckout(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || checkoutBusy) return
    setCheckoutBusy(true)
    setCheckoutError('')
    checkoutKey.current ??= createIdempotencyKey()
    try {
      const result = await apiRequest<CheckoutResponse>('/orders', token, {
        method: 'POST',
        headers: { 'Idempotency-Key': checkoutKey.current },
        body: JSON.stringify({ ...checkout, voucher_code: appliedVoucher?.code }),
      })
      checkoutKey.current = null
      setPlacedOrder({
        ...result.data,
        payment: checkout.payment_method,
      })
      onCartChanged(emptyCart)
    } catch (reason) {
      setCheckoutError(reason instanceof Error ? reason.message : 'Không thể tạo đơn hàng.')
    } finally {
      setCheckoutBusy(false)
    }
  }

  const openCheckout = useCallback(async () => {
    setCheckoutError('')
    setAddressMessage('')
    setVoucherError('')
    setAppliedVoucher(null)
    setVoucherCode('')
    setCheckout((current) => ({
      ...current,
      recipient_name: customer?.name ?? current.recipient_name,
      phone: customer?.phone ?? current.phone,
      city: current.city || deliveryCity,
    }))
    setCheckoutOpen(true)
    if (token) {
      try {
        const result = await apiRequest<{ data: SavedAddress[] }>('/addresses', token)
        setSavedAddresses(result.data)
      } catch (reason) {
        setCheckoutError(reason instanceof Error ? reason.message : 'Không tải được địa chỉ đã lưu.')
      }
      try {
        const result = await apiRequest<{ data: CustomerVoucher[] }>('/vouchers', token)
        setCustomerVouchers(result.data)
      } catch (reason) {
        setVoucherError(reason instanceof Error ? reason.message : 'Không tải được mã giảm giá đã thu thập.')
      }
      try {
        const result = await apiRequest<{ data: EducationBenefit }>('/education-discount', token)
        setEducationBenefit(result.data)
      } catch (reason) {
        setVoucherError(reason instanceof Error ? reason.message : 'Không tải được ưu đãi tài khoản.')
      }
    }
  }, [token, customer, deliveryCity])

  async function applyVoucher() {
    if (!token || voucherBusy) return
    setVoucherBusy(true)
    setVoucherError('')
    setAppliedVoucher(null)
    try {
      const result = await apiRequest<{ data: { code: string; discount: number; total: number } }>('/vouchers/validate', token, {
        method: 'POST',
        body: JSON.stringify({ code: voucherCode.trim() }),
      })
      setAppliedVoucher(result.data)
    } catch (reason) {
      setVoucherError(reason instanceof Error ? reason.message : 'Không thể áp dụng mã giảm giá.')
    } finally {
      setVoucherBusy(false)
    }
  }

  async function saveAddress() {
    if (!token) return
    setAddressMessage('')
    try {
      await apiRequest('/addresses', token, {
        method: 'POST',
        body: JSON.stringify({
          label: addressLabel.trim(),
          recipient_name: checkout.recipient_name,
          phone: checkout.phone,
          address: checkout.address,
          ward: checkout.ward,
          district: checkout.district,
          city: checkout.city,
        }),
      })
      const result = await apiRequest<{ data: SavedAddress[] }>('/addresses', token)
      setSavedAddresses(result.data)
      setAddressMessage('Đã lưu địa chỉ.')
    } catch (reason) {
      setAddressMessage(reason instanceof Error ? reason.message : 'Không thể lưu địa chỉ.')
    }
  }

  async function deleteAddress(label: string) {
    if (!token) return
    try {
      await apiRequest(`/addresses/${encodeURIComponent(label)}`, token, { method: 'DELETE' })
      setSavedAddresses((current) => current.filter((address) => address.loai_dia_chi !== label))
    } catch (reason) {
      setAddressMessage(reason instanceof Error ? reason.message : 'Không thể xóa địa chỉ.')
    }
  }

  useEffect(() => {
    if (!resumeCheckout || !token) return
    let active = true
    apiRequest<{ data: SavedAddress[] }>('/addresses', token)
      .then((result) => {
        if (active) setSavedAddresses(result.data)
      })
      .catch((reason: unknown) => {
        if (active) setCheckoutError(reason instanceof Error ? reason.message : 'Không tải được địa chỉ đã lưu.')
      })
      .finally(() => {
        if (active) setCheckoutOpen(true)
        onCheckoutResumed()
      })
    return () => { active = false }
  }, [resumeCheckout, token, onCheckoutResumed])

  const loadOrders = useCallback(async (requestId?: number) => {
    if (!token) return
    try {
      const [result, noticeResult, returnResult] = await Promise.all([
        apiRequest<{ data: OrderSummary[] }>('/orders', token),
        apiRequest<{ data: CustomerNotification[] }>('/notifications', token),
        apiRequest<{ data: CustomerReturnRequest[] }>('/returns', token),
      ])
      setOrders(result.data)
      setNotifications(noticeResult.data)
      setReturnRequests(returnResult.data)
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không tải được lịch sử đơn hàng.')
    } finally {
      setOrdersLoading(false)
      if (requestId !== undefined) setOrdersLoadedRequestId(requestId)
    }
  }, [token])

  const openOrders = useCallback(() => {
    if (!token) return
    setOrdersOpen(true)
    setAccountPanel('orders')
    setOrdersLoading(true)
    setOrdersError('')
    setSelectedOrder(null)
    onCloseCart()
    void loadOrders()
  }, [token, onCloseCart, loadOrders])

  async function loadPromotions() {
    if (!token) return
    setPromotionError('')
    setPromotionMessage('')
    setPromotionBusy(true)
    try {
      const [voucherResult, educationResult] = await Promise.all([
        apiRequest<{ data: CustomerVoucher[] }>('/vouchers', token),
        apiRequest<{ data: EducationBenefit }>('/education-discount', token),
      ])
      setCustomerVouchers(voucherResult.data)
      setEducationBenefit(educationResult.data)
    } catch (reason) {
      setPromotionError(reason instanceof Error ? reason.message : 'Không tải được ưu đãi.')
    } finally {
      setPromotionBusy(false)
    }
  }

  async function claimVoucher(code: string) {
    if (!token || promotionBusy) return
    setPromotionBusy(true)
    setPromotionError('')
    setPromotionMessage('')
    try {
      await apiRequest(`/vouchers/${encodeURIComponent(code)}/claim`, token, { method: 'POST' })
      setPromotionMessage(`Đã thu thập mã ${code}.`)
      await loadPromotions()
    } catch (reason) {
      setPromotionError(reason instanceof Error ? reason.message : 'Không thể thu thập mã giảm giá.')
    } finally {
      setPromotionBusy(false)
    }
  }

  async function requestEducationCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || promotionBusy) return
    if (!educationEvidence) {
      setPromotionError('Tải lên thẻ học sinh/sinh viên hoặc giấy tờ nghề nghiệp để nhân viên xem xét.')
      return
    }
    setPromotionBusy(true)
    setPromotionError('')
    setPromotionMessage('')
    try {
      const result = await apiRequest<{ message: string }>('/education-discount/request-code', token, {
        method: 'POST',
        body: JSON.stringify({ email: educationEmail.trim(), audience: educationAudience }),
      })
      setEducationCodeSent(true)
      setPromotionMessage(result.message)
    } catch (reason) {
      setPromotionError(reason instanceof Error ? reason.message : 'Không thể gửi mã xác minh.')
    } finally {
      setPromotionBusy(false)
    }
  }

  async function verifyEducationCode(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || promotionBusy) return
    if (!educationEvidence) {
      setPromotionError('Vui lòng tải lại minh chứng trước khi xác minh email.')
      return
    }
    setPromotionBusy(true)
    setPromotionError('')
    setPromotionMessage('')
    try {
      const formData = new FormData()
      formData.set('code', educationCode.trim())
      formData.set('evidence', educationEvidence)
      const result = await apiRequest<{ message: string }>('/education-discount/verify-code', token, {
        method: 'POST',
        body: formData,
      })
      setEducationCodeSent(false)
      setEducationCode('')
      setEducationEvidence(null)
      setEducationEvidenceInputKey((key) => key + 1)
      setPromotionMessage(result.message)
      await loadPromotions()
    } catch (reason) {
      setPromotionError(reason instanceof Error ? reason.message : 'Không thể xác minh mã.')
    } finally {
      setPromotionBusy(false)
    }
  }

  useEffect(() => {
    if (ordersRequestId > 0) void loadOrders(ordersRequestId)
  }, [ordersRequestId, loadOrders])

  function closeOrders() {
    setOrdersOpen(false)
    setSelectedOrder(null)
    setOrdersError('')
    setClosedOrdersRequestId(ordersRequestId)
  }

  async function markNotificationRead(notification: CustomerNotification) {
    if (!token || notification.read_at) return
    try {
      await apiRequest(`/notifications/${notification.id}/read`, token, { method: 'PATCH' })
      setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, read_at: new Date().toISOString() } : item))
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không thể cập nhật thông báo.')
    }
  }

  async function markAllNotificationsRead() {
    if (!token) return
    try {
      await apiRequest('/notifications/read-all', token, { method: 'PATCH' })
      setNotifications((current) => current.map((item) => ({ ...item, read_at: item.read_at ?? new Date().toISOString() })))
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không thể cập nhật thông báo.')
    }
  }

  async function viewOrder(orderId: string) {
    if (!token) return
    setOrdersError('')
    try {
      const result = await apiRequest<{ data: OrderDetail }>(`/orders/${encodeURIComponent(orderId)}`, token)
      setSelectedOrder(result.data)
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không tải được chi tiết đơn hàng.')
    }
  }

  async function cancelOrder(orderId: string) {
    if (!token || !window.confirm('Bạn có chắc chắn muốn hủy đơn hàng này không?')) return
    setOrdersError('')
    try {
      await apiRequest(`/orders/${encodeURIComponent(orderId)}/cancel`, token, { method: 'POST' })
      const result = await apiRequest<{ data: OrderSummary[] }>('/orders', token)
      setOrders(result.data)
      if (selectedOrder?.order.ma_dh === orderId) await viewOrder(orderId)
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không thể hủy đơn hàng.')
    }
  }

  async function submitReturnRequest(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !selectedOrder || returnBusy) return
    setReturnBusy(true)
    setOrdersError('')
    try {
      await apiRequest(`/orders/${encodeURIComponent(selectedOrder.order.ma_dh)}/returns`, token, {
        method: 'POST',
        body: JSON.stringify({ type: returnType, reason: returnReason, description: returnDescription.trim() || null }),
      })
      const result = await apiRequest<{ data: CustomerReturnRequest[] }>('/returns', token)
      setReturnRequests(result.data)
      setReturnDescription('')
    } catch (reason) {
      setOrdersError(reason instanceof Error ? reason.message : 'Không gửi được yêu cầu đổi/trả.')
    } finally {
      setReturnBusy(false)
    }
  }

  return (
    <>
      {authOpen && (
        <div className="commerce-backdrop" role="presentation" onClick={onCloseAuth}>
          <section className="commerce-dialog auth-dialog" role="dialog" aria-modal="true" aria-labelledby="auth-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={onCloseAuth} aria-label={t('Đóng')}>×</button>
            <span className="eyebrow">{language === 'vi' ? 'TÀI KHOẢN TECHSTORE' : 'TECHSTORE ACCOUNT'}</span>
            <h2 id="auth-title">{authMode === 'login' ? t('Đăng nhập') : language === 'vi' ? 'Tạo tài khoản' : 'Create account'}</h2>
            <p className="commerce-intro">{language === 'vi' ? 'Đăng nhập để quản lý giỏ hàng và theo dõi đơn hàng của bạn.' : 'Log in to manage your cart and track your orders.'}</p>
            <form className="commerce-form" onSubmit={submitAuth}>
              {authMode === 'register' && (
                <>
                  <label>{language === 'vi' ? 'Họ và tên' : 'Full name'}<input name="name" required maxLength={200} autoComplete="name" /></label>
                  <label>{language === 'vi' ? 'Tên đăng nhập' : 'Username'}<input name="username" required minLength={3} maxLength={50} autoComplete="username" /></label>
                  <label>Email<input name="email" type="email" required maxLength={255} autoComplete="email" /></label>
                  <label>{language === 'vi' ? 'Số điện thoại' : 'Phone number'}<input name="phone" type="tel" required maxLength={20} autoComplete="tel" /></label>
                </>
              )}
              {authMode === 'login' && (
                <label>{language === 'vi' ? 'Email hoặc tên đăng nhập' : 'Email or username'}<input name="identifier" required maxLength={255} autoComplete="username" /></label>
              )}
              <label>{language === 'vi' ? 'Mật khẩu' : 'Password'}<input name="password" type="password" required minLength={authMode === 'register' ? 8 : undefined} maxLength={72} autoComplete={authMode === 'login' ? 'current-password' : 'new-password'} /></label>
              {authMode === 'register' && (
                <label>{language === 'vi' ? 'Nhập lại mật khẩu' : 'Confirm password'}<input name="password_confirmation" type="password" required maxLength={72} autoComplete="new-password" /></label>
              )}
              {authError && <p className="commerce-error" role="alert">{authError}</p>}
              <button className="primary-button" type="submit" disabled={authBusy}>
                {authBusy ? (language === 'vi' ? 'Đang xử lý…' : 'Working…') : authMode === 'login' ? t('Đăng nhập') : language === 'vi' ? 'Đăng ký' : 'Sign up'}
              </button>
            </form>
            <p className="auth-switch">
              {authMode === 'login' ? (language === 'vi' ? 'Chưa có tài khoản?' : "Don't have an account?") : (language === 'vi' ? 'Đã có tài khoản?' : 'Already have an account?')}
              <button type="button" onClick={() => { setAuthMode(authMode === 'login' ? 'register' : 'login'); setAuthError('') }}>
                {authMode === 'login' ? (language === 'vi' ? 'Đăng ký ngay' : 'Sign up now') : t('Đăng nhập')}
              </button>
            </p>
          </section>
        </div>
      )}

      {cartOpen && (
        <div className="commerce-backdrop" role="presentation" onClick={onCloseCart}>
          <aside className="cart-drawer" role="dialog" aria-modal="true" aria-labelledby="cart-title" onClick={(event) => event.stopPropagation()}>
            <header className="cart-drawer-header">
              <div><span className="eyebrow">TECHSTORE</span><h2 id="cart-title">{t('Giỏ hàng')} <small>({cart.items.length})</small></h2></div>
              <button className="commerce-close" type="button" onClick={onCloseCart} aria-label={`${t('Đóng')} ${t('Giỏ hàng')}`}>×</button>
            </header>
            {customer && <button className="orders-open-button" type="button" onClick={() => void openOrders()}>{language === 'vi' ? 'Theo dõi đơn hàng của tôi' : 'Track my orders'} →</button>}
            {!customer && <p className="commerce-intro">{language === 'vi' ? 'Giỏ hàng này được lưu trên thiết bị. Đăng nhập khi thanh toán để đồng bộ và đặt hàng.' : 'This cart is saved on this device. Log in at checkout to sync it and place your order.'}</p>}
            {cart.items.length === 0
              ? <div className="cart-empty"><span aria-hidden="true">♧</span><h3>{language === 'vi' ? 'Giỏ hàng đang trống' : 'Your cart is empty'}</h3><p>{language === 'vi' ? 'Chọn sản phẩm bạn yêu thích để bắt đầu đặt hàng.' : 'Choose your favorite products to get started.'}</p><button type="button" onClick={onCloseCart}>{language === 'vi' ? 'Tiếp tục khám phá' : 'Continue exploring'}</button></div>
              : (
                <>
                  <div className="cart-items">
                    {cart.items.map((item) => (
                      <article className="cart-item" key={item.product_id}>
                        <div className="cart-item-info"><strong>{item.name}</strong><span>{money(item.price)}</span></div>
                        <div className="cart-item-actions">
                          <div className="quantity-control">
                            <button type="button" aria-label={`Giảm số lượng ${item.name}`} disabled={item.quantity <= 1} onClick={() => updateQuantity(item.product_id, item.quantity - 1)}>−</button>
                            <span>{item.quantity}</span>
                            <button type="button" aria-label={`Tăng số lượng ${item.name}`} disabled={item.quantity >= item.stock || item.quantity >= 20} onClick={() => updateQuantity(item.product_id, item.quantity + 1)}>+</button>
                          </div>
                          <button className="remove-item" type="button" onClick={() => removeItem(item.product_id)}>{t('Xóa')}</button>
                        </div>
                        <span className="cart-line-total">{money(item.line_total)}</span>
                      </article>
                    ))}
                  </div>
                  <div className="cart-summary">
                    <div><span>{language === 'vi' ? 'Tạm tính' : 'Subtotal'}</span><strong>{money(cart.total)}</strong></div>
                    <div className="cart-final-total"><span>{language === 'vi' ? 'Tổng tiền hàng' : 'Order total'}</span><strong>{money(cart.total)}</strong></div>
                    <small>{language === 'vi' ? 'Chưa gồm phí giao hàng. Cửa hàng sẽ xác nhận phí và thời gian giao trước khi hoàn tất đơn.' : 'Excludes delivery fees. The store will confirm delivery fees and timing before completing the order.'}</small>
                    {checkoutError && <p className="commerce-error" role="alert">{checkoutError}</p>}
                    <button className="primary-button" type="button" onClick={() => !token || guestCartNeedsMerge ? onRequireAuthCheckout() : void openCheckout()}>
                      {language === 'vi' ? 'Tiến hành đặt hàng' : 'Proceed to checkout'}
                    </button>
                  </div>
                </>
              )}
          </aside>
        </div>
      )}

      {(ordersOpen || ordersRequestId > closedOrdersRequestId) && (
        <div className="commerce-backdrop" role="presentation" onClick={closeOrders}>
          <section className="commerce-dialog orders-dialog" role="dialog" aria-modal="true" aria-labelledby="orders-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={closeOrders} aria-label={t('Đóng')}>×</button>
            <span className="eyebrow">{language === 'vi' ? 'TÀI KHOẢN TECHSTORE' : 'TECHSTORE ACCOUNT'}</span>
            <h2 id="orders-title">{selectedOrder ? `${language === 'vi' ? 'Đơn hàng' : 'Order'} ${selectedOrder.order.ma_dh}` : t('Đơn hàng của tôi')}</h2>
            {!selectedOrder && <nav className="customer-account-tabs" aria-label={t('Thông tin tài khoản')}>
              <button type="button" className={accountPanel === 'orders' ? 'active' : ''} onClick={() => { setAccountPanel('orders'); setPromotionError('') }}>{t('Lịch sử mua hàng')}</button>
              <button type="button" className={accountPanel === 'vouchers' ? 'active' : ''} onClick={() => { setAccountPanel('vouchers'); void loadPromotions() }}>{t('Mã giảm giá')}</button>
              <button type="button" className={accountPanel === 'education' ? 'active' : ''} onClick={() => { setAccountPanel('education'); void loadPromotions() }}>{t('Ưu đãi HSSV–GV')}</button>
            </nav>}
            {ordersError && <p className="commerce-error" role="alert">{ordersError}</p>}
            {accountPanel === 'orders' && (ordersLoading || (ordersRequestId > closedOrdersRequestId && ordersLoadedRequestId < ordersRequestId)) && <p className="detail-status">{t('Đang tải đơn hàng…')}</p>}
            {accountPanel === 'orders' && !selectedOrder && notifications.length > 0 && (
              <section className="customer-notifications" aria-label={t('Thông báo tài khoản')}>
                <header><h3>{language === 'vi' ? `Thông báo của bạn (${notifications.filter((notification) => !notification.read_at).length} chưa đọc)` : `Your notifications (${notifications.filter((notification) => !notification.read_at).length} unread)`}</h3>{notifications.some((notification) => !notification.read_at) && <button type="button" onClick={() => void markAllNotificationsRead()}>{t('Đánh dấu đã đọc hết')}</button>}</header>
                {notifications.map((notification) => (
                  <button className={`customer-notification${notification.read_at ? '' : ' unread'}`} type="button" key={notification.id} onClick={() => void markNotificationRead(notification)}>
                    <strong>{notification.tieu_de}</strong><span>{notification.noi_dung}</span><time>{new Date(notification.created_at).toLocaleString(language === 'vi' ? 'vi-VN' : 'en-US')}</time>
                  </button>
                ))}
              </section>
            )}
            {accountPanel === 'orders' && !ordersLoading && !selectedOrder && orders.length === 0 && !ordersError && <p className="commerce-intro">{t('Bạn chưa có đơn hàng nào.')}</p>}
            {accountPanel === 'orders' && !selectedOrder && orders.map((order) => (
              <article className="order-list-item" key={order.ma_dh}>
                <div><strong>{order.ma_dh}</strong><span>{new Date(order.ngay_dat).toLocaleDateString(language === 'vi' ? 'vi-VN' : 'en-US')}</span></div>
                <div><span className={`order-status status-${order.trang_thai === 'Da Giao' ? 'done' : order.trang_thai === 'huy' ? 'cancelled' : order.trang_thai === 'Dang Giao' ? 'shipping' : 'pending'}`}>{order.trang_thai}</span><strong>{money(Number(order.tong_tien))}</strong>{order.ma_van_don && <small>{order.don_vi_van_chuyen} · {order.ma_van_don}</small>}</div>
                <button type="button" onClick={() => void viewOrder(order.ma_dh)}>{t('Xem chi tiết')}</button>
              </article>
            ))}
            {accountPanel === 'orders' && selectedOrder && (
              <div className="order-detail-view">
                <button className="order-back-button" type="button" onClick={() => setSelectedOrder(null)}>← {t('Tất cả đơn hàng')}</button>
                <div className="order-facts">
                  <p>{t('Trạng thái:')} <strong>{selectedOrder.order.trang_thai}</strong></p>
                  <p>{t('Ngày đặt:')} <strong>{new Date(selectedOrder.order.ngay_dat).toLocaleDateString(language === 'vi' ? 'vi-VN' : 'en-US')}</strong></p>
                  <p>{t('Người nhận:')} <strong>{selectedOrder.order.ten_nguoi_nhan || '—'}</strong></p>
                  <p>{t('Điện thoại:')} <strong>{selectedOrder.order.dien_thoai_nguoi_nhan || '—'}</strong></p>
                  <p>{t('Địa chỉ:')} <strong>{selectedOrder.order.dia_chi_giao_hang || '—'}</strong></p>
                  <p>{t('Thanh toán:')} <strong>{selectedOrder.payment?.phuong_thuc === 'Chuyen khoan' ? t('Chuyển khoản Agribank QR') : selectedOrder.payment?.phuong_thuc === 'MoMo' ? 'MoMo QR' : t('Thanh toán khi nhận hàng')} · {selectedOrder.payment?.trang_thai || t('Chưa rõ')}</strong></p>
                  <p>{t('Vận chuyển:')} <strong>{selectedOrder.order.don_vi_van_chuyen ? `${selectedOrder.order.don_vi_van_chuyen} · ${selectedOrder.order.ma_van_don}` : t('Cửa hàng sẽ cập nhật sau khi bàn giao đơn')}</strong></p>
                  {selectedOrder.order.ngay_giao_du_kien && <p>{t('Ngày giao dự kiến:')} <strong>{new Date(`${selectedOrder.order.ngay_giao_du_kien}T00:00:00`).toLocaleDateString(language === 'vi' ? 'vi-VN' : 'en-US')}</strong></p>}
                </div>
                <h3>{t('Sản phẩm')}</h3>
                {selectedOrder.items.map((item) => <div className="order-product-line" key={item.ma_sp}><span>{item.ten_sp} × {item.so_luong}</span><strong>{money(Number(item.don_gia) * Number(item.so_luong))}</strong></div>)}
                <div className="order-product-line order-total"><span>{t('Tổng cộng')}</span><strong>{money(Number(selectedOrder.order.tong_tien))}</strong></div>
                <h3>{t('Lịch sử trạng thái')}</h3>
                <ol className="order-history">
                  {selectedOrder.history.map((entry, index) => <li key={`${entry.trang_thai}-${index}`}><strong>{entry.trang_thai}</strong><span>{entry.ghi_chu}</span><time>{new Date(entry.thoi_gian_tao).toLocaleString(language === 'vi' ? 'vi-VN' : 'en-US')}</time></li>)}
                </ol>
                {selectedOrder.order.trang_thai === 'Cho xac nhan' && <button className="cancel-order-button" type="button" onClick={() => void cancelOrder(selectedOrder.order.ma_dh)}>{t('Hủy đơn hàng')}</button>}
                {selectedReturn && <section className="return-request-status"><h3>{t('Yêu cầu đổi/trả')}</h3><p>{selectedReturn.loai_yeu_cau === 'return' ? t('Đổi / trả') : t('Đổi sản phẩm')} · {returnStatusLabels[selectedReturn.trang_thai] ?? selectedReturn.trang_thai}</p>{selectedReturn.ghi_chu_nhan_vien && <p>{t('Phản hồi cửa hàng:')} {selectedReturn.ghi_chu_nhan_vien}</p>}</section>}
                {!selectedReturn && canRequestReturn && <form className="commerce-form return-request-form" onSubmit={(event) => void submitReturnRequest(event)}>
                  <h3>{t('Gửi yêu cầu trong 7 ngày kể từ khi nhận hàng')}</h3>
                  <p className="commerce-intro">{t('Cửa hàng sẽ xem xét yêu cầu và phản hồi trong tài khoản; việc gửi yêu cầu chưa đồng nghĩa với hoàn tiền tự động.')}</p>
                  <label>{t('Loại yêu cầu')}<select value={returnType} onChange={(event) => setReturnType(event.target.value as 'return' | 'exchange')}><option value="return">{t('Đổi / trả')}</option><option value="exchange">{t('Đổi sản phẩm')}</option></select></label>
                  <label>{t('Lý do')}<select value={returnReason} onChange={(event) => setReturnReason(event.target.value)}><option value="damaged">{t('Sản phẩm hư hỏng')}</option><option value="wrong_item">{t('Giao sai sản phẩm')}</option><option value="defective">{t('Lỗi kỹ thuật')}</option><option value="changed_mind">{t('Đổi ý')}</option><option value="other">{t('Lý do khác')}</option></select></label>
                  <label>{t('Mô tả thêm')}<textarea maxLength={2000} value={returnDescription} onChange={(event) => setReturnDescription(event.target.value)} /></label>
                  <button className="primary-button" type="submit" disabled={returnBusy}>{returnBusy ? t('Đang gửi…') : t('Gửi yêu cầu')}</button>
                </form>}
              </div>
            )}
            {accountPanel === 'vouchers' && <section className="customer-promotion-panel">
              <h3>{t('Mã giảm giá của bạn')}</h3>
              <p className="commerce-intro">{t('Thu thập mã còn hiệu lực tại đây, sau đó chọn mã khi thanh toán. Mỗi mã chỉ dùng một lần cho mỗi tài khoản.')}</p>
              {promotionError && <p className="commerce-error" role="alert">{promotionError}</p>}
              {promotionMessage && <p className="promotion-message" role="status">{promotionMessage}</p>}
              {promotionBusy && customerVouchers.length === 0 && <p className="detail-status">{t('Đang tải mã giảm giá…')}</p>}
              {!promotionBusy && customerVouchers.length === 0 && <p className="commerce-intro">{t('Hiện chưa có mã giảm giá khả dụng.')}</p>}
              {customerVouchers.map((voucher) => (
                <article className="customer-voucher-card" key={voucher.code}>
                  <div>
                    <strong>{voucher.code}</strong>
                    <span>{language === 'vi' ? 'Giảm' : 'Discount'} {voucher.discount_type === 'percent' ? `${voucher.discount_value}%` : money(voucher.discount_value)}</span>
                    <small>{language === 'vi' ? 'Đơn tối thiểu' : 'Minimum order'} {money(voucher.minimum_order_value)}{voucher.maximum_discount ? ` · ${language === 'vi' ? 'tối đa' : 'up to'} ${money(voucher.maximum_discount)}` : ''}</small>
                    {voucher.expires_at && <small>{language === 'vi' ? 'Hạn dùng:' : 'Expires:'} {new Date(voucher.expires_at).toLocaleDateString(language === 'vi' ? 'vi-VN' : 'en-US')}</small>}
                  </div>
                  <button type="button" disabled={voucher.claimed || promotionBusy} onClick={() => void claimVoucher(voucher.code)}>
                    {voucher.claimed ? t('Đã thu thập') : promotionBusy ? (language === 'vi' ? 'Đang xử lý…' : 'Processing…') : t('Thu thập')}
                  </button>
                </article>
              ))}
            </section>}
            {accountPanel === 'education' && <section className="customer-promotion-panel">
              <h3>{t('Ưu đãi học sinh, sinh viên và nhà giáo')}</h3>
              <p className="commerce-intro">{t('Xác minh email trường và gửi minh chứng để nhân viên duyệt đúng đối tượng. Ưu đãi tối đa 2.000.000₫ mỗi đơn; không cộng dồn với mã giảm giá.')}</p>
              {promotionError && <p className="commerce-error" role="alert">{promotionError}</p>}
              {promotionMessage && <p className="promotion-message" role="status">{promotionMessage}</p>}
              {educationBenefit?.status === 'approved' && educationBenefit.verified ? (
                <div className="education-benefit-confirmed">
                  <strong>Đã xác minh: {educationBenefit.audience} · giảm {educationBenefit.discount_percent}%</strong>
                  <span>{educationBenefit.email}</span>
                  <p>{t('Ưu đãi được tự động xét khi đặt hàng.')}</p>
                </div>
              ) : (
                <>
                  {educationBenefit?.status === 'pending' && (
                    <div className="education-benefit-confirmed">
                      <strong>{t('Hồ sơ đang chờ nhân viên duyệt')}</strong>
                      <span>{educationBenefit.audience} · {educationBenefit.email}</span>
                      <p>{t('Email đã xác minh. Ưu đãi chỉ được áp dụng sau khi hồ sơ và minh chứng được duyệt.')}</p>
                    </div>
                  )}
                  {educationBenefit?.status === 'rejected' && (
                    <div className="education-benefit-confirmed">
                      <strong>{t('Hồ sơ chưa được duyệt')}</strong>
                      <span>{educationBenefit.review_note || t('Bạn có thể gửi lại hồ sơ với minh chứng phù hợp.')}</span>
                    </div>
                  )}
                  {educationBenefit?.status !== 'pending' && <form className="commerce-form education-verification-form" onSubmit={(event) => void requestEducationCode(event)}>
                    <label>{t('Đối tượng')}<select value={educationAudience} onChange={(event) => setEducationAudience(event.target.value)}>
                      {educationLevels.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select></label>
                    <label>{t('Email trường')}<input type="email" required maxLength={255} value={educationEmail} onChange={(event) => setEducationEmail(event.target.value)} placeholder={language === 'vi' ? 'ten@truong.edu.vn' : 'name@school.edu'} /></label>
                    <label>{t('Minh chứng (JPG, PNG hoặc PDF, tối đa 5 MB)')}<input key={educationEvidenceInputKey} type="file" accept=".jpg,.jpeg,.png,.pdf" required onChange={(event) => setEducationEvidence(event.target.files?.[0] ?? null)} /></label>
                    <p>{t('Chỉ quản trị viên được xem tệp để xác minh đối tượng ưu đãi.')}</p>
                    <button className="primary-button" type="submit" disabled={promotionBusy}>{promotionBusy ? t('Đang gửi…') : t('Gửi mã xác minh')}</button>
                  </form>}
                  {educationBenefit?.status !== 'pending' && educationCodeSent && <form className="commerce-form education-verification-form" onSubmit={(event) => void verifyEducationCode(event)}>
                    <label>{language === 'vi' ? 'Mã OTP (6 chữ số)' : 'OTP code (6 digits)'}<input inputMode="numeric" pattern="[0-9]{6}" maxLength={6} required value={educationCode} onChange={(event) => setEducationCode(event.target.value)} /></label>
                    <button className="primary-button" type="submit" disabled={promotionBusy}>{promotionBusy ? t('Đang xác minh…') : t('Xác minh email')}</button>
                  </form>}
                </>
              )}
            </section>}
          </section>
        </div>
      )}

      {(checkoutOpen || resumeCheckout) && (
        <div className="commerce-backdrop" role="presentation" onClick={() => { setCheckoutOpen(false); setPlacedOrder(null) }}>
          <section className="commerce-dialog checkout-dialog" role="dialog" aria-modal="true" aria-labelledby="checkout-title" onClick={(event) => event.stopPropagation()}>
            <button className="commerce-close" type="button" onClick={() => { setCheckoutOpen(false); setPlacedOrder(null) }} aria-label={t('Đóng')}>×</button>
            {!placedOrder ? (
              <>
                <span className="eyebrow">{t('HOÀN TẤT ĐẶT HÀNG')}</span>
                <h2 id="checkout-title">{t('Thông tin giao hàng')}</h2>
                <ol className="checkout-steps" aria-label={t('Các bước đặt hàng')}>
                  <li><span aria-hidden="true">✓</span> {t('Giỏ hàng')}</li>
                  <li aria-current="step"><span aria-hidden="true">2</span> {t('Thông tin giao hàng')}</li>
                  <li><span aria-hidden="true">3</span> {t('Chọn thanh toán')}</li>
                </ol>
                <section className="checkout-summary" aria-label={t('Tổng kết thanh toán')}>
                  <div><span>{t('Tạm tính sản phẩm')}</span><strong>{money(cart.total)}</strong></div>
                  {bestDiscountPreview > 0 && <div><span>{voucherDiscountPreview > educationDiscountPreview ? `${language === 'vi' ? 'Ưu đãi' : 'Offer'} ${appliedVoucher?.code}` : t('Ưu đãi HSSV–GV')}</span><strong>−{money(bestDiscountPreview)}</strong></div>}
                  <div className="checkout-grand-total"><span>{t('Tổng tiền hàng · chưa gồm phí giao')}</span><strong>{money(cart.total - bestDiscountPreview)}</strong></div>
                  <p>{t('Chưa bao gồm phí giao hàng. Cửa hàng sẽ xác nhận phí và thời gian giao trước khi hoàn tất.')}</p>
                </section>
                <details className="checkout-items">
                  <summary>{language === 'vi' ? 'Xem' : 'View'} {cart.items.length} {t('sản phẩm trong đơn')}</summary>
                  {cart.items.map((item) => <div key={item.product_id}><span>{item.name} × {item.quantity}</span><strong>{money(item.line_total)}</strong></div>)}
                </details>
                <p className="shipping-disclaimer">{t('Cửa hàng cần xác nhận phí giao hàng, thời gian nhận dự kiến và tình trạng đơn sau khi tiếp nhận; hiện chưa có bảng phí hoặc kết nối hãng vận chuyển.')}</p>
                <form className="commerce-form checkout-form" onSubmit={submitCheckout}>
                  {savedAddresses.length > 0 && (
                    <label className="checkout-wide">{t('Địa chỉ đã lưu')}
                      <select value="" onChange={(event) => {
                        const address = savedAddresses.find((saved) => saved.loai_dia_chi === event.target.value)
                        if (address) {
                          setAddressLabel(address.loai_dia_chi)
                          setCheckout({
                            ...checkout,
                            recipient_name: address.ten_nguoi_nhan ?? '',
                            phone: address.so_dien_thoai ?? '',
                            address: address.chi_tiet_dia_chi,
                            ward: address.phuong_xa ?? '',
                            district: address.quan_huyen ?? '',
                            city: address.thanh_pho ?? '',
                          })
                        }
                      }}>
                        <option value="">{t('Chọn để điền thông tin')}</option>
                        {savedAddresses.map((address) => <option key={address.loai_dia_chi} value={address.loai_dia_chi}>{address.loai_dia_chi} · {address.chi_tiet_dia_chi}</option>)}
                      </select>
                    </label>
                  )}
                  <label>{t('Người nhận')}<input required maxLength={200} autoComplete="name" value={checkout.recipient_name} onChange={(event) => setCheckout({ ...checkout, recipient_name: event.target.value })} /></label>
                  <label>{t('Số điện thoại')}<input required type="tel" maxLength={20} autoComplete="tel" value={checkout.phone} onChange={(event) => setCheckout({ ...checkout, phone: event.target.value })} /></label>
                  <label className="checkout-wide">{t('Địa chỉ')}<input required maxLength={255} autoComplete="street-address" value={checkout.address} onChange={(event) => setCheckout({ ...checkout, address: event.target.value })} /></label>
                  <label>{t('Phường / xã')}<input maxLength={100} value={checkout.ward} onChange={(event) => setCheckout({ ...checkout, ward: event.target.value })} /></label>
                  <label>{t('Quận / huyện')}<input required maxLength={100} value={checkout.district} onChange={(event) => setCheckout({ ...checkout, district: event.target.value })} /></label>
                  <label className="checkout-wide">{t('Tỉnh / thành phố')}<input required maxLength={100} autoComplete="address-level1" value={checkout.city} onChange={(event) => setCheckout({ ...checkout, city: event.target.value })} /></label>
                  <div className="checkout-wide address-save-row">
                    <label>{t('Tên địa chỉ')}<input maxLength={20} value={addressLabel} onChange={(event) => setAddressLabel(event.target.value)} /></label>
                    <button type="button" onClick={() => void saveAddress()}>{t('Lưu địa chỉ')}</button>
                    {savedAddresses.some((address) => address.loai_dia_chi === addressLabel) && <button type="button" onClick={() => void deleteAddress(addressLabel)}>{t('Xóa địa chỉ đã lưu')}</button>}
                    {addressMessage && <span role="status">{addressMessage}</span>}
                  </div>
                  <div className="checkout-wide voucher-row">
                    <label>{t('Mã đã thu thập')}<select value={voucherCode} onChange={(event) => { setVoucherCode(event.target.value); setAppliedVoucher(null); setVoucherError('') }}>
                      <option value="">{t('Không chọn mã')}</option>
                      {customerVouchers.filter((voucher) => voucher.claimed).map((voucher) => <option key={voucher.code} value={voucher.code}>{voucher.code} · {voucher.discount_type === 'percent' ? `${voucher.discount_value}%` : money(voucher.discount_value)}</option>)}
                    </select></label>
                    <label>{t('Hoặc nhập mã')}<input value={voucherCode} onChange={(event) => { setVoucherCode(event.target.value); setAppliedVoucher(null); setVoucherError('') }} /></label>
                    <button type="button" disabled={voucherBusy || !voucherCode.trim()} onClick={() => void applyVoucher()}>{voucherBusy ? t('Đang kiểm tra…') : appliedVoucher ? t('Đã áp dụng') : t('Áp dụng mã')}</button>
                    {voucherError && <span className="commerce-error" role="alert">{voucherError}</span>}
                  </div>
                  <fieldset className="payment-options checkout-wide">
                    <legend>{t('Phương thức thanh toán')}</legend>
                    <label><input type="radio" name="payment_method" value="transfer" checked={checkout.payment_method === 'transfer'} onChange={() => setCheckout({ ...checkout, payment_method: 'transfer' })} /> {t('Chuyển khoản Agribank qua QR')}</label>
                    <label><input type="radio" name="payment_method" value="momo" checked={checkout.payment_method === 'momo'} onChange={() => setCheckout({ ...checkout, payment_method: 'momo' })} /> {t('MoMo qua mã QR')}</label>
                    <label><input type="radio" name="payment_method" value="cash" checked={checkout.payment_method === 'cash'} onChange={() => setCheckout({ ...checkout, payment_method: 'cash' })} /> {t('Thanh toán khi nhận hàng')}</label>
                  </fieldset>
                  {checkout.payment_method === 'momo' || checkout.payment_method === 'transfer' ? (
                    <div className="momo-payment checkout-wide">
                      <img src={checkout.payment_method === 'momo' ? '/momo-qr.png' : '/agribank-qr.png'} alt={language === 'vi' ? checkout.payment_method === 'momo' ? 'Mã QR MoMo do cửa hàng cung cấp' : 'Mã QR chuyển khoản Agribank do cửa hàng cung cấp' : checkout.payment_method === 'momo' ? 'MoMo QR code provided by the store' : 'Agribank transfer QR code provided by the store'} />
                      <p>{language === 'vi' ? checkout.payment_method === 'momo' ? 'Quét mã QR bằng MoMo' : 'Quét mã QR bằng ứng dụng ngân hàng hỗ trợ VietQR/NAPAS' : checkout.payment_method === 'momo' ? 'Scan the QR code using MoMo' : 'Scan the QR code using a bank app that supports VietQR/NAPAS'} {language === 'vi' ? 'và chuyển đúng số tiền' : 'and transfer exactly'} <strong>{money(appliedVoucher?.total ?? cart.total)}</strong>. {language === 'vi' ? 'Nội dung chuyển khoản ghi mã đơn sau khi đặt hàng. Đây là mã QR tĩnh; cửa hàng cần kiểm tra giao dịch thực tế trước khi xác nhận thanh toán.' : 'Include your order number in the transfer note after placing the order. This is a static QR code; the store must verify the transaction before confirming payment.'}</p>
                    </div>
                  ) : (
                    <p className="payment-notice checkout-wide">{language === 'vi' ? 'Bạn thanh toán khi nhận hàng. Cửa hàng sẽ xác nhận đơn trước khi giao.' : 'Pay when your order arrives. The store will confirm your order before shipping.'}</p>
                  )}
                  {checkoutError && <p className="commerce-error checkout-wide" role="alert">{checkoutError}</p>}
                  <button className="primary-button checkout-wide" type="submit" disabled={checkoutBusy || cart.items.length === 0}>
                    {checkoutBusy ? t('Đang tạo đơn…') : t('Xác nhận đặt hàng')}
                  </button>
                </form>
              </>
            ) : (
              <div className="order-success">
                <span className="order-success-icon" aria-hidden="true">✓</span>
                <span className="eyebrow">{t('ĐẶT HÀNG THÀNH CÔNG')}</span>
                <h2 id="checkout-title">{t('Cảm ơn bạn đã mua hàng!')}</h2>
                <p>{t('Mã đơn hàng:')} <strong>{placedOrder.id}</strong></p>
                <p>{t('Tổng tiền hàng (chưa gồm phí giao):')} <strong>{money(placedOrder.total)}</strong></p>
                <p>{t('Cửa hàng sẽ liên hệ xác nhận phí giao, thời gian nhận dự kiến và trạng thái đơn hàng.')}</p>
                <p>{placedOrder.payment === 'momo'
                  ? language === 'vi' ? 'Quét mã QR MoMo bằng ứng dụng ví, thanh toán đúng số tiền và ghi mã đơn trong nội dung. Đơn vẫn chờ cửa hàng kiểm tra và xác nhận giao dịch.' : 'Scan the MoMo QR code in your wallet app, pay the exact amount, and include your order number. The store must verify the payment before confirming the transaction.'
                  : placedOrder.payment === 'transfer'
                    ? language === 'vi' ? 'Quét mã Agribank bằng ứng dụng ngân hàng, chuyển đúng số tiền và ghi mã đơn trong nội dung. Mã QR tĩnh không tự xác minh giao dịch; đơn vẫn chờ cửa hàng đối soát.' : 'Scan the Agribank QR code in your banking app, transfer the exact amount, and include your order number. The static QR code cannot verify the payment; the store must reconcile it.'
                    : language === 'vi' ? 'Bạn sẽ thanh toán khi nhận hàng. Cửa hàng sẽ liên hệ để xác nhận đơn.' : 'Pay when your order arrives. The store will contact you to confirm the order.'}</p>
                {placedOrder.payment === 'momo' && <img className="checkout-success-qr" src="/momo-qr.png" alt={language === 'vi' ? 'Mã QR MoMo thanh toán đơn hàng' : 'MoMo QR code for order payment'} />}
                {placedOrder.payment === 'transfer' && <img className="checkout-success-qr" src="/agribank-qr.png" alt={language === 'vi' ? 'Mã QR Agribank thanh toán đơn hàng' : 'Agribank QR code for order payment'} />}
                <button className="primary-button" type="button" onClick={() => { setCheckoutOpen(false); setPlacedOrder(null); onCloseCart() }}>{t('Tiếp tục mua sắm')}</button>
              </div>
            )}
          </section>
        </div>
      )}
    </>
  )
}

import { useCallback, useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import './App.css'
import { AppearanceSettings } from './AppearanceSettings'
import { apiRequest } from './commerce'

type StaffAccount = { id: string; name: string; email: string; role: string; branch_id: number | null }
type LoginResponse = { data: StaffAccount; token: string }
type StaffTab = 'overview' | 'orders' | 'inventory' | 'warranties' | 'support' | 'payroll'
type Page<T> = { data: T[]; meta?: { total: number } }
type StaffOrder = { ma_dh: string; ngay_dat: string; tong_tien: number; trang_thai: string; customer_email: string | null; nhan_vien_id: string | null }
type InventoryItem = { ma_sp: string; ten_sp: string; total_stock: number; branch_stock: number | null; allocated_stock: number; low_stock: number }
type InventoryChange = { id: number; ma_sp: string; ten_sp: string; ton_cu: number; ton_moi: number; tk_admin: string; created_at: string }
type Warranty = { ma_dh: string; serial_number: string; ten_sp: string; email: string; status: string; processing_status: string; processing_note: string | null }
type Conversation = { id: string; latest_message: string | null; status: string; assigned_admin_id: string | null; waiting_minutes: number }
type Message = { id: number; sender: string; message: string; created_at: string }
type Revenue = { scope: string; total_revenue?: number; branches?: { id_cua_hang: number; ten_cua_hang: string; total_revenue: number }[]; branch_revenue?: number; personal_kpi?: number }

const storedTokenKey = 'techstore-staff-token'
const storedAccountKey = 'techstore-staff-account'
const formatMoney = (value: number | string) => `${new Intl.NumberFormat('vi-VN').format(Number(value))} ₫`
const roleNames: Record<string, string> = { chu_doanh_nghiep: 'Chủ doanh nghiệp', chu_quan: 'Chủ quản', giam_doc_chi_nhanh: 'Giám đốc chi nhánh', nhan_vien: 'Nhân viên' }

function savedAccount(): StaffAccount | null {
  try {
    const account = JSON.parse(sessionStorage.getItem(storedAccountKey) ?? 'null') as StaffAccount | null
    return account && typeof account.id === 'string' && typeof account.role === 'string' ? account : null
  } catch {
    return null
  }
}

export default function StaffApp() {
  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(storedTokenKey))
  const [account, setAccount] = useState<StaffAccount | null>(savedAccount)
  const [tab, setTab] = useState<StaffTab>('overview')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [revenue, setRevenue] = useState<Revenue | null>(null)
  const [orders, setOrders] = useState<StaffOrder[]>([])
  const [inventory, setInventory] = useState<InventoryItem[]>([])
  const [inventoryHistory, setInventoryHistory] = useState<InventoryChange[]>([])
  const [warranties, setWarranties] = useState<Warranty[]>([])
  const [conversations, setConversations] = useState<Conversation[]>([])
  const [selectedConversation, setSelectedConversation] = useState<{ id: string; messages: Message[] } | null>(null)
  const [reply, setReply] = useState('')
  const [stockValues, setStockValues] = useState<Record<string, string>>({})
  const [payroll, setPayroll] = useState<Record<string, unknown>[]>([])

  const load = useCallback(async (nextTab: StaffTab, accessToken: string) => {
    setBusy(true)
    setError('')
    try {
      if (nextTab === 'overview') setRevenue(await apiRequest<Revenue>('/staff/revenue', accessToken))
      if (nextTab === 'orders') setOrders((await apiRequest<Page<StaffOrder>>('/staff/orders', accessToken)).data)
      if (nextTab === 'inventory') {
        const [stock, history] = await Promise.all([
          apiRequest<Page<InventoryItem>>('/staff/inventory?per_page=50', accessToken),
          apiRequest<Page<InventoryChange>>('/staff/inventory/history?per_page=20', accessToken),
        ])
        setInventory(stock.data)
        setInventoryHistory(history.data)
      }
      if (nextTab === 'warranties') setWarranties((await apiRequest<{ data: Warranty[] }>('/staff/warranties', accessToken)).data)
      if (nextTab === 'support') setConversations((await apiRequest<Page<Conversation>>('/staff/conversations', accessToken)).data)
      if (nextTab === 'payroll') setPayroll((await apiRequest<{ data: Record<string, unknown>[] }>('/staff/payroll', accessToken)).data)
    } catch (reason) {
      const message = reason instanceof Error ? reason.message : 'Không tải được dữ liệu nghiệp vụ.'
      setError(message)
      if (message === 'Unauthenticated.') {
        sessionStorage.removeItem(storedTokenKey)
        sessionStorage.removeItem(storedAccountKey)
        setToken(null)
        setAccount(null)
      }
    } finally {
      setBusy(false)
    }
  }, [])

  useEffect(() => {
    if (!token || !account) return
    const timer = window.setTimeout(() => void load(tab, token), 0)
    return () => window.clearTimeout(timer)
  }, [tab, token, account, load])

  function logout() {
    if (token) void apiRequest('/staff/auth/logout', token, { method: 'POST' }).catch(() => {})
    sessionStorage.removeItem(storedTokenKey)
    sessionStorage.removeItem(storedAccountKey)
    setToken(null)
    setAccount(null)
  }

  async function login(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    try {
      const result = await apiRequest<LoginResponse>('/auth/staff/login', null, {
        method: 'POST',
        body: JSON.stringify({ identifier: String(form.get('identifier') ?? '').trim(), password: String(form.get('password') ?? '') }),
      })
      sessionStorage.setItem(storedTokenKey, result.token)
      sessionStorage.setItem(storedAccountKey, JSON.stringify(result.data))
      setToken(result.token)
      setAccount(result.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể đăng nhập.')
    } finally {
      setBusy(false)
    }
  }

  async function claimOrder(orderId: string) {
    if (!token) return
    try {
      await apiRequest(`/staff/orders/${encodeURIComponent(orderId)}/claim`, token, { method: 'POST' })
      await load('orders', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể nhận đơn.')
    }
  }

  async function updateStock(item: InventoryItem) {
    if (!token) return
    try {
      await apiRequest(`/staff/products/${encodeURIComponent(item.ma_sp)}/stock`, token, {
        method: 'PATCH',
        body: JSON.stringify({ stock: Number(stockValues[item.ma_sp] ?? item.branch_stock ?? 0) }),
      })
      await load('inventory', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể cập nhật tồn kho.')
    }
  }

  async function updateWarranty(warranty: Warranty, status: string) {
    if (!token) return
    try {
      await apiRequest(`/staff/warranties/${encodeURIComponent(warranty.serial_number)}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ status }),
      })
      await load('warranties', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể cập nhật bảo hành.')
    }
  }

  async function openConversation(conversation: Conversation) {
    if (!token) return
    setError('')
    try {
      if (!conversation.assigned_admin_id) {
        await apiRequest(`/staff/conversations/${conversation.id}`, token, { method: 'PATCH', body: JSON.stringify({ action: 'claim' }) })
        await load('support', token)
      }
      const result = await apiRequest<{ data: { id: string; messages: Message[] } }>(`/staff/conversations/${conversation.id}`, token)
      setSelectedConversation(result.data)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không mở được hội thoại.')
    }
  }

  async function submitReply(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token || !selectedConversation || !reply.trim()) return
    try {
      await apiRequest(`/staff/conversations/${selectedConversation.id}`, token, {
        method: 'PATCH',
        body: JSON.stringify({ action: 'reply', message: reply.trim() }),
      })
      setReply('')
      await openConversation({ id: selectedConversation.id, status: 'waiting_admin', assigned_admin_id: account?.id ?? null, latest_message: null, waiting_minutes: 0 })
      await load('support', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không gửi được phản hồi.')
    }
  }

  async function resolveConversation() {
    if (!token || !selectedConversation) return
    try {
      await apiRequest(`/staff/conversations/${selectedConversation.id}`, token, { method: 'PATCH', body: JSON.stringify({ action: 'resolve' }) })
      setSelectedConversation(null)
      await load('support', token)
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : 'Không thể kết thúc hội thoại.')
    }
  }

  if (!token || !account) {
    return <main className="admin-login-page"><AppearanceSettings /><form className="admin-login-card" onSubmit={login}>
      <a className="brand-mark" href="/"><img src="/techstore-mark.svg" alt="" /><span>TECH<span>STORE</span></span></a>
      <span className="eyebrow">CỔNG NGHIỆP VỤ NỘI BỘ</span><h1>Đăng nhập nhân viên</h1>
      <label>Tài khoản hoặc email<input name="identifier" required autoComplete="username" /></label>
      <label>Mật khẩu<input name="password" type="password" required autoComplete="current-password" /></label>
      {error && <p className="commerce-error" role="alert">{error}</p>}
      <button className="primary-button" type="submit" disabled={busy}>{busy ? 'Đang đăng nhập…' : 'Đăng nhập'}</button>
      <a className="admin-home-link" href="/">← Về trang cửa hàng</a>
    </form></main>
  }

  const tabs: [StaffTab, string, boolean][] = [
    ['overview', 'Tổng quan', true],
    ['orders', 'Đơn hàng', account.role === 'nhan_vien' || account.role === 'giam_doc_chi_nhanh'],
    ['inventory', 'Tồn kho', account.role === 'nhan_vien' || account.role === 'giam_doc_chi_nhanh'],
    ['warranties', 'Bảo hành', account.role === 'giam_doc_chi_nhanh'],
    ['support', 'Hỗ trợ khách hàng', true],
    ['payroll', 'Lương cá nhân', account.role === 'nhan_vien'],
  ]
  const activeTabs = tabs.filter(([, , visible]) => visible)

  return <main className="admin-app staff-app"><AppearanceSettings />
    <header className="admin-header"><a className="brand-mark" href="/"><img src="/techstore-mark.svg" alt="" /><span>TECH<span>STORE</span></span></a><div><span className="eyebrow">{account.name} · {roleNames[account.role] ?? account.role}</span><button type="button" onClick={logout}>Đăng xuất</button></div></header>
    <div className="admin-layout"><nav className="admin-sidebar" aria-label="Nghiệp vụ nhân viên"><h1>Cổng nhân viên</h1>{activeTabs.map(([key, label]) => <button key={key} type="button" className={tab === key ? 'admin-tab active' : 'admin-tab'} onClick={() => setTab(key)}>{label}</button>)}<a href="/">← Xem cửa hàng</a></nav>
      <section className="admin-content"><div className="admin-page-heading"><div><span className="eyebrow">KHU VỰC NHÂN VIÊN</span><h2>{activeTabs.find(([key]) => key === tab)?.[1]}</h2></div><button type="button" onClick={() => token && void load(tab, token)}>Tải lại</button></div>
        {error && <p className="commerce-error admin-alert" role="alert">{error}</p>}
        {busy && <p className="detail-status">Đang tải dữ liệu…</p>}
        {tab === 'overview' && revenue && <div className="staff-metrics">
          {revenue.total_revenue !== undefined && <article><span>Doanh thu toàn hệ thống</span><strong>{formatMoney(revenue.total_revenue)}</strong></article>}
          {revenue.branch_revenue !== undefined && <article><span>Doanh thu chi nhánh</span><strong>{formatMoney(revenue.branch_revenue)}</strong></article>}
          {revenue.personal_kpi !== undefined && <article><span>KPI doanh thu cá nhân tháng này</span><strong>{formatMoney(revenue.personal_kpi)}</strong></article>}
          {revenue.branches?.map((branch) => <article key={branch.id_cua_hang}><span>{branch.ten_cua_hang}</span><strong>{formatMoney(branch.total_revenue)}</strong></article>)}
        </div>}
        {tab === 'orders' && <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Mã đơn</th><th>Thời gian</th><th>Khách hàng</th><th>Tổng tiền</th><th>Trạng thái</th><th></th></tr></thead><tbody>{orders.map((order) => <tr key={order.ma_dh}><td>{order.ma_dh}</td><td>{order.ngay_dat}</td><td>{order.customer_email || '—'}</td><td>{formatMoney(order.tong_tien)}</td><td>{order.trang_thai}</td><td>{account.role === 'nhan_vien' && !order.nhan_vien_id && <button type="button" onClick={() => void claimOrder(order.ma_dh)}>Nhận xử lý</button>}</td></tr>)}{!busy && orders.length === 0 && <tr><td colSpan={6}>Không có đơn hàng chờ xử lý.</td></tr>}</tbody></table></div>}
        {tab === 'inventory' && <><p className="admin-training-note">Cảnh báo sắp hết hàng áp dụng khi tồn kho chi nhánh ≤ 5 sản phẩm. Đối chiếu chênh lệch giữa tổng tồn kho và số lượng đã phân bổ theo chi nhánh.</p><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Tồn chi nhánh</th><th>Tồn toàn hệ thống</th><th>Chênh lệch tổng / phân bổ</th><th>Cập nhật</th></tr></thead><tbody>{inventory.map((item) => <tr key={item.ma_sp}><td><strong>{item.ten_sp}</strong><small>{item.ma_sp}{item.low_stock ? ' · SẮP HẾT' : ''}</small></td><td>{item.branch_stock ?? 0}</td><td>{item.total_stock}</td><td>{Number(item.total_stock) - Number(item.allocated_stock)}</td><td><input aria-label={`Tồn kho ${item.ma_sp}`} type="number" min="0" max="1000000" value={stockValues[item.ma_sp] ?? item.branch_stock ?? 0} onChange={(event) => setStockValues({ ...stockValues, [item.ma_sp]: event.target.value })} /><button type="button" onClick={() => void updateStock(item)}>Lưu</button></td></tr>)}{!busy && inventory.length === 0 && <tr><td colSpan={5}>Không có dữ liệu tồn kho.</td></tr>}</tbody></table></div><h3>Lịch sử thay đổi tồn kho</h3><div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm</th><th>Thay đổi</th><th>Nhân viên</th><th>Thời gian</th></tr></thead><tbody>{inventoryHistory.map((entry) => <tr key={entry.id}><td>{entry.ten_sp} ({entry.ma_sp})</td><td>{entry.ton_cu} → {entry.ton_moi}</td><td>{entry.tk_admin}</td><td>{entry.created_at}</td></tr>)}{inventoryHistory.length === 0 && <tr><td colSpan={4}>Chưa có lịch sử điều chỉnh.</td></tr>}</tbody></table></div></>}
        {tab === 'warranties' && <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Sản phẩm / serial</th><th>Đơn hàng</th><th>Khách hàng</th><th>Trạng thái xử lý</th><th>Cập nhật</th></tr></thead><tbody>{warranties.map((warranty) => <tr key={warranty.serial_number}><td>{warranty.ten_sp}<small>{warranty.serial_number}</small></td><td>{warranty.ma_dh}</td><td>{warranty.email}</td><td>{warranty.processing_status || warranty.status}</td><td><select aria-label={`Trạng thái bảo hành ${warranty.serial_number}`} value={warranty.processing_status || 'active'} onChange={(event) => void updateWarranty(warranty, event.target.value)}><option value="active">Đang tiếp nhận</option><option value="in_repair">Đang sửa chữa</option><option value="completed">Hoàn tất</option><option value="rejected">Từ chối</option></select></td></tr>)}{warranties.length === 0 && <tr><td colSpan={5}>Chưa có bảo hành thuộc chi nhánh.</td></tr>}</tbody></table></div>}
        {tab === 'support' && <div className="admin-chat-layout"><aside className="admin-chat-list"><div className="admin-chat-list-heading"><strong>Hội thoại được giao</strong><button type="button" onClick={() => token && void load('support', token)}>Tải lại</button></div>{conversations.map((conversation) => <button className="admin-chat-list-item" type="button" key={conversation.id} onClick={() => void openConversation(conversation)}><strong>{conversation.latest_message || 'Chưa có tin nhắn'}</strong><small>{conversation.id.slice(0, 8)} · Chờ {conversation.waiting_minutes} phút{conversation.assigned_admin_id ? '' : ' · Chưa nhận'}</small></button>)}{conversations.length === 0 && <p className="admin-chat-empty">Không có hội thoại đang chờ.</p>}</aside><section className="admin-chat-detail">{selectedConversation ? <><header><strong>Hội thoại {selectedConversation.id.slice(0, 8)}</strong><button type="button" onClick={() => void resolveConversation()}>Kết thúc</button></header><div className="admin-chat-messages">{selectedConversation.messages.map((message) => <article className={`admin-chat-message ${message.sender}`} key={message.id}><span>{message.sender === 'visitor' ? 'Khách hàng' : message.sender === 'admin' ? 'Nhân viên' : 'Bot'}</span><p>{message.message}</p><time>{message.created_at}</time></article>)}</div><form className="admin-chat-reply" onSubmit={submitReply}><textarea required maxLength={2000} value={reply} onChange={(event) => setReply(event.target.value)} placeholder="Nhập phản hồi cho khách hàng…" /><button className="primary-button" type="submit">Gửi phản hồi</button></form></> : <p className="admin-chat-empty">Chọn hội thoại để nhận xử lý và trả lời khách.</p>}</section></div>}
        {tab === 'payroll' && <div className="admin-table-wrap"><table className="admin-table"><thead><tr><th>Tháng</th><th>Lương cơ bản</th><th>Phụ cấp</th><th>Thưởng</th><th>Khấu trừ</th><th>Trạng thái</th></tr></thead><tbody>{payroll.map((row, index) => <tr key={index}><td>{String(row.thang)}/{String(row.nam)}</td><td>{formatMoney(String(row.luong_co_ban ?? 0))}</td><td>{formatMoney(String(row.phu_cap ?? 0))}</td><td>{formatMoney(String(row.thuong ?? 0))}</td><td>{formatMoney(String(row.khau_tru ?? 0))}</td><td>{String(row.trang_thai)}</td></tr>)}{payroll.length === 0 && <tr><td colSpan={6}>Chưa có dữ liệu lương.</td></tr>}</tbody></table></div>}
      </section>
    </div>
  </main>
}

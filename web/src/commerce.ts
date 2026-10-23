export type Customer = { id: string; name: string; email: string; phone?: string | null }

export type CartItem = {
  product_id: string
  slug: string
  name: string
  price: number
  stock: number
  quantity: number
  line_total: number
}

export type CartData = { items: CartItem[]; total: number }

type ApiError = { message?: string; errors?: Record<string, string[]> }

export async function apiRequest<T>(
  path: string,
  token?: string | null,
  options: RequestInit = {},
): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')
  if (options.body && !(options.body instanceof FormData)) headers.set('Content-Type', 'application/json')
  if (token) headers.set('Authorization', `Bearer ${token}`)

  let response: Response
  try {
    response = await fetch(`${(import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '')}${path}`, {
      ...options,
      headers,
    })
  } catch (reason) {
    if (reason instanceof TypeError) {
      throw new Error('Không thể kết nối máy chủ. Vui lòng kiểm tra Laravel API rồi thử lại.')
    }
    throw reason
  }

  const result = await response.json().catch(() => ({})) as ApiError & Record<string, unknown>
  if (!response.ok) {
    const validationMessage = result.errors ? Object.values(result.errors).flat()[0] : null
    throw new Error(validationMessage ?? result.message ?? `Yêu cầu thất bại (HTTP ${response.status}).`)
  }

  return result as T
}

export async function apiDownload(path: string, token: string): Promise<{ blob: Blob; filename: string }> {
  let response: Response
  try {
    response = await fetch(`${(import.meta.env.VITE_API_URL ?? 'http://127.0.0.1:8000/api/v1').replace(/\/$/, '')}${path}`, {
      headers: { Accept: 'application/octet-stream', Authorization: `Bearer ${token}` },
    })
  } catch (reason) {
    if (reason instanceof TypeError) {
      throw new Error('Không thể kết nối máy chủ. Vui lòng kiểm tra Laravel API rồi thử lại.')
    }
    throw reason
  }

  if (!response.ok) {
    const result = await response.json().catch(() => ({})) as ApiError
    const validationMessage = result.errors ? Object.values(result.errors).flat()[0] : null
    throw new Error(validationMessage ?? result.message ?? `Yêu cầu thất bại (HTTP ${response.status}).`)
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const filename = disposition.match(/filename="?([^";]+)"?/i)?.[1] ?? 'minh-chung-uu-dai'

  return { blob: await response.blob(), filename }
}

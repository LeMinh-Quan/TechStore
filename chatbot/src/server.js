import express from 'express'
import { createHmac, randomUUID, timingSafeEqual } from 'node:crypto'
import { fileURLToPath } from 'node:url'

const searchStopWords = new Set([
  'bao', 'cho', 'co', 'có', 'giá', 'giup', 'giúp', 'hay', 'la', 'là',
  'minh', 'mình', 'mua', 'nao', 'nào', 'nhieu', 'nhiều', 'pham', 'phẩm',
  'san', 'sản', 'tim', 'tìm', 'toi', 'tôi', 'tu', 'tư', 'van', 'vấn',
  've', 'về', 'voi', 'với', 'xem', 'ý', 'nhe', 'nhé',
])

function normalizedIntent(message) {
  return message.toLocaleLowerCase('vi')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .replace(/đ/g, 'd')
}

const productCategories = [
  { pattern: /\b(may tinh bang|tablet|ipad)\b/, category: 'Máy tính bảng' },
  { pattern: /\b(dien thoai|smartphone|iphone|android)\b/, category: 'Điện thoại' },
  { pattern: /\b(laptop|may tinh xach tay|may tinh|macbook)\b/, category: 'Laptop' },
  { pattern: /\b(man hinh|monitor)\b/, category: 'Màn hình' },
  { pattern: /\b(tai nghe|loa|am thanh)\b/, category: 'Âm thanh' },
  { pattern: /\b(chuot|ban phim|keyboard)\b/, category: 'Chuột / Bàn phím' },
  { pattern: /\b(dong ho|smartwatch)\b/, category: 'Đồng hồ' },
]

function extractBudget(message) {
  const text = normalizedIntent(message)
  const unitAmounts = [...text.matchAll(/(\d+(?:[.,]\d+)?)\s*(trieu|tr|million|m)\b/g)]
    .map((match) => Number(match[1].replace(',', '.')) * 1_000_000)
    .filter(Number.isFinite)
  const currencyAmounts = [...text.matchAll(/(\d{1,3}(?:[.,]\d{3})+|\d+)\s*(?:vnd|d|₫)/g)]
    .map((match) => Number(match[1].replace(/[.,]/g, '')))
    .filter(Number.isFinite)
  const amounts = [...unitAmounts, ...currencyAmounts].sort((left, right) => left - right)
  if (amounts.length === 0) return null

  const isRange = /(tu|khoang).{0,12}(den|toi)\s*\d/.test(text) && amounts.length > 1
  const maximumOnly = /(duoi|toi da|khong qua|tam|khoang|trong tam|ngan sach toi da)/.test(text)
  const minimumOnly = /(tren|toi thieu|tu muc)/.test(text)
  if (isRange) return { min: amounts[0], max: amounts.at(-1) }
  if (minimumOnly && !maximumOnly) return { min: amounts[0], max: null }
  return { min: null, max: amounts.at(-1) }
}

function extractEntities(message) {
  const text = normalizedIntent(message)
  const budget = extractBudget(message)
  const useCase = [
    { pattern: /\b(game|gaming|choi game)\b/, label: 'chơi game' },
    { pattern: /\b(do hoa|thiet ke|dung video|render)\b/, label: 'đồ họa / thiết kế' },
    { pattern: /\b(lap trinh|coding|code)\b/, label: 'lập trình' },
    { pattern: /\b(hoc tap|sinh vien|di hoc)\b/, label: 'học tập' },
    { pattern: /\b(van phong|lam viec|office)\b/, label: 'văn phòng' },
  ].find(({ pattern }) => pattern.test(text))?.label ?? null
  const gaming = useCase === 'chơi game' || /\b(card roi|card do hoa|vga roi|gpu roi|co card)\b/.test(text)
  const category = productCategories.find(({ pattern }) => pattern.test(text))?.category
    ?? (gaming ? 'Laptop' : null)

  return { category, budget, useCase: gaming ? 'chơi game' : useCase }
}

function classifyIntent(message) {
  const text = normalizedIntent(message)
  const entities = extractEntities(message)

  if (/(nhan vien|tu van vien|ho tro truc tiep|gap admin|noi chuyen voi nguoi)/.test(text)) {
    return { type: 'human', label: 'Chuyển nhân viên', entities }
  }
  if (/\b(chao|xin chao|hello|hi)\b/.test(text)) {
    return { type: 'greeting', label: 'Chào hỏi', entities }
  }
  if (/(thanh toan|chuyen khoan|tra tien)/.test(text)) {
    return { type: 'payment', label: 'Thanh toán', entities }
  }
  if (/(dat hang|mua hang|checkout)/.test(text) && !entities.category) {
    return { type: 'checkout', label: 'Đặt hàng', entities }
  }
  if (/(don hang|lich su mua|lich su don|huy don)/.test(text)) {
    return { type: 'order', label: 'Đơn hàng', entities }
  }
  if (/(bao hanh|doi tra|tra hang|loi san pham|bao tri)/.test(text)) {
    return { type: 'warranty', label: 'Bảo hành / đổi trả', entities }
  }
  if (/(giao hang|van chuyen|phi ship|nhan hang)/.test(text)) {
    return { type: 'delivery', label: 'Giao hàng', entities }
  }
  if (/(tu van|goi y|cau hinh|phu hop nhu cau|nen mua)/.test(text) || (entities.category && entities.useCase)) {
    return { type: 'configuration', label: 'Tư vấn cấu hình', entities }
  }
  if (entities.category || /(tim|san pham|co hang|gia bao nhieu|gia cua|mua)/.test(text)) {
    return { type: 'product', label: 'Tìm sản phẩm', entities }
  }

  return { type: 'unknown', label: 'Cần hỗ trợ thêm', entities }
}

function productSearchTerms(message, category = null) {
  if (category) return category

  const terms = normalizedIntent(message)
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .split(/\s+/)
    .filter((term) => term.length > 1 && !searchStopWords.has(term) && !/^\d+$/.test(term))

  return terms.join(' ')
}

function faqReply(intent) {
  switch (intent.type) {
    case 'greeting':
      return 'Xin chào! Mình có thể tư vấn cấu hình, tìm sản phẩm đang bán hoặc hỗ trợ đơn hàng.'
    case 'payment':
      return 'Chủ đề: thanh toán.\nBạn có thể chọn thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng lúc đặt hàng. Chuyển khoản trong phiên bản này chưa kết nối ngân hàng.'
    case 'checkout':
      return 'Chủ đề: đặt hàng.\nBạn chọn sản phẩm, thêm vào giỏ, đăng nhập rồi điền thông tin giao hàng để đặt hàng. Hiện cửa hàng hỗ trợ thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng.'
    case 'order':
      return 'Chủ đề: đơn hàng.\nĐăng nhập tài khoản, mở mục đơn hàng để xem trạng thái hoặc hủy đơn khi đơn vẫn đang chờ xác nhận.'
    case 'delivery':
      return 'Chủ đề: giao hàng.\nBạn có thể nhập địa chỉ giao hàng khi đặt đơn. Để kiểm tra phí hoặc tiến độ giao hàng cụ thể, hãy gửi mã đơn hoặc chọn “Gặp nhân viên”.'
    case 'warranty':
      return 'Chủ đề: bảo hành / đổi trả.\nThời hạn bảo hành tùy theo sản phẩm. Bạn hãy mở trang chi tiết sản phẩm để xem thông tin bảo hành hoặc chọn “Gặp nhân viên” để được kiểm tra cụ thể.'
    default:
      return null
  }
}

const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const conversationCapabilityLifetimeMs = 30 * 24 * 60 * 60 * 1000

function conversationCapability(conversationId, serviceToken) {
  const issuedAt = Date.now()
  const signature = createHmac('sha256', serviceToken)
    .update(`${conversationId}:${issuedAt}`)
    .digest('hex')

  return `${issuedAt}.${signature}`
}

function hasConversationCapability(request, conversationId, serviceToken) {
  const provided = request.get('authorization')?.match(/^Bearer (\d{13})\.([a-f0-9]{64})$/i)
  if (!provided) return false

  const issuedAt = Number(provided[1])
  const now = Date.now()
  if (issuedAt > now + 60_000 || now - issuedAt > conversationCapabilityLifetimeMs) return false

  const expected = createHmac('sha256', serviceToken)
    .update(`${conversationId}:${issuedAt}`)
    .digest()
  const providedBytes = Buffer.from(provided[2], 'hex')

  return providedBytes.length === expected.length && timingSafeEqual(providedBytes, expected)
}

export function createApp({
  apiBaseUrl = process.env.LARAVEL_API_URL,
  serviceToken = process.env.CHATBOT_SERVICE_TOKEN,
  chatRateLimit = 20,
  historyRateLimit = 60,
  rateLimitWindowMs = 60_000,
  allowedOrigins = (process.env.WEB_ORIGINS ?? 'http://localhost:5173,http://127.0.0.1:5173')
    .split(',')
    .map((origin) => origin.trim())
    .filter(Boolean),
  fetchImpl = fetch,
} = {}) {
  const app = express()
  const rateLimits = new Map()
  let rateLimitRequests = 0

  app.use((request, response, next) => {
    response.set('X-Content-Type-Options', 'nosniff')
    response.set('X-Frame-Options', 'DENY')
    response.set('Referrer-Policy', 'no-referrer')
    response.set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
    response.set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'")
    const origin = request.get('origin')
    if (origin && !allowedOrigins.includes(origin)) {
      return response.status(403).json({ message: 'Origin is not allowed.' })
    }
    if (origin) {
      response.set('Access-Control-Allow-Origin', origin)
      response.set('Vary', 'Origin')
    }
    response.set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
    response.set('Access-Control-Allow-Headers', 'Content-Type, Authorization')
    if (request.method === 'OPTIONS') return response.sendStatus(204)
    return next()
  })
  app.use(express.json({ limit: '10kb' }))

  function limitRequests(limit) {
    return (request, response, next) => {
      const now = Date.now()
      const key = `${request.path}:${request.ip}`
      let entry = rateLimits.get(key)

      if (!entry || entry.resetAt <= now) {
        entry = { count: 0, resetAt: now + rateLimitWindowMs }
        rateLimits.set(key, entry)
      }

      entry.count += 1
      rateLimitRequests += 1
      if (rateLimitRequests % 256 === 0 || rateLimits.size > 10_000) {
        for (const [entryKey, value] of rateLimits) {
          if (value.resetAt <= now) rateLimits.delete(entryKey)
        }
        while (rateLimits.size > 10_000) {
          rateLimits.delete(rateLimits.keys().next().value)
        }
      }

      if (entry.count > limit) {
        response.set('Retry-After', String(Math.max(1, Math.ceil((entry.resetAt - now) / 1000))))
        return response.status(429).json({ message: 'Bạn gửi yêu cầu quá nhanh. Vui lòng thử lại sau.' })
      }

      return next()
    }
  }

  app.get('/health', (_request, response) => response.json({ status: 'ok' }))

  async function internalRequest(path, { method = 'GET', body } = {}) {
    const url = new URL(`${apiBaseUrl.replace(/\/$/, '')}${path}`)
    const headers = { Authorization: `Bearer ${serviceToken}`, Accept: 'application/json' }
    if (body !== undefined) headers['Content-Type'] = 'application/json'

    const upstream = await fetchImpl(url, {
      method,
      headers,
      ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      signal: AbortSignal.timeout(5000),
    })
    const result = await upstream.json().catch(() => ({}))
    if (!upstream.ok) {
      const error = new Error(result.message || `Laravel API returned HTTP ${upstream.status}.`)
      error.status = upstream.status
      throw error
    }

    return result
  }

  async function trainedFaqReply(intent) {
    const fallback = faqReply(intent)
    if (!fallback) return null

    const result = await internalRequest(`/internal/chatbot/knowledge/${intent.type}`)
    return typeof result.data?.answer === 'string' && result.data.answer.trim()
      ? result.data.answer
      : fallback
  }

  async function matchTrainedQuestion(message) {
    const query = new URLSearchParams({ q: message })
    const result = await internalRequest(`/internal/chatbot/knowledge/match?${query}`)
    const match = result.data

    return match
      && typeof match.intent === 'string'
      && typeof match.answer === 'string'
      && Number(match.score) >= 0.55
      ? match
      : null
  }

  async function conversationHistory(conversationId) {
    const result = await internalRequest(`/internal/chatbot/conversations/${conversationId}`)
    return result.data
  }

  app.use('/api/chat/:conversationId', limitRequests(historyRateLimit))
  app.get('/api/chat/:conversationId', async (request, response) => {
    const { conversationId } = request.params
    if (!uuidPattern.test(conversationId)) {
      return response.status(422).json({ message: 'Mã hội thoại không hợp lệ.' })
    }
    if (!serviceToken) {
      return response.status(503).json({ message: 'Chatbot service is not configured.' })
    }
    if (!hasConversationCapability(request, conversationId, serviceToken)) {
      return response.status(401).json({ message: 'Không có quyền truy cập hội thoại này.' })
    }

    try {
      return response.json(await conversationHistory(conversationId))
    } catch (error) {
      if (error.status === 404) return response.status(404).json({ message: 'Không tìm thấy hội thoại.' })
      console.error('Chatbot could not load conversation history:', error)
      return response.status(502).json({ message: 'Chưa tải được lịch sử hội thoại.' })
    }
  })

  app.use('/api/chat', limitRequests(chatRateLimit))
  app.post('/api/chat', async (request, response) => {
    const message = typeof request.body?.message === 'string' ? request.body.message.trim() : ''
    if (message.length < 2 || message.length > 500) {
      return response.status(422).json({ reply: 'Tin nhắn cần có từ 2 đến 500 ký tự.' })
    }
    let conversationId = request.body?.conversationId
    if (conversationId !== undefined && (typeof conversationId !== 'string' || !uuidPattern.test(conversationId))) {
      return response.status(422).json({ reply: 'Mã hội thoại không hợp lệ.' })
    }
    const hasCapability = typeof serviceToken === 'string'
      && serviceToken !== ''
      && conversationId !== undefined
      && hasConversationCapability(request, conversationId, serviceToken)
    if (conversationId !== undefined && !hasCapability) {
      return response.status(401).json({ reply: 'Phiên trò chuyện không hợp lệ. Hãy bắt đầu cuộc trò chuyện mới.' })
    }
    if (conversationId === undefined && request.get('authorization')) {
      return response.status(422).json({ reply: 'Mã hội thoại không hợp lệ.' })
    }
    conversationId ??= randomUUID()
    if (!apiBaseUrl || !serviceToken) {
      console.error('Chatbot integration is missing LARAVEL_API_URL or CHATBOT_SERVICE_TOKEN.')
      return response.status(503).json({ reply: 'Chatbot chưa được cấu hình kết nối tới cửa hàng.' })
    }

    try {
      const existing = await internalRequest('/internal/chatbot/conversations', {
        method: 'POST',
        body: { conversation_id: conversationId },
      })
      if (existing.data.status === 'closed') {
        conversationId = randomUUID()
        await internalRequest('/internal/chatbot/conversations', {
          method: 'POST',
          body: { conversation_id: conversationId },
        })
      }

      const received = await internalRequest(`/internal/chatbot/conversations/${conversationId}/messages`, {
        method: 'POST',
        body: { sender: 'visitor', message },
      })
      if (received.data.status === 'waiting_admin') {
        return response.json({
          conversationId,
          sessionToken: conversationCapability(conversationId, serviceToken),
          status: 'waiting_admin',
          reply: null,
          conversation: await conversationHistory(conversationId),
        })
      }

      const history = await conversationHistory(conversationId)
      const messages = Array.isArray(history.messages) ? history.messages : []
      const lastBotIndex = messages.findLastIndex(({ sender }) => sender === 'bot')
      const lastBotPrompt = lastBotIndex >= 0 ? normalizedIntent(messages[lastBotIndex].message) : ''
      const previousVisitor = lastBotIndex >= 0
        ? messages.slice(0, lastBotIndex).findLast(({ sender }) => sender === 'visitor')
        : null
      const continuesConfiguration = previousVisitor
        && /(ngan sach|loai thiet bi|laptop|dien thoai|may tinh bang)/.test(lastBotPrompt)
        && classifyIntent(previousVisitor.message).type === 'configuration'
      const question = continuesConfiguration ? `${previousVisitor.message} ${message}` : message
      let intent = classifyIntent(question)
      const humanRequested = intent.type === 'human'
      let reply = null
      if (!humanRequested && ['unknown', 'greeting', 'payment', 'checkout', 'order', 'delivery', 'warranty'].includes(intent.type)) {
        const trainedMatch = await matchTrainedQuestion(question)
        if (trainedMatch && (intent.type === 'unknown' || intent.type === trainedMatch.intent)) {
          if (intent.type === 'unknown') {
            intent = { ...intent, type: trainedMatch.intent, label: trainedMatch.intent }
          }
          reply = trainedMatch.answer
        }
      }
      if (!reply && !humanRequested) reply = await trainedFaqReply(intent)
      let products = []
      if (!reply && intent.type === 'configuration' && !intent.entities.category) {
        reply = 'Mình sẽ tách nhu cầu thành loại thiết bị, ngân sách và mục đích sử dụng. Bạn đang tìm laptop, điện thoại hay máy tính bảng? Ví dụ: “laptop khoảng 20 triệu để học tập”.'
      }
      if (!reply && intent.type === 'product' && !productSearchTerms(question, intent.entities.category)) {
        reply = 'Bạn muốn tìm loại sản phẩm hoặc mẫu nào? Hãy gửi tên sản phẩm, danh mục hay ngân sách để mình lọc kết quả đang bán.'
      }
      if (!reply && !humanRequested && ['configuration', 'product'].includes(intent.type)) {
        const upstreamUrl = new URL(`${apiBaseUrl.replace(/\/$/, '')}/internal/chatbot/products`)
        upstreamUrl.searchParams.set('q', productSearchTerms(question, intent.entities.category))
        if (intent.entities.category) upstreamUrl.searchParams.set('category', intent.entities.category)
        if (intent.entities.useCase === 'chơi game') upstreamUrl.searchParams.set('gaming', '1')
        if (intent.entities.budget?.min !== null && intent.entities.budget?.min !== undefined) {
          upstreamUrl.searchParams.set('min_price', String(intent.entities.budget.min))
        }
        if (intent.entities.budget?.max !== null && intent.entities.budget?.max !== undefined) {
          upstreamUrl.searchParams.set('max_price', String(intent.entities.budget.max))
        }
        const upstream = await fetchImpl(upstreamUrl, {
          headers: { Authorization: `Bearer ${serviceToken}`, Accept: 'application/json' },
          signal: AbortSignal.timeout(5000),
        })
        if (!upstream.ok) {
          throw new Error(`Laravel chatbot product search returned HTTP ${upstream.status}.`)
        }

        const result = await upstream.json()
        products = Array.isArray(result.data) ? result.data.slice(0, 3) : []
        if (products.length > 0) {
          const { category, budget, useCase } = intent.entities
          const criteria = [
            category,
            budget?.max ? `tối đa ${new Intl.NumberFormat('vi-VN').format(budget.max)} ₫` : null,
            budget?.min ? `từ ${new Intl.NumberFormat('vi-VN').format(budget.min)} ₫` : null,
            useCase,
          ].filter(Boolean).join(' · ')
          reply = `Mình đã phân loại yêu cầu${criteria ? `: ${criteria}` : ''}.\nMình tìm thấy ${products.length} sản phẩm đang bán:\n${products.map(({ name, price }) =>
            `• ${name} — ${new Intl.NumberFormat('vi-VN').format(price)} ₫`,
          ).join('\n')}`
        } else if (intent.type === 'configuration' && intent.entities.useCase === 'chơi game') {
          reply = 'Mình chưa tìm thấy laptop gaming hoặc laptop có card đồ họa rời đang bán khớp tiêu chí. Bạn có thể nới ngân sách hoặc chọn “Gặp nhân viên” để được tư vấn thêm.'
        } else if (intent.type === 'configuration') {
          const { category, budget, useCase } = intent.entities
          reply = `Mình đã nhận nhu cầu${category ? ` ${category}` : ''}${useCase ? ` để ${useCase}` : ''}${budget?.max ? `, ngân sách tối đa ${new Intl.NumberFormat('vi-VN').format(budget.max)} ₫` : ''}, nhưng chưa tìm thấy sản phẩm đang bán khớp tiêu chí. Bạn có thể nới ngân sách hoặc chọn “Gặp nhân viên” để được tư vấn thêm.`
        } else {
          reply = 'Mình chưa tìm thấy sản phẩm đang bán khớp từ khóa đó. Bạn thử gửi tên sản phẩm hoặc chọn danh mục cần tìm nhé.'
        }
      }

      if (!reply) {
        reply = humanRequested
          ? 'Mình đã chuyển cuộc trò chuyện cho nhân viên TechStore. Bạn có thể để lại câu hỏi và thông tin liên hệ tại đây.'
          : 'Mình chưa xác định được chủ đề câu hỏi. Mình đã chuyển cuộc trò chuyện cho nhân viên TechStore; bạn có thể để lại thêm chi tiết tại đây.'
        await internalRequest(`/internal/chatbot/conversations/${conversationId}/handoff`, {
          method: 'POST',
          body: { message: reply },
        })
        return response.json({
          conversationId,
          sessionToken: conversationCapability(conversationId, serviceToken),
          status: 'waiting_admin',
          reply,
          intent: intent.type,
          topic: intent.label,
          entities: intent.entities,
          conversation: await conversationHistory(conversationId),
        })
      }

      await internalRequest(`/internal/chatbot/conversations/${conversationId}/messages`, {
        method: 'POST',
        body: { sender: 'bot', message: reply },
      })
      return response.json({
        conversationId,
        sessionToken: conversationCapability(conversationId, serviceToken),
        status: 'bot',
        reply,
        intent: intent.type,
        topic: intent.label,
        entities: intent.entities,
        products: products.map(({ id, name, price }) => ({ id, name, price })),
        conversation: await conversationHistory(conversationId),
      })
    } catch (error) {
      console.error('Chatbot could not complete the conversation request:', error)
      return response.status(502).json({ reply: 'Dịch vụ cửa hàng tạm thời không khả dụng. Vui lòng thử lại sau.' })
    }
  })

  app.use((error, _request, response, _next) => {
    if (error instanceof SyntaxError && 'body' in error) {
      return response.status(400).json({ message: 'Invalid JSON request body.' })
    }
    console.error('Unhandled chatbot request error:', error)
    return response.status(500).json({ message: 'Internal server error.' })
  })

  return app
}

const isMainModule = process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]
if (isMainModule) {
  const port = Number(process.env.PORT ?? 3001)
  const host = process.env.HOST ?? '127.0.0.1'
  createApp().listen(port, host, () => console.log(`TechStore chatbot listening on ${host}:${port}.`))
}

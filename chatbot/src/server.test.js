import assert from 'node:assert/strict'
import { after, before, test } from 'node:test'
import { createApp } from './server.js'

let server
let baseUrl
let lastProductSearch
let lastKnowledgeIntent
const conversations = new Map()
const trainedMatches = new Map()

before(async () => {
  const app = createApp({
    apiBaseUrl: 'http://laravel.test/api/v1',
    serviceToken: 'test-secret',
    chatRateLimit: 100,
    fetchImpl: async (url, options = {}) => {
      assert.equal(options.headers.Authorization, 'Bearer test-secret')
      if (url.pathname.endsWith('/internal/chatbot/conversations') && options.method === 'POST') {
        const { conversation_id: id } = JSON.parse(options.body)
        if (!conversations.has(id)) conversations.set(id, { id, status: 'bot', messages: [] })
        return Response.json({ data: conversations.get(id) })
      }

      const messageRoute = url.pathname.match(/\/internal\/chatbot\/conversations\/([^/]+)\/messages$/)
      if (messageRoute && options.method === 'POST') {
        const conversation = conversations.get(messageRoute[1])
        const message = JSON.parse(options.body)
        if (message.sender === 'bot' && conversation.status === 'waiting_admin') {
          return Response.json({ message: 'Conversation is waiting for admin.' }, { status: 422 })
        }
        conversation.messages.push({ id: conversation.messages.length + 1, sender: message.sender, message: message.message })
        return Response.json({ data: { status: conversation.status } }, { status: 201 })
      }

      const handoffRoute = url.pathname.match(/\/internal\/chatbot\/conversations\/([^/]+)\/handoff$/)
      if (handoffRoute && options.method === 'POST') {
        const conversation = conversations.get(handoffRoute[1])
        conversation.status = 'waiting_admin'
        const message = JSON.parse(options.body)
        conversation.messages.push({ id: conversation.messages.length + 1, sender: 'bot', message: message.message })
        return Response.json({ data: { status: conversation.status } })
      }

      if (url.pathname.endsWith('/internal/chatbot/knowledge/match')) {
        return Response.json({ data: trainedMatches.get(url.searchParams.get('q')) ?? null })
      }

      const knowledgeRoute = url.pathname.match(/\/internal\/chatbot\/knowledge\/([^/]+)$/)
      if (knowledgeRoute) {
        lastKnowledgeIntent = knowledgeRoute[1]
        const replies = {
          greeting: 'Xin chào! Mình có thể tư vấn cấu hình, tìm sản phẩm đang bán hoặc hỗ trợ đơn hàng.',
          payment: 'Chủ đề: thanh toán.\nBạn có thể chọn thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng lúc đặt hàng. Chuyển khoản trong phiên bản này chưa kết nối ngân hàng.',
          checkout: 'Chủ đề: đặt hàng.\nBạn chọn sản phẩm, thêm vào giỏ, đăng nhập rồi điền thông tin giao hàng để đặt hàng. Hiện cửa hàng hỗ trợ thanh toán khi nhận hàng hoặc chuyển khoản mô phỏng.',
          order: 'Chủ đề: đơn hàng.\nĐăng nhập tài khoản, mở mục đơn hàng để xem trạng thái hoặc hủy đơn khi đơn vẫn đang chờ xác nhận.',
          delivery: 'Chủ đề: giao hàng.\nBạn có thể nhập địa chỉ giao hàng khi đặt đơn. Để kiểm tra phí hoặc tiến độ giao hàng cụ thể, hãy gửi mã đơn hoặc chọn “Gặp nhân viên”.',
          warranty: 'Chủ đề: bảo hành / đổi trả.\nThời hạn bảo hành tùy theo sản phẩm. Bạn hãy mở trang chi tiết sản phẩm để xem thông tin bảo hành hoặc chọn “Gặp nhân viên” để được kiểm tra cụ thể.',
        }
        return Response.json({ data: { answer: replies[knowledgeRoute[1]] ?? null } })
      }

      const historyRoute = url.pathname.match(/\/internal\/chatbot\/conversations\/([^/]+)$/)
      if (historyRoute && options.method !== 'POST') {
        const conversation = conversations.get(historyRoute[1])
        return conversation
          ? Response.json({ data: conversation })
          : Response.json({ message: 'Not found.' }, { status: 404 })
      }

      if (url.pathname.endsWith('/internal/chatbot/products')) {
        lastProductSearch = new URL(url)
        const query = url.searchParams.get('q')
        if (query !== 'laptop' && query !== 'Laptop') return Response.json({ data: [] })
        if (url.searchParams.get('gaming') === '1') {
          return Response.json({
            data: [{ id: 'LAPTOP-GAMING01', name: 'Laptop gaming kiểm thử có GeForce RTX', price: 25000000 }],
          })
        }
        return Response.json({
          data: [{ id: 'LAPTOP01', name: 'Laptop kiểm thử', price: 12000000 }],
        })
      }
      throw new Error(`Unexpected Laravel API request: ${options.method ?? 'GET'} ${url}`)
    },
  })
  server = app.listen(0)
  await new Promise((resolve) => server.once('listening', resolve))
  baseUrl = `http://127.0.0.1:${server.address().port}`
})

after(async () => {
  await new Promise((resolve, reject) => server.close((error) => error ? reject(error) : resolve()))
})

async function send(message, session) {
  const response = await fetch(`${baseUrl}/api/chat`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(session ? { Authorization: `Bearer ${session.sessionToken}` } : {}),
    },
    body: JSON.stringify({ message, ...(session ? { conversationId: session.conversationId } : {}) }),
  })

  const result = await response.json()
  return {
    response,
    result,
    session: result.conversationId && result.sessionToken
      ? { conversationId: result.conversationId, sessionToken: result.sessionToken }
      : session,
  }
}

test('health endpoint responds without database access', async () => {
  const response = await fetch(`${baseUrl}/health`)
  assert.equal(response.status, 200)
  assert.equal(response.headers.get('x-content-type-options'), 'nosniff')
  assert.equal(response.headers.get('x-frame-options'), 'DENY')
  assert.deepEqual(await response.json(), { status: 'ok' })
})

test('chat persists user and bot messages alongside live product results', async () => {
  const { response, result, session } = await send('Laptop')
  assert.equal(response.status, 200)
  assert.equal(result.conversationId, session.conversationId)
  assert.match(result.sessionToken, /^\d{13}\.[a-f0-9]{64}$/)
  assert.equal(result.status, 'bot')
  assert.match(result.reply, /Laptop kiểm thử/)
  assert.deepEqual(result.products, [{ id: 'LAPTOP01', name: 'Laptop kiểm thử', price: 12000000 }])
  assert.deepEqual(result.conversation.messages.map(({ sender }) => sender), ['visitor', 'bot'])
})

test('conversation history requires its capability token', async () => {
  const { session } = await send('Laptop')
  const unauthorized = await fetch(`${baseUrl}/api/chat/${session.conversationId}`)
  assert.equal(unauthorized.status, 401)

  const authorized = await fetch(`${baseUrl}/api/chat/${session.conversationId}`, {
    headers: { Authorization: `Bearer ${session.sessionToken}` },
  })
  assert.equal(authorized.status, 200)
})

test('an untrusted caller cannot take over a known conversation ID', async () => {
  const { session } = await send('Laptop')
  const response = await fetch(`${baseUrl}/api/chat`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ message: 'Đọc hội thoại', conversationId: session.conversationId }),
  })
  assert.equal(response.status, 401)
})

test('chat strips conversational filler before searching products', async () => {
  const { response } = await send('Tìm giúp mình Laptop nhé?')
  assert.equal(response.status, 200)
})

test('configuration questions are classified and filtered by device, budget, and use case', async () => {
  const { response, result } = await send('Tư vấn laptop khoảng 20 triệu để học tập')
  assert.equal(response.status, 200)
  assert.equal(result.intent, 'configuration')
  assert.equal(result.topic, 'Tư vấn cấu hình')
  assert.deepEqual(result.entities, {
    category: 'Laptop',
    budget: { min: null, max: 20000000 },
    useCase: 'học tập',
  })
  assert.equal(lastProductSearch.searchParams.get('category'), 'Laptop')
  assert.equal(lastProductSearch.searchParams.get('max_price'), '20000000')
  assert.match(result.reply, /đã phân loại yêu cầu: Laptop · tối đa 20\.000\.000 ₫ · học tập/)
})

test('gaming requests only search laptops with a gaming line or discrete graphics', async () => {
  const { response, result } = await send('Tư vấn laptop chơi game khoảng 30 triệu')
  assert.equal(response.status, 200)
  assert.equal(result.intent, 'configuration')
  assert.equal(result.entities.category, 'Laptop')
  assert.equal(result.entities.useCase, 'chơi game')
  assert.equal(lastProductSearch.searchParams.get('category'), 'Laptop')
  assert.equal(lastProductSearch.searchParams.get('gaming'), '1')
  assert.match(result.reply, /Laptop gaming kiểm thử/)
})

test('configuration guidance collects device and budget in follow-up messages', async () => {
  const first = await send('Tư vấn cấu hình')
  assert.equal(first.result.intent, 'configuration')
  assert.match(first.result.reply, /đang tìm laptop, điện thoại hay máy tính bảng/i)

  const second = await send('Laptop khoảng 20 triệu để học tập', first.session)
  assert.equal(second.result.intent, 'configuration')
  assert.match(second.result.reply, /Laptop/)
})

test('generic product request asks for a useful search detail', async () => {
  const { result } = await send('Tìm sản phẩm')
  assert.equal(result.intent, 'product')
  assert.match(result.reply, /tên sản phẩm, danh mục hay ngân sách/)
})

test('known purchase and payment questions receive grounded FAQs', async () => {
  const { response, result } = await send('Thanh toán thế nào?')
  assert.equal(response.status, 200)
  assert.equal(lastKnowledgeIntent, 'payment')
  assert.match(result.reply, /Chủ đề: thanh toán/)
  assert.match(result.reply, /chuyển khoản mô phỏng/)
  assert.equal(result.conversation.status, 'bot')
  assert.equal(result.conversation.messages[1].sender, 'bot')
})

test('chat uses approved matching training examples for FAQ replies', async () => {
  trainedMatches.set('Thanh toán thế nào?', {
    intent: 'payment',
    answer: 'Cửa hàng đang hỗ trợ thanh toán khi nhận hàng.',
    score: 0.8,
  })

  const { response, result } = await send('Thanh toán thế nào?')
  assert.equal(response.status, 200)
  assert.equal(result.intent, 'payment')
  assert.equal(result.reply, 'Cửa hàng đang hỗ trợ thanh toán khi nhận hàng.')
})

test('chat ignores weak training matches', async () => {
  trainedMatches.set('Thanh toán thế nào?', {
    intent: 'payment',
    answer: 'Không nên dùng câu trả lời này.',
    score: 0.4,
  })

  const { result } = await send('Thanh toán thế nào?')
  assert.match(result.reply, /Chủ đề: thanh toán/)
})

test('purchase instructions remain available as a classified FAQ', async () => {
  const { result } = await send('Đặt hàng như thế nào?')
  assert.equal(result.intent, 'checkout')
  assert.match(result.reply, /thêm vào giỏ/)
})

test('directly requested human support is handed off to the admin inbox', async () => {
  const { result } = await send('Tôi muốn gặp nhân viên tư vấn')
  assert.equal(result.intent, 'human')
  assert.equal(result.status, 'waiting_admin')
  assert.match(result.reply, /đã chuyển cuộc trò chuyện cho nhân viên/i)
})

test('unknown questions are stored and handed off to the admin inbox', async () => {
  const { response, result } = await send('xyz')
  assert.equal(response.status, 200)
  assert.equal(result.status, 'waiting_admin')
  assert.equal(result.conversation.status, 'waiting_admin')
  assert.equal(result.conversation.messages.at(-1).sender, 'bot')
})

test('messages in an admin handoff remain in the same conversation without a bot answer', async () => {
  const first = await send('xyz')
  const { result } = await send('Tôi bổ sung thêm thông tin', first.session)
  assert.equal(result.reply, null)
  assert.equal(result.status, 'waiting_admin')
  assert.equal(result.conversation.messages.at(-1).sender, 'visitor')
})

test('chat rejects empty messages', async () => {
  const { response } = await send(' ')
  assert.equal(response.status, 422)
})

test('chat endpoint rate limits repeated requests', async () => {
  const app = createApp({
    apiBaseUrl: null,
    serviceToken: 'rate-test-secret',
    chatRateLimit: 1,
  })
  const limitedServer = app.listen(0)
  await new Promise((resolve) => limitedServer.once('listening', resolve))
  const url = `http://127.0.0.1:${limitedServer.address().port}/api/chat`

  try {
    const first = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: 'hello' }),
    })
    assert.equal(first.status, 503)

    const second = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: 'hello again' }),
    })
    assert.equal(second.status, 429)
    assert.ok(Number(second.headers.get('retry-after')) > 0)
  } finally {
    await new Promise((resolve, reject) => limitedServer.close((error) => error ? reject(error) : resolve()))
  }
})

import { useEffect, useState } from 'react'
import type { FormEvent } from 'react'
import { translate } from './language'
import type { Language } from './language'

type ChatProduct = { id: string; name: string; price: number }
type Message = { id: string; role: 'assistant' | 'user' | 'admin'; text: string; products?: ChatProduct[] }
type StoredMessage = { id: number; sender: 'visitor' | 'bot' | 'admin'; message: string }
type ChatConversation = { id: string; status: 'bot' | 'waiting_admin' | 'closed'; messages: StoredMessage[] }
type ChatResponse = {
  reply?: string | null
  products?: ChatProduct[]
  conversationId?: string
  sessionToken?: string
  conversation?: ChatConversation
  status?: ChatConversation['status']
}

const chatbotUrl = (import.meta.env.VITE_CHATBOT_URL ?? 'http://127.0.0.1:3001').replace(/\/$/, '')
const conversationKey = 'techstore-chat-conversation'
const conversationTokenKey = 'techstore-chat-access-token'
const recommendationsKey = 'techstore-chat-product-recommendations'
const greeting: Message = { id: 'welcome', role: 'assistant', text: 'Xin chào! Mình có thể tư vấn cấu hình, tìm sản phẩm hoặc hỗ trợ đơn hàng. Bạn cần giúp gì?' }
const quickQuestions = [
  { label: 'Tư vấn cấu hình', message: 'Tư vấn cấu hình' },
  { label: 'Tìm sản phẩm', message: 'Tìm sản phẩm' },
  { label: 'Hỗ trợ đơn hàng', message: 'Hỗ trợ đơn hàng' },
  { label: 'Gặp nhân viên', message: 'Tôi muốn gặp nhân viên hỗ trợ' },
]

function loadRecommendations(): Record<string, ChatProduct[]> {
  try {
    const stored: unknown = JSON.parse(sessionStorage.getItem(recommendationsKey) ?? '{}')
    if (!stored || typeof stored !== 'object' || Array.isArray(stored)) return {}

    const recommendations: Record<string, ChatProduct[]> = {}
    for (const [messageId, products] of Object.entries(stored)) {
      if (Array.isArray(products) && products.every((product) =>
        product && typeof product === 'object'
        && typeof product.id === 'string'
        && typeof product.name === 'string'
        && Number.isFinite(product.price),
      )) {
        recommendations[messageId] = products
      }
    }

    return recommendations
  } catch {
    return {}
  }
}

function loadConversationSession(): { id: string | null; token: string | null } {
  const id = sessionStorage.getItem(conversationKey)
  const token = sessionStorage.getItem(conversationTokenKey)
  if (id && token) return { id, token }

  sessionStorage.removeItem(conversationKey)
  sessionStorage.removeItem(conversationTokenKey)
  sessionStorage.removeItem(recommendationsKey)

  return { id: null, token: null }
}

function presentConversation(
  conversation: ChatConversation,
  recommendations: Record<string, ChatProduct[]>,
): Message[] {
  return [
    greeting,
    ...conversation.messages.map((message) => ({
      id: String(message.id),
      role: message.sender === 'visitor' ? 'user' as const : message.sender === 'admin' ? 'admin' as const : 'assistant' as const,
      text: message.message,
      products: recommendations[String(message.id)],
    })),
  ]
}

export function ChatWidget({ language = 'vi' }: { language?: Language }) {
  const t = (text: string) => translate(language, text)
  const [open, setOpen] = useState(false)
  const [input, setInput] = useState('')
  const [conversationSession, setConversationSession] = useState(loadConversationSession)
  const conversationId = conversationSession.id
  const conversationToken = conversationSession.token
  const [recommendations, setRecommendations] = useState(loadRecommendations)
  const [conversationStatus, setConversationStatus] = useState<ChatConversation['status']>('bot')
  const [messages, setMessages] = useState<Message[]>([greeting])
  const [sending, setSending] = useState(false)
  const [connectionNotice, setConnectionNotice] = useState('')

  useEffect(() => {
    const openChat = (event: Event) => {
      setOpen(true)
      const message = (event as CustomEvent<{ message?: string }>).detail?.message
      if (message) setInput(message)
    }
    window.addEventListener('techstore:open-chat', openChat)
    return () => window.removeEventListener('techstore:open-chat', openChat)
  }, [])

  useEffect(() => {
    if (!open || !conversationId || !conversationToken) return
    let active = true
    async function pollConversation() {
      try {
        const response = await fetch(`${chatbotUrl}/api/chat/${conversationId}`, {
          headers: { Authorization: `Bearer ${conversationToken}` },
        })
        const result = await response.json() as ChatConversation & { message?: string }
        if (!response.ok) {
          if ([401, 403, 404].includes(response.status) && active) {
            sessionStorage.removeItem(conversationKey)
            sessionStorage.removeItem(conversationTokenKey)
            sessionStorage.removeItem(recommendationsKey)
            setConversationSession({ id: null, token: null })
            setRecommendations({})
            setConversationStatus('bot')
            setMessages([greeting])
          } else if (active) {
            setConnectionNotice('Chưa tải được tin nhắn mới. Đang thử kết nối lại…')
          }
          return
        }
        if (active) {
          setConnectionNotice('')
          setConversationStatus(result.status)
          setMessages(presentConversation(result, recommendations))
        }
      } catch {
        if (active) setConnectionNotice('Mất kết nối tạm thời. Đang thử kết nối lại…')
      }
    }
    void pollConversation()
    const interval = window.setInterval(() => void pollConversation(), 3000)
    return () => {
      active = false
      window.clearInterval(interval)
    }
  }, [open, conversationId, conversationToken, recommendations])

  async function submitMessage(rawMessage: string) {
    const message = rawMessage.trim()
    if (!message || sending) return
    setInput('')
    setSending(true)

    try {
      const response = await fetch(`${chatbotUrl}/api/chat`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          ...(conversationToken ? { Authorization: `Bearer ${conversationToken}` } : {}),
        },
        body: JSON.stringify({ message, ...(conversationId ? { conversationId } : {}) }),
      })
      const result = await response.json() as ChatResponse & { message?: string }
      if (!response.ok) throw new Error(result.reply || result.message || 'Chatbot tạm thời chưa phản hồi được.')
      if (result.conversationId) {
        if (result.sessionToken) {
          sessionStorage.setItem(conversationKey, result.conversationId)
          sessionStorage.setItem(conversationTokenKey, result.sessionToken)
          setConversationSession({ id: result.conversationId, token: result.sessionToken })
        }
      }
      if (result.conversation) {
        setConversationStatus(result.conversation.status)
        const botMessage = [...result.conversation.messages].reverse().find(({ sender }) => sender === 'bot')
        const nextRecommendations = botMessage && result.products?.length
          ? { ...recommendations, [String(botMessage.id)]: result.products.slice(0, 3) }
          : recommendations
        if (nextRecommendations !== recommendations) {
          setRecommendations(nextRecommendations)
          sessionStorage.setItem(recommendationsKey, JSON.stringify(nextRecommendations))
        }
        setMessages(presentConversation(result.conversation, nextRecommendations))
      } else if (result.status) {
        setConversationStatus(result.status)
      }
    } catch (reason) {
      const text = reason instanceof TypeError
        ? 'Chưa kết nối được chatbot.'
        : reason instanceof Error ? reason.message : 'Chưa kết nối được chatbot.'
      setMessages((current) => [...current, { id: `error-${Date.now()}`, role: 'assistant', text: `${text} Vui lòng thử lại sau.` }])
    } finally {
      setSending(false)
    }
  }

  function sendMessage(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    void submitMessage(input)
  }

  return (
    <div className="chat-widget">
      {open && (
        <section className="chat-panel" aria-label={language === 'vi' ? 'Trò chuyện với TechStore' : 'Chat with TechStore'}>
          <header className="chat-header">
            <div className="chat-avatar"><img src="/techstore-mark.svg" alt="" /></div>
            <div>
              <strong>{t('Trợ lý TechStore')}</strong>
              <span><i />{conversationStatus === 'waiting_admin' ? t('Đang chờ nhân viên hỗ trợ') : conversationStatus === 'closed' ? t('Cuộc trò chuyện đã kết thúc') : t('Đang trực tuyến')}</span>
            </div>
            <button className="chat-close" onClick={() => setOpen(false)} aria-label={t('Đóng trò chuyện')}>×</button>
          </header>
          <div className="chat-messages" aria-live="polite">
            {connectionNotice && <p className="chat-connection-notice" role="status">{connectionNotice}</p>}
            {messages.map((message) => (
              <div className={`chat-entry ${message.role}`} key={message.id}>
                <p className={`chat-message ${message.role}`}>{message.id === 'welcome' ? t(message.text) : message.text}</p>
                {message.products?.map((product) => (
                  <article className="chat-product" key={product.id}>
                    <strong>{product.name}</strong>
                    <span>{new Intl.NumberFormat(language === 'vi' ? 'vi-VN' : 'en-US').format(product.price)} ₫</span>
                    <button
                      type="button"
                      aria-label={`${t('Xem chi tiết')} ${product.name}`}
                      onClick={() => window.dispatchEvent(new CustomEvent('techstore:open-product', { detail: { slug: product.id } }))}
                    >
                      {t('Xem chi tiết')}
                    </button>
                  </article>
                ))}
              </div>
            ))}
            {messages.length === 1 && conversationStatus === 'bot' && (
              <div className="chat-suggestions" aria-label={language === 'vi' ? 'Chủ đề hỗ trợ nhanh' : 'Quick help topics'}>
                {quickQuestions.map((question) => (
                  <button
                    key={question.message}
                    type="button"
                    disabled={sending}
                    onClick={() => void submitMessage(question.message)}
                  >
                    {t(question.label)}
                  </button>
                ))}
              </div>
            )}
            {sending && <p className="chat-message assistant typing">{t('Đang xử lý tin nhắn…')}</p>}
          </div>
          <form className="chat-form" onSubmit={sendMessage}>
            <input
              aria-label={language === 'vi' ? 'Tin nhắn' : 'Message'}
              maxLength={500}
              placeholder={conversationStatus === 'waiting_admin' ? language === 'vi' ? 'Gửi thêm thông tin cho nhân viên…' : 'Send more information to the agent…' : language === 'vi' ? 'Nhập câu hỏi của bạn…' : 'Type your question…'}
              value={input}
              onChange={(event) => setInput(event.target.value)}
              disabled={sending}
            />
            <button aria-label={t('Gửi tin nhắn')} disabled={sending || !input.trim()}>↗</button>
          </form>
        </section>
      )}
      <button className="chat-launcher" aria-expanded={open} onClick={() => setOpen((value) => !value)}>
        <span aria-hidden="true">{open ? '×' : '✳'}</span>{open ? t('Đóng') : t('Tư vấn')}
      </button>
    </div>
  )
}

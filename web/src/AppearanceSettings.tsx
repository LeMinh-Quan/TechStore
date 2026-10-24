import { useLayoutEffect, useState } from 'react'
import type { CSSProperties } from 'react'
import { translate } from './language'
import type { Language } from './language'

type ThemeMode = 'light' | 'dark'
type Palette = { id: string; label: string; primary: string; dark: string; soft: string }

const preferenceKey = 'techstore-appearance'
const palettes: Palette[] = [
  { id: 'emerald', label: 'Xanh lá', primary: '#10b981', dark: '#047857', soft: '#d1fae5' },
  { id: 'blue', label: 'Xanh dương', primary: '#3b82f6', dark: '#1d4ed8', soft: '#dbeafe' },
  { id: 'violet', label: 'Tím', primary: '#8b5cf6', dark: '#6d28d9', soft: '#ede9fe' },
  { id: 'rose', label: 'Đỏ hồng', primary: '#f43f5e', dark: '#be123c', soft: '#ffe4e6' },
  { id: 'amber', label: 'Cam', primary: '#f59e0b', dark: '#b45309', soft: '#fef3c7' },
  { id: 'cyan', label: 'Xanh ngọc', primary: '#06b6d4', dark: '#0e7490', soft: '#cffafe' },
  { id: 'slate', label: 'Xám xanh', primary: '#64748b', dark: '#334155', soft: '#e2e8f0' },
]

function loadPreferences(): { mode: ThemeMode; paletteId: string } {
  try {
    const saved: unknown = JSON.parse(localStorage.getItem(preferenceKey) ?? 'null')
    if (saved && typeof saved === 'object') {
      const value = saved as { mode?: unknown; paletteId?: unknown }
      return {
        mode: value.mode === 'dark' ? 'dark' : 'light',
        paletteId: palettes.some((palette) => palette.id === value.paletteId) ? value.paletteId as string : 'blue',
      }
    }
  } catch {
    // Ignore malformed local preferences and use the defaults.
  }

  return { mode: 'light', paletteId: 'blue' }
}

function applyPreferences(mode: ThemeMode, paletteId: string) {
  const palette = palettes.find((item) => item.id === paletteId) ?? palettes[1]
  const root = document.documentElement
  root.dataset.theme = mode
  root.dataset.palette = palette.id
  root.style.setProperty('--blue', palette.dark)
  root.style.setProperty('--blue-dark', palette.dark)
  root.style.setProperty('--blue-deep', palette.dark)
  root.style.setProperty('--blue-soft', palette.soft)
}

export function AppearanceSettings({ language = 'vi' }: { language?: Language }) {
  const t = (text: string) => translate(language, text)
  const [mode, setMode] = useState<ThemeMode>(() => loadPreferences().mode)
  const [paletteId, setPaletteId] = useState(() => loadPreferences().paletteId)
  const [open, setOpen] = useState(false)

  useLayoutEffect(() => {
    applyPreferences(mode, paletteId)
    localStorage.setItem(preferenceKey, JSON.stringify({ mode, paletteId }))
  }, [mode, paletteId])

  return (
    <div className="appearance-settings">
      <button
        className="appearance-launcher"
        type="button"
        aria-label={t('Tùy chỉnh giao diện')}
        aria-expanded={open}
        onClick={() => setOpen((current) => !current)}
      >
        <span aria-hidden="true">◉</span>
        <span>{t('Giao diện')}</span>
      </button>
      {open && (
        <section className="appearance-panel" aria-label={t('Tùy chỉnh giao diện')}>
          <header className="appearance-heading">
            <span className="appearance-heading-icon" aria-hidden="true">◉</span>
            <span><strong>{t('Giao diện')}</strong><small>{t('Tùy chỉnh màu sắc cá nhân')}</small></span>
            <button type="button" aria-label={t('Đóng tùy chỉnh giao diện')} onClick={() => setOpen(false)}>×</button>
          </header>
          <fieldset className="appearance-mode">
            <legend>{t('Chế độ hiển thị')}</legend>
            <button className={mode === 'light' ? 'selected' : ''} type="button" aria-pressed={mode === 'light'} onClick={() => setMode('light')}>
              <span aria-hidden="true">☼</span>{t('Sáng')}
            </button>
            <button className={mode === 'dark' ? 'selected' : ''} type="button" aria-pressed={mode === 'dark'} onClick={() => setMode('dark')}>
              <span aria-hidden="true">☾</span>{t('Tối')}
            </button>
          </fieldset>
          <fieldset className="appearance-colors">
            <legend>{t('Màu chủ đạo')}</legend>
            <div>
              {palettes.map((palette) => (
                <button
                  key={palette.id}
                  type="button"
                  className={paletteId === palette.id ? 'selected' : ''}
                  aria-label={`${t('Màu chủ đạo')}: ${t(palette.label)}`}
                  aria-pressed={paletteId === palette.id}
                  title={t(palette.label)}
                  style={{ '--swatch-color': palette.primary } as CSSProperties}
                  onClick={() => setPaletteId(palette.id)}
                />
              ))}
            </div>
          </fieldset>
        </section>
      )}
    </div>
  )
}

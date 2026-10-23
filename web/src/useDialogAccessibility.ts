import { useEffect } from 'react'

const focusableSelector = 'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])'

export function useDialogAccessibility() {
  useEffect(() => {
    let activeDialog: HTMLElement | null = null
    let restoreFocus: HTMLElement | null = null

    const syncDialog = () => {
      const nextDialog = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]')
      if (nextDialog === activeDialog) return

      if (!nextDialog) {
        if (restoreFocus?.isConnected) restoreFocus.focus()
        restoreFocus = null
        activeDialog = null
        return
      }

      if (!activeDialog) {
        restoreFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null
      }
      activeDialog = nextDialog
      const initialFocus = nextDialog.querySelector<HTMLElement>(focusableSelector)
      if (initialFocus) initialFocus.focus()
      else {
        nextDialog.tabIndex = -1
        nextDialog.focus()
      }
    }

    const handleKeyDown = (event: KeyboardEvent) => {
      const dialog = document.querySelector<HTMLElement>('[role="dialog"][aria-modal="true"]')
      if (!dialog) return

      if (event.key === 'Escape') {
        const closeButton = dialog.querySelector<HTMLButtonElement>('.detail-close, .commerce-close')
        if (closeButton) {
          event.preventDefault()
          closeButton.click()
        }
        return
      }

      if (event.key !== 'Tab') return
      const focusable = Array.from(dialog.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => element.getClientRects().length > 0)
      if (focusable.length === 0) {
        event.preventDefault()
        dialog.focus()
        return
      }

      const first = focusable[0]
      const last = focusable[focusable.length - 1]
      if (event.shiftKey && (document.activeElement === first || !dialog.contains(document.activeElement))) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && (document.activeElement === last || !dialog.contains(document.activeElement))) {
        event.preventDefault()
        first.focus()
      }
    }

    const observer = new MutationObserver(syncDialog)
    observer.observe(document.body, { childList: true, subtree: true })
    document.addEventListener('keydown', handleKeyDown)
    syncDialog()

    return () => {
      observer.disconnect()
      document.removeEventListener('keydown', handleKeyDown)
      if (restoreFocus?.isConnected) restoreFocus.focus()
    }
  }, [])
}

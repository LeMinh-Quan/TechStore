import { lazy, StrictMode, Suspense } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'

const App = lazy(() => import('./App.tsx'))
const AdminApp = lazy(() => import('./AdminApp'))
const StaffApp = lazy(() => import('./StaffApp'))
const Page = window.location.pathname.startsWith('/admin')
  ? AdminApp
  : window.location.pathname.startsWith('/staff')
    ? StaffApp
    : App
export { Page }

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Suspense fallback={<main className="app-loading" role="status">Đang tải TechStore…</main>}>
      <Page />
    </Suspense>
  </StrictMode>,
)

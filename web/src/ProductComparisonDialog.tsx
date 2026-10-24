import { translate, translateCategory } from './language'
import type { Language } from './language'

type ComparisonProduct = {
  id: string
  name: string
  category: string | null
  brand: string | null
  price: number
  stock: number
  specifications?: Record<string, string>
}

type ProductComparisonDialogProps = {
  products: ComparisonProduct[]
  onClose: () => void
  language?: Language
}

export function ProductComparisonDialog({ products, onClose, language = 'vi' }: ProductComparisonDialogProps) {
  const t = (text: string) => translate(language, text)
  const rows: { label: string; value: (product: ComparisonProduct) => string }[] = [
    { label: language === 'vi' ? 'Giá' : 'Price', value: (product) => product.price > 0 ? `${new Intl.NumberFormat(language === 'vi' ? 'vi-VN' : 'en-US').format(product.price)} ₫` : language === 'vi' ? 'Đang cập nhật' : 'Being updated' },
    { label: t('Danh mục'), value: (product) => product.category ? translateCategory(language, product.category) : '—' },
    { label: t('Thương hiệu'), value: (product) => product.brand ?? '—' },
    { label: language === 'vi' ? 'Tồn kho' : 'Stock', value: (product) => product.stock > 0 ? `${product.stock} ${t('sản phẩm')}` : t('Hết hàng') },
    ...Array.from(new Set(products.flatMap((product) => Object.keys(product.specifications ?? {}).filter((key) => key !== 'raw'))))
      .map((key) => ({ label: key, value: (product: ComparisonProduct) => product.specifications?.[key] ?? '—' })),
  ]

  return (
    <div className="commerce-backdrop" role="presentation" onClick={onClose}>
      <section className="commerce-dialog compare-dialog" role="dialog" aria-modal="true" aria-labelledby="compare-title" onClick={(event) => event.stopPropagation()}>
        <button className="commerce-close" type="button" aria-label={t('Đóng')} onClick={onClose}>×</button>
        <span className="eyebrow">{language === 'vi' ? 'SO SÁNH SẢN PHẨM' : 'PRODUCT COMPARISON'}</span><h2 id="compare-title">{language === 'vi' ? 'Đặt cạnh nhau để chọn dễ hơn' : 'Compare products side by side'}</h2>
        <div className="compare-table-wrap"><table className="compare-table"><thead><tr><th>{language === 'vi' ? 'Thông tin' : 'Details'}</th>{products.map((product) => <th key={product.id}>{product.name}</th>)}</tr></thead><tbody>
          {rows.map(({ label, value }) => <tr key={label}><th>{label}</th>{products.map((product) => <td key={product.id}>{value(product)}</td>)}</tr>)}
        </tbody></table></div>
      </section>
    </div>
  )
}

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
}

export function ProductComparisonDialog({ products, onClose }: ProductComparisonDialogProps) {
  const rows: { label: string; value: (product: ComparisonProduct) => string }[] = [
    { label: 'Giá', value: (product) => product.price > 0 ? `${new Intl.NumberFormat('vi-VN').format(product.price)} ₫` : 'Đang cập nhật' },
    { label: 'Danh mục', value: (product) => product.category ?? '—' },
    { label: 'Thương hiệu', value: (product) => product.brand ?? '—' },
    { label: 'Tồn kho', value: (product) => product.stock > 0 ? `${product.stock} sản phẩm` : 'Hết hàng' },
    ...Array.from(new Set(products.flatMap((product) => Object.keys(product.specifications ?? {}).filter((key) => key !== 'raw'))))
      .map((key) => ({ label: key, value: (product: ComparisonProduct) => product.specifications?.[key] ?? '—' })),
  ]

  return (
    <div className="commerce-backdrop" role="presentation" onClick={onClose}>
      <section className="commerce-dialog compare-dialog" role="dialog" aria-modal="true" aria-labelledby="compare-title" onClick={(event) => event.stopPropagation()}>
        <button className="commerce-close" type="button" aria-label="Đóng" onClick={onClose}>×</button>
        <span className="eyebrow">SO SÁNH SẢN PHẨM</span><h2 id="compare-title">Đặt cạnh nhau để chọn dễ hơn</h2>
        <div className="compare-table-wrap"><table className="compare-table"><thead><tr><th>Thông tin</th>{products.map((product) => <th key={product.id}>{product.name}</th>)}</tr></thead><tbody>
          {rows.map(({ label, value }) => <tr key={label}><th>{label}</th>{products.map((product) => <td key={product.id}>{value(product)}</td>)}</tr>)}
        </tbody></table></div>
      </section>
    </div>
  )
}

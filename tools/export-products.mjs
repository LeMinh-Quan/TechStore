import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import ExcelJS from 'exceljs'

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const sourceFile = path.resolve(process.argv[2] ?? 'A:\\site-map\\cellphones_product_details_final.xlsx')
const outputFile = path.resolve(process.argv[3] ?? path.join(projectRoot, 'api', 'storage', 'app', 'imports', 'cellphones-products.jsonl'))
const worksheetName = 'Sản phẩm'

function cleanText(value) {
  if (value === null || value === undefined) return ''
  if (typeof value === 'object' && Array.isArray(value.richText)) {
    return value.richText.map((part) => part.text ?? '').join('').trim()
  }
  if (typeof value === 'object' && 'text' in value) return String(value.text ?? '').trim()
  return String(value).replace(/\u0000/g, '').trim()
}

function cleanPrice(value) {
  if (typeof value === 'number' && Number.isFinite(value) && value >= 0) return Math.round(value)
  const digits = cleanText(value).replace(/[^\d]/g, '')
  return digits ? Number(digits) : 0
}

const workbook = new ExcelJS.Workbook()
await workbook.xlsx.readFile(sourceFile)
const sheet = workbook.getWorksheet(worksheetName)
if (!sheet) throw new Error(`Workbook does not contain the "${worksheetName}" worksheet.`)

const headers = sheet.getRow(1).values.slice(1).map(cleanText)
const column = new Map(headers.map((header, index) => [header, index + 1]))
const required = ['URL', 'Ngành hàng', 'Tên sản phẩm']
const missing = required.filter((header) => !column.has(header))
if (missing.length) throw new Error(`Missing required workbook columns: ${missing.join(', ')}`)

const productRows = []
let skippedRows = 0
for (let rowNumber = 2; rowNumber <= sheet.rowCount; rowNumber++) {
  const row = sheet.getRow(rowNumber)
  const get = (header) => row.getCell(column.get(header) ?? 0).value
  const url = cleanText(get('URL'))
  const name = cleanText(get('Tên sản phẩm'))
  const category = cleanText(get('Ngành hàng'))

  if (!url || !name || !category) {
    skippedRows++
    continue
  }

  productRows.push({
    url,
    name,
    category,
    brand: cleanText(get('Thương hiệu') ?? get('Hãng sản xuất')),
    sale_price: cleanPrice(get('Giá khuyến mãi')),
    list_price: cleanPrice(get('Giá niêm yết')),
    description: cleanText(get('Mô tả ngắn')),
    specifications: {
      cpu: cleanText(get('CPU')),
      ram: cleanText(get('RAM')),
      storage: cleanText(get('Ổ cứng')),
      graphics: cleanText(get('Card màn hình')),
      display: cleanText(get('Màn hình')),
      battery: cleanText(get('Pin')),
      operating_system: cleanText(get('Hệ điều hành')),
      manufacturer: cleanText(get('Hãng sản xuất')),
      dimensions: cleanText(get('Trọng lượng / Kích thước')),
      raw: cleanText(get('Thông số kỹ thuật gốc')),
    },
  })
}

await mkdir(path.dirname(outputFile), { recursive: true })
await writeFile(outputFile, `${productRows.map((row) => JSON.stringify(row)).join('\n')}\n`, 'utf8')
console.log(`Exported ${productRows.length} products; skipped ${skippedRows} incomplete rows.`)
console.log(`JSONL output: ${outputFile}`)

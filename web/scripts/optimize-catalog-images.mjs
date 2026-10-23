import { readdir, stat } from 'node:fs/promises'
import { extname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const scriptDirectory = fileURLToPath(new URL('.', import.meta.url))
const catalogDirectory = resolve(scriptDirectory, '../../api/storage/app/public/catalog')
const sourceExtensions = new Set(['.jpg', '.jpeg', '.png', '.webp'])
const variantWidths = [320, 480, 960]
const concurrency = 4

async function findImages(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const images = []
  for (const entry of entries) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) {
      images.push(...await findImages(path))
    } else if (entry.isFile() && sourceExtensions.has(extname(entry.name).toLowerCase())
      && !/-\d+\.webp$/i.test(entry.name)) {
      images.push(path)
    }
  }
  return images
}

async function optimizeImage(sourcePath) {
  const sourceStats = await stat(sourcePath)
  const metadata = await sharp(sourcePath).metadata()
  if (!metadata.width) {
    throw new Error(`Image has no readable width: ${sourcePath}`)
  }

  let generated = 0
  let savedBytes = 0
  for (const width of variantWidths.filter((size) => size <= metadata.width)) {
    const targetPath = `${sourcePath.slice(0, -extname(sourcePath).length)}-${width}.webp`
    try {
      const targetStats = await stat(targetPath)
      if (targetStats.mtimeMs >= sourceStats.mtimeMs && targetStats.size > 0) continue
    } catch (error) {
      if (error.code !== 'ENOENT') throw error
    }

    const result = await sharp(sourcePath)
      .rotate()
      .resize({ width, withoutEnlargement: true })
      .webp({ quality: 80, effort: 4 })
      .toFile(targetPath)
    generated += 1
    savedBytes += Math.max(0, sourceStats.size - result.size)
  }

  return { generated, savedBytes }
}

const images = await findImages(catalogDirectory)
let nextIndex = 0
let generated = 0
let savedBytes = 0
const failures = []

await Promise.all(Array.from({ length: concurrency }, async () => {
  while (nextIndex < images.length) {
    const imagePath = images[nextIndex]
    nextIndex += 1
    try {
      const result = await optimizeImage(imagePath)
      generated += result.generated
      savedBytes += result.savedBytes
    } catch (error) {
      failures.push(error instanceof Error ? error.message : String(error))
    }
  }
}))

console.log(`Processed ${images.length} source images; generated ${generated} WebP variants; saved up to ${Math.round(savedBytes / 1024)} KiB.`)
if (failures.length > 0) {
  for (const failure of failures) console.error(failure)
  process.exitCode = 1
}

export const DEFAULT_MAX_BYTES = 5 * 1024 * 1024
export const DEFAULT_MAX_DIM = 1600

const readImage = (file) => new Promise((resolve, reject) => {
    const img = new Image()
    const url = URL.createObjectURL(file)
    img.onload = () => {
        URL.revokeObjectURL(url)
        resolve(img)
    }
    img.onerror = (err) => {
        URL.revokeObjectURL(url)
        reject(err)
    }
    img.src = url
})

const canvasToBlob = (canvas, type, quality) => new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), type, quality)
})

export const compressImage = async (file, options = {}) => {
    const {
        maxBytes = DEFAULT_MAX_BYTES,
        maxDim = DEFAULT_MAX_DIM,
        initialQuality = 0.85,
        minQuality = 0.5,
        outputType = 'image/jpeg',
    } = options

    if (!file || !file.type?.startsWith('image/')) return file
    if (file.size <= maxBytes) return file

    const img = await readImage(file)
    const scale = Math.min(1, maxDim / Math.max(img.width, img.height))
    const width = Math.round(img.width * scale)
    const height = Math.round(img.height * scale)
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    ctx.drawImage(img, 0, 0, width, height)

    let quality = initialQuality
    let blob = await canvasToBlob(canvas, outputType, quality)
    while (blob && blob.size > maxBytes && quality > minQuality) {
        quality = Math.max(minQuality, quality - 0.1)
        blob = await canvasToBlob(canvas, outputType, quality)
    }

    if (!blob || blob.size > maxBytes) return file
    const newName = file.name.replace(/\.\w+$/, '.jpg')
    return new File([blob], newName, { type: outputType })
}

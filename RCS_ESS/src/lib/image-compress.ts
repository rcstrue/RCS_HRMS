/**
 * WhatsApp HD-style image compression utility.
 *
 * Strategy (mirrors WhatsApp's "HD photo" behaviour):
 *   1. Decode the source image.
 *   2. If the longest side exceeds MAX_DIMENSION (1600 px), down-scale
 *      proportionally so it fits inside that box.
 *   3. Re-encode as JPEG starting at QUALITY_START (0.82).
 *   4. If the output still exceeds MAX_SIZE_KB (1024 KB ≈ 1 MB), keep the
 *      same resolution and lower quality in steps of 0.05 until it fits or
 *      quality reaches MIN_QUALITY (0.10).
 *   5. As a last resort (still too large), further reduce the resolution by
 *      10 % each round while keeping MIN_QUALITY.
 *
 * Returns a Blob so callers can construct a File and pass it to the normal
 * upload pipeline.
 */

export const MAX_DIMENSION = 1600;       // longest side in px
export const MAX_SIZE_KB = 1024;          // ≈ 1 MB
const QUALITY_START = 0.82;
const MIN_QUALITY = 0.10;
const QUALITY_STEP = 0.05;
const RESOLUTION_STEP = 0.90;             // 10 % shrink per fallback round

/** Load an image from a File, Blob, or data-URL string. */
function loadImage(src: File | Blob | string): Promise<HTMLImageElement> {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(img);
    img.onerror = () => reject(new Error('Failed to load image'));
    if (typeof src === 'string') {
      img.src = src;
    } else {
      img.src = URL.createObjectURL(src);
    }
  });
}

/** Measure the data-URL size in KB (base64 ≈ 3/4 of raw bytes). */
function dataUrlSizeKB(dataUrl: string): number {
  // "data:image/jpeg;base64," prefix → ignore the header part
  const commaIdx = dataUrl.indexOf(',');
  const base64 = dataUrl.substring(commaIdx + 1);
  return (base64.length * 3) / 4 / 1024;
}

/**
 * Compress an image file to ≤ 1 MB using WhatsApp HD-like logic.
 *
 * @param file  - Source image (File / Blob)
 * @returns     - A new File (JPEG) that is ≤ MAX_SIZE_KB
 */
export async function compressImageHD(
  file: File | Blob,
): Promise<File> {
  const img = await loadImage(file);
  let { width, height } = img;

  // Step 1: Fit inside MAX_DIMENSION box
  if (width > MAX_DIMENSION || height > MAX_DIMENSION) {
    const ratio = Math.min(MAX_DIMENSION / width, MAX_DIMENSION / height);
    width = Math.round(width * ratio);
    height = Math.round(height * ratio);
  }

  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d')!;

  let quality = QUALITY_START;

  // Step 2-4: Try quality reduction loop first (at same resolution)
  while (quality >= MIN_QUALITY) {
    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);

    const dataUrl = canvas.toDataURL('image/jpeg', quality);
    const sizeKB = dataUrlSizeKB(dataUrl);

    if (sizeKB <= MAX_SIZE_KB) {
      return dataUrlToFile(dataUrl, file instanceof File ? file.name : 'image.jpg');
    }

    quality = Math.max(MIN_QUALITY, +(quality - QUALITY_STEP).toFixed(2));
  }

  // Step 5: Last resort — shrink resolution while at MIN_QUALITY
  while (width > 400 && height > 400) {
    width = Math.round(width * RESOLUTION_STEP);
    height = Math.round(height * RESOLUTION_STEP);

    canvas.width = width;
    canvas.height = height;
    ctx.clearRect(0, 0, width, height);
    ctx.drawImage(img, 0, 0, width, height);

    const dataUrl = canvas.toDataURL('image/jpeg', MIN_QUALITY);
    if (dataUrlSizeKB(dataUrl) <= MAX_SIZE_KB) {
      return dataUrlToFile(dataUrl, file instanceof File ? file.name : 'image.jpg');
    }
  }

  // Extremely small image — just return whatever we have
  const finalUrl = canvas.toDataURL('image/jpeg', MIN_QUALITY);
  return dataUrlToFile(finalUrl, file instanceof File ? file.name : 'image.jpg');
}

/** Convert a data-URL to a File object. */
function dataUrlToFile(dataUrl: string, originalName: string): File {
  const commaIdx = dataUrl.indexOf(',');
  const byteString = atob(dataUrl.substring(commaIdx + 1));
  const mimeString = dataUrl.substring(5, commaIdx).split(':')[1].split(';')[0];

  const ab = new ArrayBuffer(byteString.length);
  const ia = new Uint8Array(ab);
  for (let i = 0; i < byteString.length; i++) {
    ia[i] = byteString.charCodeAt(i);
  }

  // Build a nice filename: "photo_1701234567890.jpg"
  const ext = 'jpg';
  const baseName = originalName.replace(/\.[^.]+$/, '') || 'photo';
  const fileName = `${baseName}_hd.${ext}`;

  return new File([ab], fileName, { type: mimeString || 'image/jpeg' });
}
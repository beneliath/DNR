// Preview fitting never changes the saved logo, its dimensions, or its SVG data.
export function logoArtworkBounds(pixels, width, height) {
    let transparent = false;
    for (let i = 3; i < pixels.length; i += 4) if (pixels[i] < 250) { transparent = true; break; }
    const corners = [0, (width - 1) * 4, (height - 1) * width * 4, (width * height - 1) * 4];
    const background = Array.from(pixels.slice(0, 3));
    const uniformBackground = !transparent && corners.every(i => background.every((value, channel) => Math.abs(value - pixels[i + channel]) <= 8));
    let left = width, top = height, right = -1, bottom = -1;
    for (let y = 0; y < height; y++) for (let x = 0; x < width; x++) {
        const i = (y * width + x) * 4;
        if (pixels[i + 3] < 16 || (uniformBackground && background.every((value, channel) => Math.abs(value - pixels[i + channel]) <= 16))) continue;
        left = Math.min(left, x); top = Math.min(top, y); right = Math.max(right, x); bottom = Math.max(bottom, y);
    }
    return right < left ? {x: 0, y: 0, width, height} : {x: left, y: top, width: right - left + 1, height: bottom - top + 1};
}

export function logoPreviewLayout(width, height, bounds, frameWidth, frameHeight) {
    const scale = Math.min(frameWidth / bounds.width, frameHeight / bounds.height, 8 * Math.min(frameWidth / width, frameHeight / height));
    return {
        width: width * scale, height: height * scale,
        left: frameWidth / 2 - (bounds.x + bounds.width / 2) * scale,
        top: frameHeight / 2 - (bounds.y + bounds.height / 2) * scale,
    };
}

const measured = new WeakMap();
export function fitBrandLogoPreview(image) {
    const stage = image.closest('.content-brand-logo-stage');
    if (!stage || image.hidden || !image.complete || !image.naturalWidth || !stage.clientWidth) return;
    const width = image.naturalWidth, height = image.naturalHeight, source = image.currentSrc || image.src;
    let bounds = measured.get(image)?.source === source ? measured.get(image).bounds : null;
    if (!bounds) {
        bounds = {x: 0, y: 0, width, height};
        try {
            const canvas = image.ownerDocument.createElement('canvas');
            const scale = Math.min(1, 512 / Math.max(width, height));
            canvas.width = Math.max(1, Math.round(width * scale)); canvas.height = Math.max(1, Math.round(height * scale));
            const context = canvas.getContext('2d', {willReadFrequently: true});
            if (context) {
                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                const box = logoArtworkBounds(context.getImageData(0, 0, canvas.width, canvas.height).data, canvas.width, canvas.height);
                bounds = {x: box.x * width / canvas.width, y: box.y * height / canvas.height, width: box.width * width / canvas.width, height: box.height * height / canvas.height};
            }
            canvas.width = canvas.height = 1;
        } catch { /* Keep the ordinary fitted preview if pixels cannot be read. */ }
        measured.set(image, {source, bounds});
    }
    const layout = logoPreviewLayout(width, height, bounds, stage.clientWidth, stage.clientHeight);
    Object.entries(layout).forEach(([key, value]) => { image.style[key] = `${value}px`; });
}

// Equal travel on either side of the midpoint gives 25%–400% zoom, centered at 100%.
export const imageCropZoom = position => 2 ** (Math.max(-100, Math.min(100, Number(position) || 0)) / 50);

export function imageCropLayout(imageWidth, imageHeight, aspect, zoom, horizontal = 50, vertical = 50) {
  const width = 600, height = Math.max(1, Math.round(width / aspect));
  const scale = Math.max(width / imageWidth, height / imageHeight) * zoom;
  const drawnWidth = imageWidth * scale, drawnHeight = imageHeight * scale;
  const travelX = Math.abs(width - drawnWidth), travelY = Math.abs(height - drawnHeight);
  const offset = position => Math.max(0, Math.min(100, Number(position))) / 100 - 0.5;
  return {
    width, height,
    x: (width - drawnWidth) / 2 + travelX * offset(horizontal),
    y: (height - drawnHeight) / 2 + travelY * offset(vertical),
    drawnWidth, drawnHeight,
    canMoveX: travelX > 0.5, canMoveY: travelY > 0.5,
  };
}

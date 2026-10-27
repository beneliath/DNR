// Keep text lines intact at the preview edge. Images may continue below the card.
export function safePreviewBoundary(limit, ranges) {
  let boundary = Math.max(0, Math.floor(limit));
  for (const {top, bottom} of ranges.filter(r => r.bottom > r.top).sort((a, b) => b.bottom - a.bottom)) {
    if (top < boundary && bottom > boundary) boundary = Math.max(0, Math.floor(top));
  }
  return boundary;
}

export function templatePreviewBoundary(doc, limit) {
  const ranges = [], scrollY = doc.defaultView.scrollY;
  const include = rect => {
    if (rect.width > 0 && rect.height > 0 && rect.top + scrollY < limit && rect.bottom + scrollY > 0) {
      ranges.push({top: rect.top + scrollY - 1, bottom: rect.bottom + scrollY + 1});
    }
  };
  const walker = doc.createTreeWalker(doc.body, 4);
  const range = doc.createRange();
  while (walker.nextNode()) {
    const node = walker.currentNode;
    if (!node.textContent.trim() || node.parentElement.closest('script,style,noscript,template')) continue;
    range.selectNodeContents(node);
    for (const rect of range.getClientRects()) include(rect);
  }
  for (const node of doc.body.querySelectorAll('input,button,select,textarea,hr')) {
    include(node.getBoundingClientRect());
  }
  return safePreviewBoundary(limit, ranges);
}

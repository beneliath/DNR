// Keep both installation modes compatible with audienceCollectionEmbedCode().
export function collectionEmbedCode(publicUrl, document) {
    const attribute = value => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;').replaceAll("'", '&#039;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
    const url = attribute(publicUrl);
    return document.embedded?.disable_javascript
        ? `<iframe src="${url}" title="${attribute(document.title)}" width="100%" height="720" frameborder="0" sandbox="allow-forms allow-same-origin allow-popups" referrerpolicy="origin"></iframe>`
        : `<script src="${url}&amp;embed=1" defer></script>`;
}

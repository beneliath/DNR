const previews = document.querySelectorAll('[data-pdf-receipt-preview]');

if (previews.length) {
    let pdfjsPromise;
    function loadPdfJs() { return pdfjsPromise ||= import('./vendor/pdfjs-6.3.289/pdf.min.mjs').then((pdfjs) => {
        pdfjs.GlobalWorkerOptions.workerSrc = new URL('./vendor/pdfjs-6.3.289/pdf.worker.min.mjs', import.meta.url).href;
        return pdfjs;
    }); }

    async function renderPreview(preview) {
        let loadingTask;
        try {
            const pdfjs = await loadPdfJs();
            loadingTask = pdfjs.getDocument({
                url: preview.dataset.receiptUrl,
                withCredentials: true,
                isEvalSupported: false,
            });
            const document = await loadingTask.promise;
            const page = await document.getPage(1);
            const base = page.getViewport({ scale: 1 });
            const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
            const scale = Math.min(
                preview.clientWidth * pixelRatio / base.width,
                preview.clientHeight * pixelRatio / base.height,
            );
            const viewport = page.getViewport({ scale });
            const canvas = preview.querySelector('canvas');
            canvas.width = Math.ceil(viewport.width);
            canvas.height = Math.ceil(viewport.height);
            canvas.style.width = `${viewport.width / pixelRatio}px`;
            canvas.style.height = `${viewport.height / pixelRatio}px`;
            await page.render({ canvas, viewport }).promise;
            canvas.hidden = false;
            preview.querySelector('.reimbursement-pdf-preview-fallback').hidden = true;
            page.cleanup();
        } catch {
            // Keep the PDF label when a document cannot be previewed.
        } finally {
            if (loadingTask) await loadingTask.destroy();
        }
    }

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) continue;
                observer.unobserve(entry.target);
                void renderPreview(entry.target);
            }
        }, { rootMargin: '250px' });
        previews.forEach((preview) => observer.observe(preview));
    } else {
        previews.forEach((preview) => { void renderPreview(preview); });
    }
}

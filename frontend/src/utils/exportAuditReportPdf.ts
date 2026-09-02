const waitForPaint = () => new Promise<void>((resolve) => {
  requestAnimationFrame(() => requestAnimationFrame(() => resolve()));
});

const assertRenderedReport = (element: HTMLElement) => {
  if (!element.isConnected) throw new Error('The PDF report container is not attached to the document.');
  const style = window.getComputedStyle(element);
  const bounds = element.getBoundingClientRect();
  if (style.display === 'none' || style.visibility === 'hidden' || bounds.width <= 0 || bounds.height <= 0) {
    throw new Error('The PDF report container is not rendered.');
  }
};

export async function exportAuditReportPdf(element: HTMLElement, auditId: string) {
  try {
    assertRenderedReport(element);
    await (document.fonts?.ready ?? Promise.resolve());
    await waitForPaint();
    assertRenderedReport(element);

    const [{ default: html2canvas }, { jsPDF }] = await Promise.all([
      import('html2canvas'),
      import('jspdf'),
    ]);

    const exportWidth = Math.ceil(element.scrollWidth);
    const exportHeight = Math.ceil(element.scrollHeight);
    if (!exportWidth || !exportHeight) throw new Error('The PDF report container has no measurable content.');

    const canvas = await html2canvas(element, {
      scale: 2,
      useCORS: true,
      backgroundColor: '#ffffff',
      logging: false,
      removeContainer: true,
      allowTaint: false,
      imageTimeout: 15000,
      windowWidth: exportWidth,
      windowHeight: exportHeight,
      onclone: (documentClone) => {
        documentClone.querySelectorAll('style, link[rel="stylesheet"]').forEach((node) => node.remove());
        documentClone.documentElement.style.background = '#ffffff';
        documentClone.body.style.margin = '0';
        documentClone.body.style.background = '#ffffff';
        const report = documentClone.querySelector<HTMLElement>('[data-pdf-safe-report]');
        if (!report) throw new Error('The cloned PDF report container could not be found.');
        report.style.position = 'static';
        report.style.left = '0';
        report.style.top = '0';
        report.style.width = `${exportWidth}px`;
        report.style.margin = '0';
        report.style.transform = 'none';
        report.style.zIndex = '0';
      },
    });

    if (!canvas.width || !canvas.height) throw new Error('html2canvas returned an empty report canvas.');

    const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4', compress: true });
    const pageWidth = pdf.internal.pageSize.getWidth();
    const pageHeight = pdf.internal.pageSize.getHeight();
    const margin = 10;
    const printableWidth = pageWidth - margin * 2;
    const printableHeight = pageHeight - margin * 2;
    const pixelsPerPage = Math.max(1, Math.floor((printableHeight / printableWidth) * canvas.width));

    for (let sourceY = 0, pageIndex = 0; sourceY < canvas.height; pageIndex += 1) {
      const sliceHeight = Math.min(pixelsPerPage, canvas.height - sourceY);
      const pageCanvas = document.createElement('canvas');
      pageCanvas.width = canvas.width;
      pageCanvas.height = sliceHeight;
      const context = pageCanvas.getContext('2d', { alpha: false });
      if (!context) throw new Error('Unable to create a PDF page canvas.');

      context.fillStyle = '#ffffff';
      context.fillRect(0, 0, pageCanvas.width, pageCanvas.height);
      context.drawImage(canvas, 0, sourceY, canvas.width, sliceHeight, 0, 0, canvas.width, sliceHeight);

      if (pageIndex > 0) pdf.addPage('a4', 'portrait');
      const renderedHeight = (sliceHeight / canvas.width) * printableWidth;
      pdf.addImage(pageCanvas.toDataURL('image/jpeg', 0.94), 'JPEG', margin, margin, printableWidth, renderedHeight, undefined, 'FAST');
      sourceY += sliceHeight;
    }

    const safeAuditId = auditId.replace(/[^a-zA-Z0-9_-]/g, '') || 'audit';
    pdf.save(`auditseo-report-${safeAuditId}.pdf`);
  } catch {
    throw new Error('PDF export failed. Please try again.');
  }
}

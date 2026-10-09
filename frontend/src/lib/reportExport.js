// html2pdf.js and exceljs are large (they pull in html2canvas/jsPDF and a
// full spreadsheet writer) and are only ever needed once someone actually
// clicks an export button, so they're loaded on demand here instead of
// being bundled into every page that could export.

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  return url
}

/**
 * Clone the printable node into a temporary on-document host so html2canvas
 * can measure full width/height. Off-screen + opacity:0 hosts often clip
 * wide tables or tall multi-page content.
 */
function mountCaptureClone(element) {
  const host = document.createElement('div')
  host.setAttribute('data-pdf-capture-host', 'true')
  host.style.cssText = [
    'position:fixed',
    'left:0',
    'top:0',
    'z-index:-1',
    'opacity:1',
    'pointer-events:none',
    'background:#ffffff',
    'overflow:visible',
  ].join(';')

  const clone = element.cloneNode(true)
  clone.style.position = 'static'
  clone.style.left = 'auto'
  clone.style.top = 'auto'
  clone.style.opacity = '1'
  clone.style.visibility = 'visible'
  clone.style.pointerEvents = 'none'
  clone.style.maxWidth = 'none'
  clone.style.overflow = 'visible'
  host.appendChild(clone)
  document.body.appendChild(host)

  return { host, clone }
}

/**
 * Renders a printable DOM node to PDF. Returns the blob so the caller can
 * preview it in-app; optionally also triggers a download / new-tab open.
 */
export async function exportReport({ element, filename, openInNewTab = false, download = false }) {
  const { default: html2pdf } = await import('html2pdf.js')
  const { host, clone } = mountCaptureClone(element)

  await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)))

  try {
    const width = Math.max(clone.scrollWidth, clone.offsetWidth, 1100)
    const height = Math.max(clone.scrollHeight, clone.offsetHeight, 1)

    const blob = await html2pdf()
      .set({
        margin: [10, 10, 10, 10],
        filename,
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: {
          scale: 2,
          useCORS: true,
          logging: false,
          backgroundColor: '#ffffff',
          scrollX: 0,
          scrollY: 0,
          windowWidth: width,
          windowHeight: height,
          width,
          height,
        },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
        pagebreak: {
          // Honour CSS page-break rules on .table-container / .pdf-keep-together.
          // Never auto-split tables or inject thead mid-table via "before" selectors.
          mode: ['css', 'legacy'],
          avoid: ['.pdf-keep-together', '.table-container.pdf-keep-together', 'tr', 'img'],
        },
      })
      .from(clone)
      .outputPdf('blob')

    if (download) {
      triggerDownload(blob, filename)
    }

    if (openInNewTab) {
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank', 'noopener,noreferrer')
    }

    return { blob }
  } finally {
    host.remove()
  }
}

export async function exportExcel({ sheets, filename }) {
  const { default: ExcelJS } = await import('exceljs')
  const workbook = new ExcelJS.Workbook()
  sheets.forEach(({ name, columns, rows }) => {
    const worksheet = workbook.addWorksheet(name)
    worksheet.columns = columns
    worksheet.addRows(rows)
    worksheet.getRow(1).font = { bold: true }
  })

  const buffer = await workbook.xlsx.writeBuffer()
  const blob = new Blob([buffer], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })

  triggerDownload(blob, filename)

  return { blob }
}

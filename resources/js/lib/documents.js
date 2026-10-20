export function filenamePart(value) {
    return String(value || '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .toUpperCase();
}

export function documentFilename(facultyName, documentType, startDate, endDate, extension = 'pdf') {
    const name = filenamePart(facultyName) || 'FACULTY';
    const document = filenamePart(documentType) || 'DOCUMENT';
    const period = startDate
        ? `_${filenamePart(startDate)}${endDate && endDate !== startDate ? `_TO_${filenamePart(endDate)}` : ''}`
        : '';

    return `${name}_${document}${period}.${extension}`;
}

export function openPdfPrintWindow() {
    const popup = window.open('', '_blank');
    if (!popup) return null;
    popup.document.title = 'Preparing document';
    popup.document.body.innerHTML = '<p style="font:16px Arial,sans-serif;padding:24px">Preparing document for printing...</p>';
    return popup;
}

export function sendPdfToPrint(pdf, popup) {
    pdf.autoPrint();
    const url = URL.createObjectURL(pdf.output('blob'));
    popup.location.replace(url);
    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
}

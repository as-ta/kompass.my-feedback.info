// Druckvorlagen: QR-Code erzeugen, Überschrift/Text live übernehmen, Druckdialog öffnen.
(() => {
    const url = document.body.dataset.printUrl;
    const render = () => {
        if (typeof window.qrcode !== 'function' || !url) return;
        const qr = window.qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        const count = qr.getModuleCount();
        const size = count + 4;
        let path = '';
        for (let row = 0; row < count; row++) {
            for (let col = 0; col < count; col++) {
                if (qr.isDark(row, col)) path += `M${col + 2} ${row + 2}h1v1h-1z`;
            }
        }
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges" role="img" aria-label="QR-Code zur Befragung"><rect width="${size}" height="${size}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
        document.querySelectorAll('[data-print-qr]').forEach((box) => { box.innerHTML = svg; });
    };
    window.addEventListener('load', render);

    document.querySelectorAll('[data-print-input]').forEach((input) => {
        const targets = document.querySelectorAll(`[data-print-${input.dataset.printInput}]`);
        input.addEventListener('input', () => {
            targets.forEach((target) => { target.textContent = input.value; });
        });
    });
    document.querySelector('[data-print-button]')?.addEventListener('click', () => window.print());
})();

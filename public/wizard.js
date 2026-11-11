// Fortschrittsanzeige: Ein Formular, das gerade abgeschickt wird, zeigt einen Spinner statt des Buttons.
function showBusy(form, text) {
    form.classList.add('is-busy');
    const button = form.querySelector('button[type="submit"], button:not([type])');
    if (!form.querySelector('.busy-indicator')) {
        const indicator = document.createElement('p');
        indicator.className = 'busy-indicator';
        indicator.setAttribute('role', 'status');
        indicator.innerHTML = '<span class="spinner" aria-hidden="true"></span>';
        const label = document.createElement('span');
        label.textContent = text ?? '';
        indicator.append(label);
        (button ?? form.lastElementChild)?.after(indicator);
    }
}

// Formulare, die sich selbst abschicken (Anmeldung bestätigen, Rückkehr von PayPal): sofort Spinner, kein kurz aufblitzender Button.
const autoSubmitForm = document.querySelector('form[data-auto-submit]');
if (autoSubmitForm) {
    showBusy(autoSubmitForm, autoSubmitForm.dataset.busyText);
    autoSubmitForm.requestSubmit();
}

// Alle übrigen POST-Formulare: Button beim Absenden sperren und Spinner zeigen (verhindert auch Doppelklicks).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method !== 'post' || form.hasAttribute('data-auto-submit')) return;
    const button = event.submitter ?? form.querySelector('button[type="submit"]');
    if (button) {
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        // Erst nach dem Absenden sperren, damit der Browser den Klick noch auswertet.
        setTimeout(() => { button.disabled = true; }, 0);
    }
});
// Zurück-Taste aus dem Browser-Cache: Buttons wieder freigeben.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('button.is-loading').forEach((button) => {
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
        button.disabled = false;
    });
});

const acknowledgedNotices = new Set();
let noticeStorageAvailable = true;

function isNoticeAcknowledged(key) {
    if (!noticeStorageAvailable) return acknowledgedNotices.has(key);
    try {
        return sessionStorage.getItem(key) === '1';
    } catch {
        return acknowledgedNotices.has(key);
    }
}

function setNoticeAcknowledged(key, value) {
    if (value) {
        acknowledgedNotices.add(key);
    } else {
        acknowledgedNotices.delete(key);
    }
    try {
        if (value) {
            sessionStorage.setItem(key, '1');
        } else {
            sessionStorage.removeItem(key);
        }
    } catch {
        noticeStorageAvailable = false;
    }
}

function setupNotice(element) {
    const body = element.querySelector('[data-notice-body]');
    const confirm = element.querySelector('[data-notice-confirm]');
    const show = element.querySelector('[data-notice-show]');
    let key = element.dataset.notice;
    let visible = !element.hidden;

    function update(shouldShow, context = key) {
        visible = shouldShow;
        key = context;
        const acknowledged = isNoticeAcknowledged('kompass:notice:' + key);
        element.hidden = !visible;
        body.hidden = acknowledged;
        show.hidden = !acknowledged;
    }

    confirm.hidden = false;
    confirm.addEventListener('click', () => {
        setNoticeAcknowledged('kompass:notice:' + key, true);
        update(true);
        show.focus();
    });
    show.addEventListener('click', () => {
        setNoticeAcknowledged('kompass:notice:' + key, false);
        update(true);
        confirm.focus();
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) update(visible);
    });
    update(visible);
    return update;
}

const notices = new Map([...document.querySelectorAll('[data-notice]')].map(
    (element) => [element, setupNotice(element)],
));

document.querySelectorAll('[data-addons]').forEach((form) => {
    const checkboxes = [...form.querySelectorAll('input[type="checkbox"]')];
    const count = form.querySelector('[data-question-count]');
    const updateNotice = notices.get(form.querySelector('[data-notice]'));
    const base = Number(form.dataset.baseCount);

    function updateLength() {
        const selected = checkboxes.filter((checkbox) => checkbox.checked).map((checkbox) => checkbox.value);
        const extras = selected.length;
        count.textContent = `Insgesamt ${base + extras} Fragen`;
        updateNotice(extras >= 3 || base + extras >= 7, form.dataset.lengthKey + selected.join(','));
    }

    form.addEventListener('change', updateLength);
    updateLength();
});

document.querySelectorAll('[data-goals]').forEach((form) => {
    const boxes = [...form.querySelectorAll('input[type="checkbox"]')];
    const updateNotice = notices.get(form.querySelector('[data-notice]'));
    const error = form.querySelector('[data-goal-error]');

    function updateGoals() {
        const selected = boxes.filter((box) => box.checked);
        const combinedStaff = selected.length > 1 && selected.some((box) => box.value === 'employees');
        const problem = combinedStaff
            ? 'Mitarbeiter und Kunden sind unterschiedliche Zielgruppen. Bitte wählen Sie für Ihre Mitarbeiter eine eigene Befragung.'
            : selected.length > 3 ? 'Wählen Sie höchstens drei Themen.' : '';
        error.textContent = problem;
        error.hidden = !problem;
        updateNotice(selected.length >= 2 && !problem, 'goals:' + selected.map((box) => box.value).join(','));
        boxes[0].setCustomValidity(problem || (selected.length === 0 ? 'Wählen Sie mindestens ein Thema.' : ''));
    }

    form.addEventListener('change', updateGoals);
    updateGoals();
});

// PayPal-Buttons: PayPal öffnet sich im eigenen Fenster, unsere Seite bleibt stehen.
// Lädt das PayPal-Skript nicht, bleibt der bisherige Button mit Weiterleitung sichtbar.
const paypalBox = document.querySelector('[data-paypal-buttons]');
if (paypalBox) {
    const fallback = document.querySelector('[data-paypal-fallback]');
    const message = paypalBox.querySelector('[data-paypal-message]');
    const busy = paypalBox.querySelector('[data-paypal-busy]');
    let period = paypalBox.dataset.period;

    const say = (text) => {
        message.textContent = text;
        message.hidden = text === '';
    };
    const post = async (url, fields) => {
        const body = new FormData();
        body.append('csrf', paypalBox.dataset.csrf);
        body.append('poll', paypalBox.dataset.poll);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    };

    const sdk = document.createElement('script');
    sdk.src = 'https://www.paypal.com/sdk/js?' + new URLSearchParams({
        'client-id': paypalBox.dataset.clientId,
        currency: 'EUR',
        intent: 'capture',
        locale: 'de_DE',
        components: 'buttons',
    });
    sdk.async = true;
    sdk.addEventListener('load', () => {
        if (!window.paypal?.Buttons) return;
        window.paypal.Buttons({
            style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'pay', height: 45 },
            createOrder: async () => {
                say('');
                const data = await post(paypalBox.dataset.orderUrl, { period });
                if (data.orderId) {
                    period = String(data.period);
                    return data.orderId;
                }
                if (data.redirect) window.location.assign(data.redirect);
                throw new Error('Keine PayPal-Bestellung');
            },
            onApprove: async (data) => {
                busy.hidden = false;
                paypalBox.classList.add('is-busy');
                try {
                    const result = await post(paypalBox.dataset.captureUrl, { period, order: data.orderID });
                    window.location.assign(result.redirect);
                } catch {
                    busy.hidden = true;
                    paypalBox.classList.remove('is-busy');
                    say('Die Zahlung konnte gerade nicht bestätigt werden. Bitte laden Sie die Seite neu, um den Status zu prüfen.');
                }
            },
            onCancel: () => say('Sie haben das PayPal-Fenster geschlossen. Die Zahlung ist nicht erfolgt. Sie können es jederzeit erneut versuchen.'),
            onError: () => say('PayPal ist gerade nicht erreichbar. Bitte versuchen Sie es in einigen Minuten erneut.'),
        }).render(paypalBox.querySelector('[data-paypal-buttons-target]')).then(() => {
            paypalBox.hidden = false;
            if (fallback) fallback.hidden = true;
        }).catch(() => {});
    });
    document.head.append(sdk);
}

const copyPollLink = document.querySelector('[data-copy-poll-link]');
const pollLink = document.querySelector('#poll-public-url');
const pollCopyStatus = document.querySelector('[data-copy-poll-status]');

if (copyPollLink && pollLink && pollCopyStatus) {
    copyPollLink.hidden = false;
    copyPollLink.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(pollLink.value);
            pollCopyStatus.textContent = 'Link kopiert.';
        } catch {
            pollLink.focus();
            pollLink.select();
            pollCopyStatus.textContent = 'Der Link ist markiert. Kopieren Sie ihn über das Menü oder mit Strg+C.';
        }
    });
}

// Info-Tooltip zum PayPal-Testbetrieb: öffnet beim Überfahren mit der Maus, per Klick oder Tastatur weiterhin umschaltbar.
document.querySelectorAll('.payment-test-info').forEach((info) => {
    const summary = info.querySelector('summary');
    let closeTimer = null;
    let openedByHover = false;
    info.addEventListener('pointerenter', (event) => {
        if (event.pointerType !== 'mouse') return;
        clearTimeout(closeTimer);
        if (!info.open) {
            info.open = true;
            openedByHover = true;
        }
    });
    info.addEventListener('pointerleave', (event) => {
        if (event.pointerType !== 'mouse' || !openedByHover) return;
        closeTimer = setTimeout(() => {
            info.open = false;
            openedByHover = false;
        }, 200);
    });
    summary?.addEventListener('click', (event) => {
        // Ein Klick auf den bereits per Maus geöffneten Tooltip hält ihn offen, statt ihn zu schließen.
        if (openedByHover) {
            event.preventDefault();
            openedByHover = false;
        }
    });
});

// QR-Code zum Teilnahmelink: wird im Browser erzeugt (public/qrcode.js, MIT-Lizenz) und lässt sich als SVG oder PNG speichern.
window.addEventListener('load', () => {
    const box = document.querySelector('[data-poll-qr]');
    if (!box || typeof window.qrcode !== 'function') return;
    const qr = window.qrcode(0, 'M');
    qr.addData(box.dataset.url);
    qr.make();
    const count = qr.getModuleCount();
    const margin = 4;
    const size = count + margin * 2;
    let path = '';
    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (qr.isDark(row, col)) path += `M${col + margin} ${row + margin}h1v1h-1z`;
        }
    }
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges">`
        + `<rect width="${size}" height="${size}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
    box.querySelector('[data-poll-qr-image]').innerHTML = svg;

    // Dateiname aus dem Titel, z. B. "qr-befragung-zur-kuendigeranalyse".
    const slug = (box.dataset.file || 'befragung').toLowerCase()
        .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
        .normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60) || 'befragung';
    box.dataset.file = 'qr-' + slug;
    const svgLink = box.querySelector('[data-poll-qr-svg]');
    svgLink.href = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
    svgLink.download = box.dataset.file + '.svg';

    // PNG in 1200 Pixeln, scharf genug für Drucksachen bis etwa 10 cm.
    const canvas = document.createElement('canvas');
    const scale = Math.floor(1200 / size);
    canvas.width = canvas.height = size * scale;
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.fillStyle = '#000';
    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (qr.isDark(row, col)) context.fillRect((col + margin) * scale, (row + margin) * scale, scale, scale);
        }
    }
    const pngLink = box.querySelector('[data-poll-qr-png]');
    canvas.toBlob((blob) => {
        if (!blob) return;
        pngLink.href = URL.createObjectURL(blob);
        pngLink.download = box.dataset.file + '.png';
    });
    box.hidden = false;
});

// Teilnahme: Bei Auswahlfragen geht es nach dem Antippen automatisch zur nächsten Frage.
document.querySelectorAll('form[data-step-form]').forEach((form) => {
    form.addEventListener('change', (event) => {
        if (!(event.target instanceof HTMLInputElement) || event.target.type !== 'radio') return;
        clearTimeout(form.autoAdvance);
        form.autoAdvance = setTimeout(() => form.requestSubmit(), 280);
    });
});

// Erscheinungsbild: Live-Vorschau beim Wählen von Logo und Farben
const brandingForm = document.querySelector('[data-branding-form]');
const brandingPreview = document.querySelector('[data-branding-preview]');
if (brandingForm && brandingPreview) {
    const hex = /^#[0-9a-f]{6}$/i;
    const channels = (color) => color.slice(1).match(/../g).map((part) => parseInt(part, 16));
    const luminance = (color) => {
        const [r, g, b] = channels(color).map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const contrast = (a, b) => { const la = luminance(a), lb = luminance(b); return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05); };
    const mix = (a, b, t) => '#' + channels(a).map((v, i) => Math.round(v + (channels(b)[i] - v) * t).toString(16).padStart(2, '0')).join('');

    const value = (key) => {
        const field = brandingForm.querySelector(`[data-color-field="${key}"]`);
        const text = field.querySelector('[data-color-text]');
        const isDefault = field.querySelector('[data-color-default]').checked;
        return isDefault || !hex.test(text.value.trim()) ? null : text.value.trim().toLowerCase();
    };
    const update = () => {
        const primary = value('primary');
        const accent = value('accent');
        const style = (brandingPreview.querySelector('.branding-phone') ?? brandingPreview).style;
        ['--green', '--accent', '--brand-on', '--brand-tint'].forEach((name) => style.removeProperty(name));
        if (primary) {
            style.setProperty('--green', primary);
            style.setProperty('--brand-on', contrast(primary, '#ffffff') >= 4.5 ? '#ffffff' : '#192921');
            style.setProperty('--brand-tint', mix(primary, '#ffffff', 0.9));
        }
        if (accent || primary) style.setProperty('--accent', accent || primary);
        brandingForm.querySelectorAll('[data-color-field]').forEach((field) => {
            const color = value(field.dataset.colorField);
            field.querySelector('[data-contrast-warning]').hidden = !color || contrast(color, '#ffffff') >= 1.6;
            field.querySelectorAll('[data-color-picker], [data-color-text]').forEach((input) => { input.disabled = field.querySelector('[data-color-default]').checked; });
        });
    };
    brandingForm.querySelectorAll('[data-color-field]').forEach((field) => {
        const picker = field.querySelector('[data-color-picker]');
        const text = field.querySelector('[data-color-text]');
        picker.addEventListener('input', () => { text.value = picker.value; update(); });
        text.addEventListener('input', () => { if (hex.test(text.value.trim())) picker.value = text.value.trim(); update(); });
        field.querySelector('[data-color-default]').addEventListener('change', update);
    });
    // Neues Logo sofort in der Vorschau zeigen
    const logoInput = brandingForm.querySelector('[data-logo-input]');
    const previewLogo = brandingPreview.querySelector('[data-preview-logo]');
    const previewName = brandingPreview.querySelector('[data-preview-name]');
    const originalLogo = previewLogo.getAttribute('src');
    const showLogo = (src) => {
        previewLogo.hidden = !src;
        if (src) previewLogo.src = src;
        if (previewName) previewName.hidden = !!src || previewName.textContent.trim() === '';
    };
    logoInput?.addEventListener('change', () => {
        const file = logoInput.files?.[0];
        showLogo(file ? URL.createObjectURL(file) : originalLogo);
    });
    brandingForm.querySelector('[data-remove-logo]')?.addEventListener('change', (event) => {
        showLogo(event.target.checked ? '' : originalLogo);
    });
    update();
}

// PayPal-Abo: eigener PayPal-Namensraum, weil Abos andere SDK-Parameter brauchen als Einmalzahlungen.
const subscriptionBox = document.querySelector('[data-paypal-subscription]');
if (subscriptionBox) {
    const message = subscriptionBox.querySelector('[data-paypal-message]');
    const busy = subscriptionBox.querySelector('[data-paypal-busy]');
    const fallback = document.querySelector('[data-paypal-subscription-fallback]');
    const say = (text) => { message.textContent = text; message.hidden = text === ''; };
    const post = async (url, fields) => {
        const body = new FormData();
        body.append('csrf', subscriptionBox.dataset.csrf);
        body.append('poll', subscriptionBox.dataset.poll);
        Object.entries(fields).forEach(([key, value]) => body.append(key, value));
        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    };
    const sdk = document.createElement('script');
    sdk.src = 'https://www.paypal.com/sdk/js?' + new URLSearchParams({
        'client-id': subscriptionBox.dataset.clientId,
        currency: 'EUR',
        intent: 'subscription',
        vault: 'true',
        locale: 'de_DE',
        components: 'buttons',
    });
    sdk.dataset.namespace = 'paypalSubscriptions';
    sdk.async = true;
    sdk.addEventListener('load', () => {
        const sdkApi = window.paypalSubscriptions;
        if (!sdkApi?.Buttons) return;
        sdkApi.Buttons({
            style: { layout: 'vertical', color: 'blue', shape: 'rect', label: 'subscribe', height: 45 },
            createSubscription: async (data, actions) => {
                say('');
                const plan = await post(subscriptionBox.dataset.planUrl, {});
                if (plan.redirect) { window.location.assign(plan.redirect); throw new Error('Weiterleitung'); }
                const details = {
                    plan_id: plan.planId,
                    custom_id: plan.customId,
                    application_context: { shipping_preference: 'NO_SHIPPING', brand_name: 'myFeedback Kompass', user_action: 'SUBSCRIBE_NOW' },
                };
                if (plan.startTime) details.start_time = plan.startTime;
                return actions.subscription.create(details);
            },
            onApprove: async (data) => {
                busy.hidden = false;
                try {
                    const result = await post(subscriptionBox.dataset.subscribeUrl, { subscription: data.subscriptionID });
                    window.location.assign(result.redirect);
                } catch {
                    busy.hidden = true;
                    say('Das Abo ist bei PayPal angelegt, konnte hier aber noch nicht bestätigt werden. Bitte laden Sie die Seite in einigen Minuten neu.');
                }
            },
            onCancel: () => say('Sie haben das PayPal-Fenster geschlossen. Es wurde kein Abo abgeschlossen.'),
            onError: () => say('PayPal ist gerade nicht erreichbar. Bitte versuchen Sie es später erneut.'),
        }).render(subscriptionBox.querySelector('[data-paypal-subscription-target]')).then(() => {
            subscriptionBox.hidden = false;
            if (fallback) fallback.hidden = true;
        }).catch(() => {});
    });
    sdk.addEventListener('error', () => { if (fallback) fallback.textContent = 'PayPal konnte nicht geladen werden. Bitte prüfen Sie Werbeblocker oder versuchen Sie es später erneut.'; });
    document.head.append(sdk);
}

// Teilen-Paket: Kacheln je Einladungsweg, editierbare Texte mit Kopieren, E-Mail und WhatsApp.
const shareKit = document.querySelector('[data-share-kit]');
if (shareKit) {
    // Ohne JavaScript stehen alle Wege untereinander. Mit JavaScript: Kacheln, Inhalt öffnet sich als Dialog.
    const tiles = [...shareKit.querySelectorAll('[data-share-tab]')];
    if (typeof HTMLDialogElement === 'function') {
        shareKit.querySelector('[data-share-tabs]').hidden = false;
        shareKit.classList.add('share-kit--dialogs');
        shareKit.querySelectorAll('[data-share-panel]').forEach((panel) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'share-dialog';
            dialog.id = panel.id;
            panel.removeAttribute('id');
            const title = panel.querySelector('h4');
            if (title) dialog.setAttribute('aria-labelledby', title.id);
            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'share-dialog-close';
            close.setAttribute('aria-label', 'Schließen');
            close.textContent = '×';
            close.addEventListener('click', () => dialog.close());
            const footer = document.createElement('div');
            footer.className = 'share-dialog-footer';
            const done = document.createElement('button');
            done.type = 'button';
            done.className = 'secondary';
            done.textContent = 'Schließen';
            done.addEventListener('click', () => dialog.close());
            footer.append(done);
            panel.replaceWith(dialog);
            dialog.append(close, panel, footer);
            // Klick auf den abgedunkelten Hintergrund schließt
            dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
        });
        tiles.forEach((tile) => {
            const dialog = document.getElementById(tile.getAttribute('aria-controls'));
            tile.addEventListener('click', () => {
                dialog.showModal();
                dialog.scrollTop = 0;
            });
            dialog.addEventListener('close', () => tile.focus());
        });
    }

    shareKit.querySelectorAll('[data-share-text]').forEach((box) => {
        const body = box.querySelector('[data-share-body]');
        const subject = box.querySelector('[data-share-subject]');
        const copy = box.querySelector('[data-share-copy]');
        const mail = box.querySelector('[data-share-mail]');
        const whatsapp = box.querySelector('[data-share-whatsapp]');
        const status = box.querySelector('[data-share-status]');
        const update = () => {
            if (mail) mail.href = 'mailto:?subject=' + encodeURIComponent(subject ? subject.value : '') + '&body=' + encodeURIComponent(body.value.replace(/\r?\n/g, '\r\n'));
            if (whatsapp) whatsapp.href = 'https://wa.me/?text=' + encodeURIComponent(body.value);
            status.textContent = '';
        };
        body.addEventListener('input', update);
        subject?.addEventListener('input', update);
        if (copy && navigator.clipboard) {
            copy.hidden = false;
            copy.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(body.value);
                    status.textContent = subject ? 'Text kopiert. Den Betreff finden Sie darüber.' : 'Text kopiert.';
                } catch {
                    body.focus();
                    body.select();
                    status.textContent = 'Der Text ist markiert. Kopieren Sie ihn mit Strg+C.';
                }
            });
        }
    });
}

// Tablet-Modus: nach dem Danke automatisch zur nächsten Person, bei längerer Pause neu beginnen.
const tabletNext = document.querySelector('[data-tablet-next]');
if (tabletNext) {
    const label = tabletNext.querySelector('[data-tablet-countdown]');
    let seconds = 8;
    const tick = () => {
        if (seconds <= 0) { window.location.replace(tabletNext.dataset.tabletNext); return; }
        label.textContent = `Gleich geht es für die nächste Person los (${seconds} s).`;
        seconds -= 1;
        setTimeout(tick, 1000);
    };
    tick();
}
const tabletIdle = document.querySelector('[data-tablet-idle]');
const tabletDialog = document.querySelector('[data-tablet-idle-dialog]');
if (tabletIdle && tabletDialog) {
    const idleAfter = 90;     // Sekunden ohne Eingabe bis zur Nachfrage
    const graceSeconds = 20;  // Sekunden bis zum Neustart
    let idleTimer = null;
    let graceTimer = null;
    const output = tabletDialog.querySelector('[data-tablet-idle-seconds]');
    const reset = () => window.location.replace(tabletIdle.dataset.resetUrl);
    const ask = () => {
        let left = graceSeconds;
        output.textContent = left;
        tabletDialog.hidden = false;
        tabletDialog.querySelector('[data-tablet-idle-continue]').focus();
        graceTimer = setInterval(() => {
            left -= 1;
            output.textContent = left;
            if (left <= 0) { clearInterval(graceTimer); reset(); }
        }, 1000);
    };
    const activity = () => {
        if (!tabletDialog.hidden) return;
        clearTimeout(idleTimer);
        idleTimer = setTimeout(ask, idleAfter * 1000);
    };
    tabletDialog.querySelector('[data-tablet-idle-continue]').addEventListener('click', () => {
        clearInterval(graceTimer);
        tabletDialog.hidden = true;
        activity();
    });
    ['pointerdown', 'keydown', 'input', 'scroll', 'touchstart'].forEach((type) => document.addEventListener(type, activity, { passive: true }));
    activity();
}

// Kleine QR-Codes (z. B. Tablet-Link zum Abscannen mit dem Tablet)
window.addEventListener('load', () => {
    if (typeof window.qrcode !== 'function') return;
    document.querySelectorAll('[data-qr-small]').forEach((box) => {
        const qr = window.qrcode(0, 'M');
        qr.addData(box.dataset.url);
        qr.make();
        const count = qr.getModuleCount();
        const size = count + 8;
        let path = '';
        for (let row = 0; row < count; row++) {
            for (let col = 0; col < count; col++) {
                if (qr.isDark(row, col)) path += `M${col + 4} ${row + 4}h1v1h-1z`;
            }
        }
        box.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${size} ${size}" shape-rendering="crispEdges"><rect width="${size}" height="${size}" fill="#fff"/><path d="${path}" fill="#000"/></svg>`;
        box.hidden = false;
    });
});

// Konto-Menü im Header
document.querySelectorAll('[data-account-menu]').forEach((menu) => {
    const button = menu.querySelector('[data-account-menu-button]');
    const panel = menu.querySelector('[data-account-menu-panel]');
    const set = (open) => {
        panel.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    button.addEventListener('click', () => set(panel.hidden));
    document.addEventListener('click', (event) => { if (!menu.contains(event.target)) set(false); });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !panel.hidden) { set(false); button.focus(); }
    });
});

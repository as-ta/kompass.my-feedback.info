const loginVerifyForm = document.querySelector('[data-login-verify]');
if (loginVerifyForm) loginVerifyForm.requestSubmit();

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

const paypalReturnForm = document.querySelector('[data-paypal-return]');
if (paypalReturnForm) paypalReturnForm.requestSubmit();

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

const element = id => document.getElementById(id);
const login = element('login');
const reviewer = element('reviewer');
const workspace = element('workspace');
const switchReviewer = element('switch-reviewer');
const previous = element('previous');
const next = element('next');
const grades = [...document.querySelectorAll('[data-value]')];
const kinds = [...document.querySelectorAll('[data-queue]')];
const priorities = [...document.querySelectorAll('[data-priority]')];
const image = element('image');
const imageTrigger = element('image-trigger');
const imageStatus = element('image-status');
const imageAction = element('image-action');
const zoom = element('zoom');
const zoomImage = element('zoom-image');
const zoomViewport = element('zoom-viewport');
const storageKey = ['48083', '48084'].includes(location.port)
    ? 'library-stroll-48a31-reviewer'
    : 'library-stroll-reviewer-' + location.port;

let state;
let busy = false;
let imageReady = false;
let scale = 1;
let unified = false;

const reviewLabels = { 2: 'Полное совпадение', 1: 'Частичное совпадение', 0: 'Нерелевантное изображение', unsure: 'Не уверен' };
const conditionLabels = { 2: 'Условие выполнено', 0: 'Условие не выполнено', unsure: 'Невозможно определить' };

function displayError(message) {
    const error = element('error');
    error.textContent = message ?? '';
    error.hidden = !message;
}

async function request(path, data) {
    const response = await fetch(path, data === undefined ? undefined : {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error);
    return result;
}

function updateControls() {
    login.querySelector('button').disabled = busy;
    switchReviewer.disabled = busy;
    previous.disabled = busy || !state || state.position === 0;
    next.disabled = busy || !state || state.position >= state.total - 1;
    imageTrigger.disabled = busy || !imageReady;
    for (const grade of grades) grade.disabled = busy || !imageReady;
    for (const button of kinds) button.disabled = busy;
    for (const button of priorities) button.disabled = busy || !state?.progress?.[button.dataset.priority]?.total;
}

function showImage(ready, message) {
    imageReady = ready;
    image.hidden = !ready;
    imageStatus.hidden = ready;
    imageStatus.textContent = message;
    imageAction.hidden = !ready;
    updateControls();
}

function render(data) {
    state = data;
    login.hidden = true;
    workspace.hidden = false;
    element('test-notice').hidden = !data.test_mode;
    switchReviewer.hidden = false;
    switchReviewer.textContent = data.reviewer_id + ' · Сменить';
    element('position').textContent = `Пара ${data.position + 1} из ${data.total}`;
    element('count').textContent = `Оценено ${data.assessed} · Неопределённых ${data.unsure} · Осталось ${data.remaining}`;
    element('progress').max = data.total;
    element('progress').value = data.assessed + data.unsure;
    element('query').textContent = data.pair.text;
    element('query').lang = data.pair.lang;
    element('language').textContent = data.pair.lang.toUpperCase();
    element('criterion').textContent = data.pair.criterion ?? '';
    element('criterion').hidden = !data.pair.criterion;
    const conditions = data.kind === 'conditions';
    element('query-type').textContent = conditions ? 'Условие' : 'Запрос';
    element('grade-title').textContent = conditions ? 'Выполнено ли условие?' : 'Насколько подходит изображение?';
    element('grades').setAttribute('aria-label', conditions ? 'Выполнение условия' : 'Релевантность');
    element('group-position').textContent = data.group ? `Изображение ${data.group.position} из ${data.group.total}` : '';
    element('review-switch').hidden = !data.kind;
    element('priorities').hidden = !data.progress;
    for (const button of kinds) button.setAttribute('aria-pressed', String(button.dataset.queue === data.kind));
    for (const button of priorities) {
        const priority = button.dataset.priority;
        const progress = data.progress?.[priority];
        button.setAttribute('aria-pressed', String(priority === data.priority));
        button.textContent = progress ? `${priority} · осталось ${progress.remaining}/${progress.total}` : priority;
    }
    element('download').href = sessionUrl('/api/export', data.reviewer_id, data.kind);
    if (image.getAttribute('src') !== data.pair.image_url) {
        showImage(false, 'Загрузка изображения…');
        image.src = data.pair.image_url;
    }
    for (const grade of grades) {
        const value = grade.dataset.value;
        grade.setAttribute('aria-pressed', String(value === String(data.rating)));
        const label = (conditions ? conditionLabels : reviewLabels)[value];
        grade.hidden = label === undefined;
        grade.lastElementChild.textContent = label ?? '';
    }
    updateControls();
}

image.addEventListener('load', () => showImage(true, ''));
image.addEventListener('error', () => showImage(false, 'Изображение недоступно. Попробуйте обновить страницу.'));

function sessionUrl(path, id, kind) {
    const params = new URLSearchParams({ reviewer: id });
    if (kind) params.set('queue', kind);
    return path + '?' + params;
}

async function openSession(id, kind) {
    if (busy) return;
    busy = true;
    updateControls();
    displayError();
    try {
        render(await request(sessionUrl('/api/session', id, kind)));
        try {
            localStorage.setItem(storageKey, state.reviewer_id);
            if (state.kind) localStorage.setItem(storageKey + '-kind', state.kind);
        } catch { /* The server still stores progress. */ }
        element('save-status').textContent = 'Ответы сохраняются автоматически';
    } catch (error) {
        displayError(error.message || 'Не удалось открыть сессию.');
    } finally {
        busy = false;
        updateControls();
    }
}

async function save(changes) {
    if (busy || !state || (changes.position !== undefined && (changes.position < 0 || changes.position >= state.total))) return;
    busy = true;
    updateControls();
    displayError();
    element('save-status').textContent = 'Сохранение…';
    try {
        render(await request('/api/session', { reviewer_id: state.reviewer_id, queue: state.kind, ...changes }));
        element('save-status').textContent = state.assessed + state.unsure === state.total ? 'Все пары просмотрены' : 'Сохранено';
    } catch (error) {
        element('save-status').textContent = 'Изменение не сохранено';
        displayError(error.message || 'Не удалось сохранить. Повторите действие.');
    } finally {
        busy = false;
        updateControls();
    }
}

function grade(value) {
    if (!imageReady || !state || busy || (state.kind === 'conditions' && value === 1)) return;
    const position = state.rating === null ? Math.min(state.position + 1, state.total - 1) : state.position;
    save({ position, answer: { position: state.position, value } });
}

login.addEventListener('submit', event => {
    event.preventDefault();
    openSession(reviewer.value.trim().toLowerCase(), unified ? 'relevance' : undefined);
});
switchReviewer.addEventListener('click', () => {
    reviewer.value = state.reviewer_id;
    state = undefined;
    try { localStorage.removeItem(storageKey); } catch { /* Browser storage is optional. */ }
    switchReviewer.hidden = true;
    workspace.hidden = true;
    login.hidden = false;
    displayError();
    reviewer.focus();
});
previous.addEventListener('click', () => save({ position: state.position - 1 }));
next.addEventListener('click', () => save({ position: state.position + 1 }));
for (const button of kinds) {
    button.addEventListener('click', () => {
        if (state && state.kind !== button.dataset.queue) openSession(state.reviewer_id, button.dataset.queue);
    });
}
for (const button of priorities) button.addEventListener('click', () => save({ priority: button.dataset.priority }));
for (const button of grades) {
    button.addEventListener('click', () => grade(button.dataset.value === 'unsure' ? 'unsure' : Number(button.dataset.value)));
}

function resizeZoom(nextScale) {
    scale = nextScale;
    zoomImage.style.width = `${Math.round(image.naturalWidth * scale)}px`;
}

function fitZoom() {
    resizeZoom(Math.min((zoomViewport.clientWidth - 16) / image.naturalWidth,
        (zoomViewport.clientHeight - 16) / image.naturalHeight, 1));
    zoomViewport.scrollTo(0, 0);
}

imageTrigger.addEventListener('click', () => {
    if (!imageReady) return;
    zoomImage.src = image.src;
    zoom.showModal();
    fitZoom();
});
element('zoom-out').addEventListener('click', () => resizeZoom(scale / 1.35));
element('zoom-fit').addEventListener('click', fitZoom);
element('zoom-in').addEventListener('click', () => resizeZoom(Math.min(scale * 1.35, 3)));
window.addEventListener('keydown', event => {
    if (!state || busy || event.altKey || event.ctrlKey || event.metaKey || event.repeat
        || event.target?.matches?.('input, textarea, [contenteditable]')) return;
    if (zoom.open) {
        if (event.key === '+' || event.key === '=') element('zoom-in').click();
        else if (event.key === '-') element('zoom-out').click();
        else return;
        event.preventDefault();
        return;
    }
    if (/^[012]$/.test(event.key)) grade(Number(event.key));
    else if (event.key === '?' || ['n', 'н'].includes(event.key.toLowerCase())) grade('unsure');
    else if (event.key === 'ArrowLeft') save({ position: state.position - 1 });
    else if (event.key === 'ArrowRight') save({ position: state.position + 1 });
    else if (['z', 'я'].includes(event.key.toLowerCase())) imageTrigger.click();
    else return;
    event.preventDefault();
});

request('/api/info').then(info => {
    element('test-notice').hidden = !info.test_mode;
    unified = Array.isArray(info.queues);
    let last;
    let kind;
    try {
        last = localStorage.getItem(storageKey);
        const saved = localStorage.getItem(storageKey + '-kind');
        if (info.queues?.includes(saved)) kind = saved;
    } catch { /* Browser storage is optional. */ }
    if (last) {
        reviewer.value = last;
        openSession(last, kind);
    }
}).catch(error => displayError(error.message));

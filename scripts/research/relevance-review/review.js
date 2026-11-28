const element = id => document.getElementById(id);
const login = element('login');
const reviewer = element('reviewer');
const workspace = element('workspace');
const switchReviewer = element('switch-reviewer');
const previous = element('previous');
const next = element('next');
const grades = [...document.querySelectorAll('[data-value]')];
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
    element('count').textContent = `Оценено ${data.assessed} · Не уверен ${data.unsure}`;
    element('progress').max = data.total;
    element('progress').value = data.assessed + data.unsure;
    element('query').textContent = data.pair.text;
    element('query').lang = data.pair.lang;
    element('language').textContent = data.pair.lang.toUpperCase();
    element('criterion').textContent = data.pair.criterion ?? '';
    element('criterion').hidden = !data.pair.criterion;
    element('download').href = '/api/export?reviewer=' + encodeURIComponent(data.reviewer_id);
    if (image.getAttribute('src') !== data.pair.image_url) {
        showImage(false, 'Загрузка изображения…');
        image.src = data.pair.image_url;
    }
    for (const grade of grades) grade.setAttribute('aria-pressed', String(grade.dataset.value === String(data.rating)));
    updateControls();
}

image.addEventListener('load', () => showImage(true, ''));
image.addEventListener('error', () => showImage(false, 'Изображение недоступно. Попробуйте обновить страницу.'));

async function openSession(id) {
    if (busy) return;
    busy = true;
    updateControls();
    displayError();
    try {
        render(await request('/api/session?reviewer=' + encodeURIComponent(id)));
        try { localStorage.setItem(storageKey, state.reviewer_id); } catch { /* The server still stores progress. */ }
        element('save-status').textContent = 'Ответы сохраняются автоматически';
    } catch (error) {
        displayError(error.message || 'Не удалось открыть сессию.');
    } finally {
        busy = false;
        updateControls();
    }
}

async function save(position, answer) {
    if (busy || !state || position < 0 || position >= state.total) return;
    busy = true;
    updateControls();
    displayError();
    element('save-status').textContent = 'Сохранение…';
    try {
        render(await request('/api/session', { reviewer_id: state.reviewer_id, position, answer }));
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
    if (!imageReady || !state || busy) return;
    const position = state.rating === null ? Math.min(state.position + 1, state.total - 1) : state.position;
    save(position, { position: state.position, value });
}

login.addEventListener('submit', event => {
    event.preventDefault();
    openSession(reviewer.value.trim().toLowerCase());
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
previous.addEventListener('click', () => save(state.position - 1));
next.addEventListener('click', () => save(state.position + 1));
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
    if (!state || zoom.open || busy || event.altKey || event.ctrlKey || event.metaKey || event.repeat) return;
    if (/^[012]$/.test(event.key)) grade(Number(event.key));
    else if (event.key === '?' || event.key === 'н') grade('unsure');
    else if (event.key === 'ArrowLeft') save(state.position - 1);
    else if (event.key === 'ArrowRight') save(state.position + 1);
    else return;
    event.preventDefault();
});

request('/api/info').then(info => {
    element('test-notice').hidden = !info.test_mode;
    let last;
    try { last = localStorage.getItem(storageKey); } catch { /* Browser storage is optional. */ }
    if (last) {
        reviewer.value = last;
        openSession(last);
    }
}).catch(error => displayError(error.message));

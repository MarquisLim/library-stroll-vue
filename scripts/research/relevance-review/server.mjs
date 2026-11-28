import { createHash, randomUUID } from 'node:crypto';
import { createReadStream, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { loadUnified, priorities, unifiedDirectory } from './unified-data.mjs';

const repo = fileURLToPath(new URL('../../../', import.meta.url));
const stage = join(repo, 'storage/app/ai-stage-04-08');
const workspace = join(stage, '4.8a-3-1', 'human-review');
const methods = ['A', 'B', 'C', 'D'];
const pairKey = ({ query_id, artwork_id }) => `${query_id}:${artwork_id}`;
const pairOrder = (a, b) => a.query_id - b.query_id || a.artwork_id - b.artwork_id;
const hash = bytes => createHash('sha256').update(bytes).digest('hex');
const readJson = path => JSON.parse(readFileSync(path, 'utf8'));
const isRating = value => [0, 1, 2, 'unsure'].includes(value);

function frozenJson(dir, name, hashes) {
    const bytes = readFileSync(join(dir, name));
    if (hash(bytes) !== hashes[name]) throw new Error(`Изменился исходный файл ${name}`);
    return JSON.parse(bytes);
}

export function loadQueue() {
    const hashes = readJson(join(stage, 'checksums.json')).files;
    const resultsDir = join(stage, '4.8a-2');
    const resultHashes = readJson(join(resultsDir, 'checksums.json'));
    if (hash(readFileSync(join(stage, 'checksums.json'))) !== resultHashes.locked_input_manifest_sha256) {
        throw new Error('План и выдача относятся к разным версиям исследования.');
    }
    const plan = frozenJson(stage, 'query-plan.json', hashes);
    const queries = new Map(plan.map(query => [query.id, { text: query.text, lang: query.lang }]));
    const images = new Map(frozenJson(stage, 'dataset-images.json', hashes).map(image => [image.artwork_id, image]));
    const oldLabels = frozenJson(stage, 'qrels.json', hashes);
    const disputes = frozenJson(stage, 'review-needed.json', hashes);
    const pool = frozenJson(resultsDir, 'review-pool.json', resultHashes.files);
    const rankings = frozenJson(resultsDir, 'rankings.json', resultHashes.files).queries;
    if (queries.size !== 40 || images.size !== 100 || oldLabels.length !== 173 || disputes.length !== 16
        || rankings.length !== 40 || rankings.some((ranked, index) => ranked.id !== plan[index].id)) {
        throw new Error('Неожиданный состав исходного исследования.');
    }

    const poolKeys = new Set(pool.map(pairKey));
    const topFive = new Map();
    const topTen = new Set();
    for (const query of rankings) {
        for (const method of methods) {
            for (const { id, rank } of query.methods[method].results.slice(0, 10)) {
                const pair = { query_id: query.id, artwork_id: id };
                const key = pairKey(pair);
                topTen.add(key);
                if (rank <= 5) topFive.set(key, pair);
            }
        }
    }
    if (poolKeys.size !== 563 || pool.length !== 563 || topTen.size !== 563
        || [...topTen].some(key => !poolKeys.has(key))) throw new Error('Топ-10 не совпадает с сохранённым пулом.');

    const outside = new Map();
    for (const { query_id, artwork_id } of [...oldLabels, ...disputes]) {
        const pair = { query_id, artwork_id };
        if (!poolKeys.has(pairKey(pair))) outside.set(pairKey(pair), pair);
    }
    const groups = [Array.from(topFive.values()), pool.filter(pair => !topFive.has(pairKey(pair))), Array.from(outside.values())];
    if (groups.some((group, index) => group.length !== [300, 263, 44][index])) {
        throw new Error('Ожидалось 300, 263 и 44 пары соответственно.');
    }

    const originals = realpathSync(join(repo, 'storage/app/ai-stage-04-04'));
    const imageFiles = new Map();
    for (const image of images.values()) {
        const path = realpathSync(resolve(originals, image.path));
        const name = relative(originals, path);
        if (name === '..' || name.startsWith(`..${sep}`) || !path.startsWith(`${originals}${sep}`)
            || hash(readFileSync(path)) !== image.sha256) throw new Error(`Изменился оригинал ${image.artwork_id}`);
        imageFiles.set(image.artwork_id, path);
    }
    const pairs = groups.flatMap(group => group.sort(pairOrder)).map(({ query_id, artwork_id }) => {
        if (!queries.has(query_id) || !imageFiles.has(artwork_id)) throw new Error('В очереди есть неизвестная пара.');
        return { query_id, artwork_id, query: queries.get(query_id), path: imageFiles.get(artwork_id) };
    });
    return { pairs, fingerprint: hash(JSON.stringify({ order: pairs.map(pairKey), hashes })) };
}

export function loadCatalogQueue(directory, split) {
    if (!['evaluation', 'regression', 'regression-1c', 'regression-1d1'].includes(split)) throw new Error('Неизвестная очередь каталога.');
    const queueBytes = readFileSync(join(directory, 'review-queue-' + split + '.json'));
    const root = realpathSync(join(directory, 'review-images'));
    const seen = new Set();
    const verified = new Map();
    const pairs = JSON.parse(queueBytes).map(({ query_id, artwork_id, query, sha256 }) => {
        if (!/^(?:G)?[RE]\d{2}$/.test(query_id) || !Number.isSafeInteger(artwork_id) || artwork_id <= 0
            || !query || typeof query.text !== 'string' || typeof query.criterion !== 'string'
            || !['ru', 'en'].includes(query.lang) || !/^[0-9a-f]{64}$/.test(sha256)) throw new Error('Некорректная пара в очереди.');
        const key = pairKey({ query_id, artwork_id });
        if (seen.has(key)) throw new Error('Повтор пары в очереди.');
        seen.add(key);
        const path = realpathSync(join(root, artwork_id + '.jpg'));
        if (!path.startsWith(root + sep)) throw new Error('Изображение вне каталога.');
        if (!verified.has(path)) verified.set(path, hash(readFileSync(path)));
        if (verified.get(path) !== sha256) throw new Error(`Изменилось изображение ${artwork_id}`);
        return { query_id, artwork_id, query: { text: query.text, lang: query.lang, criterion: query.criterion }, path };
    });
    if (!pairs.length) throw new Error('Очередь пуста.');
    return { pairs, fingerprint: hash(queueBytes) };
}

class RequestError extends Error {
    constructor(status, message) {
        super(message);
        this.status = status;
    }
}

function reviewer(value) {
    if (typeof value !== 'string' || !/^[a-z0-9][a-z0-9_-]{0,39}$/.test(value)) {
        throw new RequestError(400, 'Введите от 1 до 40 латинских букв в нижнем регистре, цифр, дефисов или подчёркиваний.');
    }
    return value;
}

async function body(request) {
    if (request.headers['content-type']?.split(';')[0] !== 'application/json') throw new RequestError(415, 'Ожидается JSON.');
    const chunks = [];
    let size = 0;
    for await (const chunk of request) {
        size += chunk.length;
        if (size > 4096) throw new RequestError(413, 'Слишком большой запрос.');
        chunks.push(chunk);
    }
    try {
        const data = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        if (data && typeof data === 'object' && !Array.isArray(data)) return data;
    } catch {
        // The response below covers both malformed JSON and a payload of the wrong type.
    }
    throw new RequestError(400, 'Некорректный JSON.');
}

export function reviewServer({ queue, queues, stateDir, testMode = false }) {
    const source = testMode ? 'synthetic_test' : 'manual_ui';
    const positionError = () => new RequestError(400, 'Пара вне очереди.');
    const unified = queues !== undefined;
    const available = queues ?? { relevance: queue };
    const sections = new Map();
    if (unified) {
        for (const [kind, input] of Object.entries(available)) {
            const groups = new Map();
            const groupKey = pair => (pair.priority ?? '') + ':' + pair.group_id;
            for (const [index, pair] of input.pairs.entries()) {
                const key = groupKey(pair);
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(index);
            }
            const indexInfo = new Map();
            for (const group of groups.values()) {
                group.forEach((position, index) => indexInfo.set(position, { position: index + 1, total: group.length }));
            }
            sections.set(kind, indexInfo);
        }
    }

    function queueKind(value) {
        const kind = value ?? 'relevance';
        if (typeof kind !== 'string' || !Object.hasOwn(available, kind)) {
            throw new RequestError(400, 'Неизвестная очередь.');
        }
        return kind;
    }

    const statePath = (kind, id) => join(unified ? join(stateDir, kind) : stateDir, `reviewer-${id}.json`);
    const isPosition = (kind, position) => Number.isInteger(position) && position >= 0 && position < available[kind].pairs.length;

    function load(kind, id) {
        const { fingerprint } = available[kind];
        const file = statePath(kind, id);
        if (!existsSync(file)) return { schema_version: unified ? 2 : 1, reviewer_id: id, source,
            ...(unified ? { kind } : {}), queue_sha256: fingerprint, position: 0, ratings: {} };
        const state = readJson(file);
        if (state.queue_sha256 !== fingerprint || state.reviewer_id !== id || state.source !== source
            || (unified && (state.schema_version !== 2 || state.kind !== kind)) || !isPosition(kind, state.position)) {
            throw new RequestError(409, 'Сохранённая сессия относится к другой очереди или режиму.');
        }
        return state;
    }

    function save(kind, state) {
        mkdirSync(unified ? join(stateDir, kind) : stateDir, { recursive: true });
        const path = statePath(kind, state.reviewer_id);
        const scratch = `${path}.${randomUUID()}.tmp`;
        try {
            writeFileSync(scratch, JSON.stringify(state, null, 2) + '\n', { flag: 'wx' });
            renameSync(scratch, path);
        } finally {
            rmSync(scratch, { force: true });
        }
    }

    function current(kind, state) {
        const { pairs } = available[kind];
        const values = Object.values(state.ratings);
        const { position } = state;
        const pair = pairs[position];
        const progress = unified && kind === 'relevance' ? Object.fromEntries(priorities.map(priority => {
            const positions = pairs.flatMap((item, index) => item.priority === priority ? [index] : []);
            return [priority, { total: positions.length,
                remaining: positions.filter(index => !Object.hasOwn(state.ratings, index)).length }];
        })) : null;
        return {
            reviewer_id: state.reviewer_id, test_mode: testMode, position, total: pairs.length,
            assessed: values.filter(row => row.status === 'rated').length,
            unsure: values.filter(row => row.status === 'unsure').length,
            remaining: pairs.length - values.length,
            ...(unified ? { kind, progress, priority: pair.priority ?? null, group: sections.get(kind).get(position) } : {}),
            pair: { text: pair.query.text, lang: pair.query.lang,
                ...(pair.query.criterion !== undefined ? { criterion: pair.query.criterion } : {}),
                image_url: `/image/${position}${unified ? '?queue=' + kind : ''}` },
            rating: state.ratings[position]?.value ?? null,
        };
    }

    const assets = { '/': ['index.html', 'text/html'], '/review.css': ['review.css', 'text/css'], '/review.js': ['review.js', 'text/javascript'] };
    return createServer(async (request, response) => {
        response.setHeader('Cache-Control', 'no-store');
        response.setHeader('X-Content-Type-Options', 'nosniff');
        response.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; frame-ancestors 'none'; form-action 'self'");
        const json = (value, status = 200) => {
            response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
            response.end(JSON.stringify(value, null, 2) + '\n');
        };
        try {
            const host = `127.0.0.1:${request.socket.localPort}`;
            if (request.headers.host !== host || (request.headers.origin && request.headers.origin !== `http://${host}`)) {
                throw new RequestError(403, 'Страница доступна только с локального адреса.');
            }
            const url = new URL(request.url, `http://${host}`);
            const path = url.pathname;
            if (request.method === 'GET' && Object.hasOwn(assets, path)) {
                const [file, type] = assets[path];
                response.writeHead(200, { 'Content-Type': `${type}; charset=utf-8` });
                response.end(readFileSync(new URL(file, import.meta.url)));
            } else if (request.method === 'GET' && /^\/image\/\d+$/.test(path)) {
                const index = Number(path.slice('/image/'.length));
                const kind = queueKind(url.searchParams.get('queue'));
                if (!isPosition(kind, index)) throw new RequestError(404, 'Изображение вне очереди.');
                const stream = createReadStream(available[kind].pairs[index].path);
                stream.once('error', () => response.destroy());
                response.on('close', () => stream.destroy());
                response.writeHead(200, { 'Content-Type': 'image/jpeg' });
                stream.pipe(response);
            } else if (request.method === 'GET' && path === '/api/info') {
                json({ test_mode: testMode, total: available.relevance.pairs.length,
                    ...(unified ? { queues: Object.keys(available) } : {}) });
            } else if (request.method === 'GET' && path === '/api/session') {
                const kind = queueKind(url.searchParams.get('queue'));
                json(current(kind, load(kind, reviewer(url.searchParams.get('reviewer')))));
            } else if (request.method === 'PUT' && path === '/api/session') {
                const data = await body(request);
                const kind = queueKind(data.queue);
                const state = load(kind, reviewer(data.reviewer_id));
                const { pairs } = available[kind];
                let target = data.position;
                if (data.priority !== undefined) {
                    if (!unified || kind !== 'relevance' || !priorities.includes(data.priority) || data.answer !== undefined) {
                        throw new RequestError(400, 'Неизвестный приоритет.');
                    }
                    target = pairs.findIndex((pair, index) => pair.priority === data.priority && !Object.hasOwn(state.ratings, index));
                    if (target < 0) target = pairs.findIndex(pair => pair.priority === data.priority);
                }
                if (!isPosition(kind, target)) throw positionError();
                if (data.answer !== undefined) {
                    const { position, value } = data.answer ?? {};
                    if (!isPosition(kind, position) || !isRating(value) || (kind === 'conditions' && value === 1)) {
                        throw new RequestError(400, 'Некорректная оценка.');
                    }
                    const { pair_id, query_id, artwork_id } = pairs[position];
                    const verdict = value === 'unsure' ? null : value;
                    state.ratings[position] = { ...(unified ? { pair_id, rating: verdict } : { query_id, artwork_id, relevance: verdict }),
                        value, status: value === 'unsure' ? 'unsure' : 'rated', reviewer_id: state.reviewer_id,
                        rated_at: new Date().toISOString() };
                }
                state.position = target;
                save(kind, state);
                json(current(kind, state));
            } else if (request.method === 'GET' && path === '/api/export') {
                const kind = queueKind(url.searchParams.get('queue'));
                const state = load(kind, reviewer(url.searchParams.get('reviewer')));
                const { ratings, position, ...metadata } = state;
                const rows = Object.values(ratings).map(({ value, ...row }) => row);
                const suffix = unified ? kind + '-' : '';
                response.setHeader('Content-Disposition', `attachment; filename="${testMode ? 'test' : 'human'}-review-${suffix}${state.reviewer_id}.json"`);
                json({ ...metadata, exported_at: new Date().toISOString(), ratings: rows });
            } else {
                throw new RequestError(404, 'Страница не найдена.');
            }
        } catch (error) {
            if (response.headersSent) return response.destroy();
            if (error instanceof RequestError) return json({ error: error.message }, error.status);
            console.error(error.message);
            json({ error: 'Локальная операция не выполнена. Проверьте вывод сервера.' }, 500);
        }
    });
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const options = process.argv.slice(2);
    const testMode = options.includes('--test');
    const unified = options.includes('--unified');
    const catalog = options.includes('--assistant-colbert');
    const constraints = options.includes('--assistant-constraints');
    const selections = options.filter(option => ['--regression', '--regression-1c', '--regression-1d1'].includes(option));
    const split = selections[0]?.slice(2) ?? 'evaluation';
    const ports = constraints ? { evaluation: 48089, 'regression-1c': 48091, 'regression-1d1': 48093 }
        : { evaluation: 48085, regression: 48087 };
    if (Number(catalog) + Number(constraints) + Number(unified) > 1 || selections.length > 1
        || (selections.length > 0 && !(catalog || constraints)) || !ports[split]
        || options.some(option => !['--test', '--unified', '--assistant-colbert', '--assistant-constraints', ...selections].includes(option))) {
        throw new Error('Использование: node server.mjs [--test] [--unified | --assistant-colbert [--regression] | --assistant-constraints [--regression-1c | --regression-1d1]]');
    }
    const newQueue = catalog || constraints;
    const catalogDir = join(repo, 'storage/app/ai-stage-05-04', constraints ? '5.4-1d-2' : '5.4-1d-1');
    const inputs = unified ? { queues: loadUnified().queues } : { queue: newQueue ? loadCatalogQueue(catalogDir, split) : loadQueue() };
    const stateDir = unified ? join(unifiedDirectory, testMode ? 'synthetic-review' : 'human-review')
        : testMode ? mkdtempSync(join(tmpdir(), 'library-stroll-review-test-'))
        : (newQueue ? join(catalogDir, 'human-review', split) : workspace);
    const port = (unified ? 48095 : newQueue ? ports[split] : 48083) + Number(testMode);
    reviewServer({ ...inputs, stateDir, testMode }).listen(port, '127.0.0.1', () => {
        const counts = unified ? `${inputs.queues.relevance.pairs.length} пар и ${inputs.queues.conditions.pairs.length} условий`
            : `${inputs.queue.pairs.length} пар`;
        console.log(`${testMode ? 'Тестовый режим. ' : ''}Ручная оценка, ${counts}. http://127.0.0.1:${port}/`);
    }).on('error', error => {
        console.error(error.message);
        process.exitCode = 1;
    });
}

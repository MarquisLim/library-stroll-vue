import { createHash, randomUUID } from 'node:crypto';
import { createReadStream, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

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

export function reviewServer({ queue, stateDir, testMode = false }) {
    const { pairs, fingerprint } = queue;
    const sessionFile = id => join(stateDir, `reviewer-${id}.json`);
    const source = testMode ? 'synthetic_test' : 'manual_ui';
    const isPosition = position => Number.isInteger(position) && position >= 0 && position < pairs.length;
    const positionError = () => new RequestError(400, 'Пара вне очереди.');

    function load(id) {
        const file = sessionFile(id);
        if (!existsSync(file)) return { schema_version: 1, reviewer_id: id, source, queue_sha256: fingerprint, position: 0, ratings: {} };
        const state = readJson(file);
        if (state.queue_sha256 !== fingerprint || state.reviewer_id !== id || state.source !== source || !isPosition(state.position)) {
            throw new RequestError(409, 'Сохранённая сессия относится к другой очереди или режиму.');
        }
        return state;
    }

    function save(state) {
        mkdirSync(stateDir, { recursive: true });
        const path = sessionFile(state.reviewer_id);
        const scratch = `${path}.${randomUUID()}.tmp`;
        try {
            writeFileSync(scratch, JSON.stringify(state, null, 2) + '\n', { flag: 'wx' });
            renameSync(scratch, path);
        } finally {
            rmSync(scratch, { force: true });
        }
    }

    function current(state) {
        const values = Object.values(state.ratings);
        const { position } = state;
        return {
            reviewer_id: state.reviewer_id, test_mode: testMode, position, total: pairs.length,
            assessed: values.filter(row => row.status === 'rated').length,
            unsure: values.filter(row => row.status === 'unsure').length,
            pair: { ...pairs[position].query, image_url: `/image/${position}` },
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
                if (!isPosition(index)) throw new RequestError(404, 'Изображение вне очереди.');
                const stream = createReadStream(pairs[index].path);
                stream.once('error', () => response.destroy());
                response.on('close', () => stream.destroy());
                response.writeHead(200, { 'Content-Type': 'image/jpeg' });
                stream.pipe(response);
            } else if (request.method === 'GET' && path === '/api/info') {
                json({ test_mode: testMode, total: pairs.length });
            } else if (request.method === 'GET' && path === '/api/session') {
                json(current(load(reviewer(url.searchParams.get('reviewer')))));
            } else if (request.method === 'PUT' && path === '/api/session') {
                const data = await body(request);
                const state = load(reviewer(data.reviewer_id));
                if (!isPosition(data.position)) throw positionError();
                if (data.answer !== undefined) {
                    const { position, value } = data.answer ?? {};
                    if (!isPosition(position) || !isRating(value)) throw new RequestError(400, 'Некорректная оценка.');
                    const { query_id, artwork_id } = pairs[position];
                    state.ratings[position] = { query_id, artwork_id, value, status: value === 'unsure' ? 'unsure' : 'rated',
                        relevance: value === 'unsure' ? null : value, reviewer_id: state.reviewer_id, rated_at: new Date().toISOString() };
                }
                state.position = data.position;
                save(state);
                json(current(state));
            } else if (request.method === 'GET' && path === '/api/export') {
                const state = load(reviewer(url.searchParams.get('reviewer')));
                const { ratings, position, ...metadata } = state;
                const rows = Object.values(ratings).map(({ value, ...row }) => row);
                response.setHeader('Content-Disposition', `attachment; filename="${testMode ? 'test' : 'human'}-review-${state.reviewer_id}.json"`);
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
    const testMode = process.argv[2] === '--test';
    if (process.argv.length > (testMode ? 3 : 2)) throw new Error('Использование: node server.mjs [--test]');
    const queue = loadQueue();
    const stateDir = testMode ? mkdtempSync(join(tmpdir(), 'library-stroll-review-test-')) : workspace;
    const port = testMode ? 48084 : 48083;
    reviewServer({ queue, stateDir, testMode }).listen(port, '127.0.0.1', () => {
        console.log(`${testMode ? 'Тестовый режим. ' : ''}Ручная оценка, ${queue.pairs.length} пар. http://127.0.0.1:${port}/`);
    }).on('error', error => {
        console.error(error.message);
        process.exitCode = 1;
    });
}

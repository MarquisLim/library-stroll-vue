import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync, realpathSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const stageRoot = fileURLToPath(new URL('../../../storage/app/ai-stage-05-04/', import.meta.url));
export const unifiedDirectory = join(stageRoot, '5.4-1d-3a');
export const priorities = ['P1', 'P2', 'P3', 'P4'];
export const stages = { colbert: '5.4-1d-1', constraints: '5.4-1d-2' };
export const studies = [
    { id: 'colbert-evaluation', family: 'colbert', split: 'evaluation', cohort: 'independent' },
    { id: 'colbert-regression', family: 'colbert', split: 'regression', cohort: 'regression-1c' },
    { id: 'constraints-evaluation', family: 'constraints', split: 'evaluation', cohort: 'independent' },
    { id: 'constraints-regression-1c', family: 'constraints', split: 'regression-1c', cohort: 'regression-1c' },
    { id: 'constraints-regression-1d1', family: 'constraints', split: 'regression-1d1', cohort: 'regression-1d1' },
];

export const sha256 = bytes => createHash('sha256').update(bytes).digest('hex');
export const jsonBytes = value => JSON.stringify(value, null, 2) + '\n';
export const readJson = file => JSON.parse(readFileSync(file, 'utf8').replace(/^\uFEFF/, ''));
export const textKey = ({ text, criterion }) => JSON.stringify([text, criterion]);
export const reviewId = (query, artworkId, digest) => sha256(JSON.stringify([query.text, query.criterion, artworkId, digest]));
const hex = /^[a-f0-9]{64}$/;
const idIsValid = id => Number.isSafeInteger(id) && id > 0;

export function imageVerifier(root = stageRoot) {
    const hashes = new Map();
    const roots = new Map();

    return ({ family, artwork_id, sha256: digest }) => {
        assert(Object.hasOwn(stages, family) && idIsValid(artwork_id) && hex.test(digest), 'Invalid image reference');
        if (!roots.has(family)) roots.set(family, realpathSync(join(root, stages[family], 'review-images')));
        const imageRoot = roots.get(family);
        const file = realpathSync(join(imageRoot, artwork_id + '.jpg'));
        const path = relative(imageRoot, file);
        assert(path && !path.startsWith('..' + sep) && file.startsWith(imageRoot + sep), 'Image outside its frozen directory');
        if (!hashes.has(file)) {
            const bytes = readFileSync(file);
            assert(bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff, 'Expected JPEG: ' + artwork_id);
            hashes.set(file, sha256(bytes));
        }
        assert.equal(hashes.get(file), digest, 'Image checksum differs for ' + artwork_id);
        return file;
    };
}

export function loadStudy(root, study) {
    const directory = join(root, stages[study.family]);
    const rankFile = join(directory, 'runs-' + study.split + '.json');
    const queueFile = join(directory, 'review-queue-' + study.split + '.json');
    const rankBytes = readFileSync(rankFile);
    const queueBytes = readFileSync(queueFile);
    const data = JSON.parse(rankBytes);
    const queries = new Map();
    const ranked = repeats => {
        assert(repeats.length > 0);
        const ids = repeats[0].ids;
        assert(ids.every(idIsValid) && new Set(ids).size === ids.length, 'Malformed saved ranking');
        for (const repeat of repeats) assert.deepEqual(repeat.ids, ids, 'Stored candidate order varies by repeat');
        return ids;
    };
    for (const row of data.rows) {
        const query = { text: row.query, criterion: row.criterion, lang: row.lang ?? row.language };
        assert(typeof row.id === 'string' && !queries.has(row.id) && typeof query.text === 'string'
            && typeof query.criterion === 'string' && ['ru', 'en'].includes(query.lang), 'Malformed study query');
        const methods = Object.fromEntries(Object.entries(row.runs ?? row.baselines).map(([mode, repeats]) => [mode, ranked(repeats)]));
        for (const [approach, settings] of Object.entries(row.trials ?? {})) {
            assert(Object.keys(settings).length === 1 && Object.hasOwn(settings, '20/0.5'), 'Unexpected constraint settings');
            for (const [mode, repeats] of Object.entries(settings['20/0.5'])) methods[approach + '-' + mode] = ranked(repeats);
        }
        queries.set(row.id, { query, methods, row });
    }
    return { ...study, queries, originalQueue: JSON.parse(queueBytes),
        rank_sha256: sha256(rankBytes), queue_sha256: sha256(queueBytes) };
}

export function loadUnified(directory = unifiedDirectory, root = stageRoot) {
    const manifest = readJson(join(directory, 'unified-manifest.json'));
    assert.equal(manifest.schema_version, 1);
    const load = (name, digest) => {
        assert(typeof digest === 'string' && hex.test(digest), 'Invalid frozen file digest');
        const bytes = readFileSync(join(directory, name));
        assert.equal(sha256(bytes), digest, 'Frozen file checksum differs: ' + name);
        return JSON.parse(bytes);
    };
    const imagePath = imageVerifier(root);
    const queues = {};
    for (const kind of ['relevance', 'conditions']) {
        const frozen = manifest.queues[kind];
        const entries = load(kind + '-queue.json', frozen.sha256);
        assert.equal(entries.length, frozen.count);
        assert(entries.length > 0);
        const seen = new Set();
        let lastPriority = 0;
        const pairs = entries.map(entry => {
            const { pair_id, group_id, priority, text, lang, criterion, image } = entry;
            assert(hex.test(pair_id) && hex.test(group_id) && !seen.has(pair_id)
                && typeof text === 'string' && text.length > 0 && typeof criterion === 'string'
                && ['ru', 'en'].includes(lang), 'Invalid or repeated review pair');
            seen.add(pair_id);
            if (kind === 'relevance') {
                const number = priorities.indexOf(priority);
                assert(number >= lastPriority && priority && pair_id === reviewId(entry, image.artwork_id, image.sha256));
                lastPriority = number;
            }
            return { pair_id, group_id, priority, query: { text, lang, criterion },
                image_sha256: image.sha256, path: imagePath(image) };
        });
        queues[kind] = { kind, pairs, fingerprint: frozen.sha256 };
    }
    return { manifest, queues, provenance: () => load('provenance.json', manifest.provenance_sha256) };
}

import assert from 'node:assert/strict';
import { once } from 'node:events';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { assessRanking, conditionMetrics, importRatings, rankingMetrics } from './human-metrics.mjs';
import { reviewServer } from './server.mjs';
import { loadUnified, sha256, unifiedDirectory } from './unified-data.mjs';

async function open(inputs) {
    const server = reviewServer(inputs);
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const base = 'http://127.0.0.1:' + server.address().port;
    return {
        get: path => fetch(base + path),
        put: data => fetch(base + '/api/session', { method: 'PUT',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }),
        close: () => new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve())),
    };
}

const inputs = loadUnified();
const provenance = inputs.provenance();
const reviewer = 'synthetic-review';
const session = (queue = 'relevance') => '/api/session?reviewer=' + reviewer + '&queue=' + queue;
const exportPath = queue => '/api/export?reviewer=' + reviewer + '&queue=' + queue;

test('frozen priorities and grouped pairs cover all historical top-20 lists without repeated grades', () => {
    assert.equal(inputs.queues.relevance.pairs.length, 2305);
    assert.equal(inputs.queues.conditions.pairs.length, 119);
    assert.equal(new Set(inputs.queues.relevance.pairs.map(x => x.pair_id)).size, 2305);
    assert.deepEqual(inputs.manifest.summary.priority_totals, { P1: 864, P2: 661, P3: 693, P4: 87 });
    const previous = new Set();
    let group = null;
    for (const pair of inputs.queues.relevance.pairs) {
        const key = pair.priority + ':' + pair.group_id;
        if (key !== group) assert(!previous.has(key));
        previous.add(key);
        group = key;
        const ranks = provenance.relevance[pair.pair_id].memberships.flatMap(row => Object.values(row.positions));
        const best = Math.min(Infinity, ...ranks);
        assert.equal(pair.priority, best <= 5 ? 'P1' : best <= 10 ? 'P2' : best <= 20 ? 'P3' : 'P4');
    }
    assert(Object.values(provenance.conditions).some(pair => pair.artwork_id === 467));
    const empty = rankingMetrics(new Map(), { queues: inputs.queues, catalogs: provenance.catalogs });
    assert.equal(Object.keys(empty).length, 5);
    for (const study of Object.values(empty)) {
        for (const mode of Object.values(study.groups.all.methods)) {
            assert.equal(mode.coverage[20].unreviewed, mode.coverage[20].returned);
            assert(Object.values(mode.scores).every(score => score.n === 0 && score.mean === null));
        }
    }
    const conditions = conditionMetrics(new Map(), inputs);
    assert.equal(conditions.unreviewed, 119);
    assert.equal(conditions.comparisons.oracle.accuracy, null);
    assert.equal(conditions.comparisons.e2e.accuracy, null);
});

test('the queue loader rejects altered queue bytes before accepting a review', t => {
    const temp = mkdtempSync(join(tmpdir(), 'unified-hash-'));
    t.after(() => rmSync(temp, { recursive: true, force: true }));
    for (const name of ['unified-manifest.json', 'relevance-queue.json']) {
        writeFileSync(join(temp, name), readFileSync(join(unifiedDirectory, name)));
    }
    const queue = join(temp, 'relevance-queue.json');
    writeFileSync(queue, Buffer.concat([readFileSync(queue), Buffer.from('\n')]));
    assert.throws(() => loadUnified(temp), /Frozen file checksum differs/);
});

test('both queues are blind, priority navigation persists, and test exports cannot be imported', async t => {
    const temp = mkdtempSync(join(tmpdir(), 'unified-review-'));
    t.after(() => rmSync(temp, { recursive: true, force: true }));
    const testRoot = join(temp, 'synthetic-review');
    const config = { queues: inputs.queues, stateDir: testRoot, testMode: true };
    let service = await open(config);
    const update = data => service.put({ reviewer_id: reviewer, ...data });
    try {
        const first = await (await service.get(session())).json();
        assert.equal(first.priority, 'P1');
        assert.equal(first.remaining, 2305);
        assert.deepEqual(Object.keys(first.pair).sort(), ['criterion', 'image_url', 'lang', 'text']);
        assert.deepEqual(Object.keys(first.group).sort(), ['position', 'total']);
        for (const path of ['provenance.json', 'relevance-queue.json', 'unified-manifest.json', 'runs-evaluation.json']) {
            assert.equal((await service.get('/' + path)).status, 404);
        }
        assert.equal((await update({ queue: 'relevance', position: 1, answer: { position: 0, value: 2 } })).status, 200);
        assert.equal((await update({ queue: 'conditions', position: 0, answer: { position: 0, value: 1 } })).status, 400);
        const condition = await (await update({ queue: 'conditions', position: 1, answer: { position: 0, value: 'unsure' } })).json();
        assert.equal(condition.unsure, 1);
        assert.equal(condition.remaining, 118);
        assert.equal(condition.progress, null);
        const next = await (await update({ queue: 'relevance', priority: 'P2' })).json();
        assert.equal(next.priority, 'P2');
        assert.equal(next.progress.P1.remaining, 863);
        assert.equal(next.progress.P2.remaining, 661);
        await service.close();
        service = await open(config);
        assert.equal((await (await service.get(session())).json()).position, next.position);
        assert.equal((await (await service.get(session('conditions'))).json()).position, 1);
        assert.equal((await update({ queue: 'relevance', position: 0, answer: { position: 0, value: 1 } })).status, 200);
        for (const kind of ['relevance', 'conditions']) {
            const exported = await (await service.get(exportPath(kind))).json();
            assert.equal(exported.source, 'synthetic_test');
            assert.equal(exported.queue_sha256, inputs.queues[kind].fingerprint);
            assert.equal(exported.ratings.length, 1);
            assert.equal(exported.ratings[0].rating, kind === 'relevance' ? 1 : null);
            assert.deepEqual(Object.keys(exported.ratings[0]).sort(), ['pair_id', 'rated_at', 'rating', 'reviewer_id', 'status']);
            assert.throws(() => importRatings(exported, inputs.queues[kind]), /Expected a human export/);
            assert(existsSync(join(testRoot, kind, 'reviewer-' + reviewer + '.json')));
        }
        assert(!existsSync(join(temp, 'human-review')));
        assert.equal((await service.get('/image/0?queue=__proto__')).status, 400);
    } finally {
        await service.close();
    }
});

test('every unique frozen JPEG is served from the approved reference', async () => {
    const service = await open({ queues: inputs.queues, stateDir: tmpdir(), testMode: true });
    const unique = new Map();
    for (const [kind, queue] of Object.entries(inputs.queues)) {
        for (const [index, pair] of queue.pairs.entries()) {
            unique.set(pair.image_sha256, `/image/${index}?queue=${kind}`);
        }
    }
    assert.equal(unique.size, 444);
    try {
        const requests = [...unique];
        for (let i = 0; i < requests.length; i += 12) {
            await Promise.all(requests.slice(i, i + 12).map(async ([hash, path]) => {
                const response = await service.get(path);
                assert.equal(response.status, 200);
                assert.equal(response.headers.get('content-type'), 'image/jpeg');
                assert.equal(sha256(Buffer.from(await response.arrayBuffer())), hash);
            }));
        }
    } finally {
        await service.close();
    }
});

test('validation rejects wrong hashes, pairs, duplicates, and cross-queue assessment values', () => {
    const queue = { kind: 'relevance', fingerprint: 'fixture', pairs: [{ pair_id: 'a' }, { pair_id: 'b' }] };
    const row = { pair_id: 'a', rating: 2, status: 'rated', reviewer_id: 'fixture', rated_at: '2026-11-28T00:00:00.000Z' };
    // This is an in-memory validation fixture with no connection to the frozen queues or a study export.
    const data = { schema_version: 2, source: 'manual_ui', queue_sha256: 'fixture', reviewer_id: 'fixture', kind: 'relevance', ratings: [row] };
    assert.deepEqual(importRatings(data, queue), new Map([['a', 2]]));
    assert.throws(() => importRatings({ ...data, queue_sha256: 'other' }, queue));
    assert.throws(() => importRatings({ ...data, ratings: [row, row] }, queue));
    assert.throws(() => importRatings({ ...data, ratings: [{ ...row, pair_id: 'c' }] }, queue));
    assert.throws(() => importRatings({ ...data, ratings: [{ ...row, status: 'unsure' }] }, queue));
    assert.throws(() => importRatings({ ...data, kind: 'conditions', ratings: [{ ...row, rating: 1 }] },
        { ...queue, kind: 'conditions' }));
});

test('top-five labels do not imply deep recall and unsure is distinct from missing', () => {
    const ids = Array.from({ length: 50 }, (_, index) => index + 1);
    const labels = new Map([[1, 2], [2, 1], [3, 0], [4, 0], [5, 2], [6, null]]);
    const result = assessRanking(ids, labels);
    assert.equal(result.scores.hit5, 1);
    assert(result.scores.ndcg5 > 0 && result.scores.ndcg5 < 1);
    assert.equal(result.scores.recall_known_5, 1);
    for (const depth of [10, 20, 50]) assert.equal(result.scores['recall_known_' + depth], null);
    assert.deepEqual(result.coverage[10], { returned: 10, rated: 5, unsure: 1, unreviewed: 4 });
    labels.set(5, null);
    assert.equal(assessRanking(ids, labels).scores.hit5, null);
    assert.equal(assessRanking(ids, labels).scores.hit1, 1);
});

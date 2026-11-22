import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { once } from 'node:events';
import { existsSync, mkdtempSync, readFileSync, readdirSync, rmdirSync, unlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { loadQueue, reviewServer } from './server.mjs';

const queue = loadQueue();
const stateFile = 'reviewer-synthetic-qa.json';

async function start(stateDir) {
    const server = reviewServer({ queue, stateDir, testMode: true });
    server.listen(0, '127.0.0.1');
    await once(server, 'listening');
    const base = `http://127.0.0.1:${server.address().port}`;
    return {
        request: (path, data, headers = {}) => fetch(base + path, data === undefined ? { headers } : {
            method: 'PUT', headers: { 'Content-Type': 'application/json', ...headers }, body: JSON.stringify(data),
        }),
        close: () => new Promise((resolve, reject) => server.close(error => error ? reject(error) : resolve())),
    };
}

test('607 distinct pairs in the expected three tiers', () => {
    const plan = queue.pairs.map(({ query_id, artwork_id }) => [query_id, artwork_id]);
    assert.equal(plan.length, 607);
    assert.equal(new Set(plan.map(row => row.join(':'))).size, 607);
    assert.equal(queue.fingerprint, loadQueue().fingerprint);
    for (const group of [plan.slice(0, 300), plan.slice(300, 563), plan.slice(563)]) {
        assert.deepEqual(group, [...group].sort(([a, b], [c, d]) => a - c || b - d));
    }
});

test('blind session, original image, persistence after restart, edit and export', async () => {
    const dir = mkdtempSync(join(tmpdir(), 'review-server-test-'));
    let server = await start(dir);
    const session = '/api/session?reviewer=synthetic-qa';
    const save = (position, answer) => server.request('/api/session', { reviewer_id: 'synthetic-qa', position, answer });
    try {
        const initial = await (await server.request(session)).json();
        assert.equal(initial.test_mode, true);
        assert.equal(initial.total, 607);
        assert.equal(initial.position, 0);
        assert.equal(initial.rating, null);
        assert.deepEqual(Object.keys(initial.pair).sort(), ['image_url', 'lang', 'text']);
        assert.equal(existsSync(join(dir, stateFile)), false);
        const response = await server.request(initial.pair.image_url);
        assert.equal(response.headers.get('content-type'), 'image/jpeg');
        const sha = bytes => createHash('sha256').update(bytes).digest('hex');
        assert.equal(sha(Buffer.from(await response.arrayBuffer())), sha(readFileSync(queue.pairs[0].path)));
        assert.equal((await server.request('/rankings.json')).status, 404);
        assert.equal((await server.request('/image/607')).status, 404);
        const invalid = await server.request('/api/session', { reviewer_id: 'synthetic-qa', position: 607 });
        assert.equal(invalid.status, 400);
        const foreign = await server.request('/api/session', { reviewer_id: 'synthetic-qa', position: 0 }, { Origin: 'http://example.invalid' });
        assert.equal(foreign.status, 403);

        const rated = await (await save(1, { position: 0, value: 2 })).json();
        assert.equal(rated.assessed, 1);
        assert.equal(rated.position, 1);
        const unsure = await (await save(2, { position: 1, value: 'unsure' })).json();
        assert.equal(unsure.unsure, 1);
        assert.equal(unsure.position, 2);
        await server.close();
        server = await start(dir);
        assert.equal((await (await server.request(session)).json()).position, 2);

        const previous = await (await save(1)).json();
        assert.equal(previous.rating, 'unsure');
        const edited = await (await save(0, { position: 1, value: 0 })).json();
        assert.equal(edited.assessed, 2);
        assert.equal(edited.unsure, 0);
        assert.equal(edited.rating, 2);
        const changed = await (await server.request('/api/session', {
            reviewer_id: 'synthetic-qa', position: 2, answer: { position: 1, value: 'unsure' },
        })).json();
        assert.equal(changed.unsure, 1);
        const zero = await (await server.request('/api/session', {
            reviewer_id: 'synthetic-qa', position: 3, answer: { position: 2, value: 0 },
        })).json();
        assert.equal(zero.assessed, 2);
        const exportResponse = await server.request('/api/export?reviewer=synthetic-qa');
        assert.match(exportResponse.headers.get('content-disposition'), /test-review-synthetic-qa\.json/);
        const exported = await exportResponse.json();
        assert.equal(exported.source, 'synthetic_test');
        assert.equal(exported.queue_sha256, queue.fingerprint);
        assert.deepEqual(exported.ratings.map(row => [row.status, row.relevance]), [['rated', 2], ['unsure', null], ['rated', 0]]);
        assert.ok(exported.ratings.every(row => row.reviewer_id === 'synthetic-qa' && !isNaN(Date.parse(row.rated_at))));
        assert.equal((await (await server.request('/api/session?reviewer=other')).json()).assessed, 0);
    } finally {
        await server.close();
        for (const name of readdirSync(dir)) {
            if (name === stateFile) unlinkSync(join(dir, name));
        }
        rmdirSync(dir);
    }
});

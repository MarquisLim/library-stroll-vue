import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { imageVerifier, jsonBytes, loadStudy, priorities, reviewId, sha256, stageRoot, stages,
    studies, textKey, unifiedDirectory } from './unified-data.mjs';

const stageId = '5.4-1d-3a';
const source = studies.map(study => loadStudy(stageRoot, study));
const verifyImage = imageVerifier();
const grouped = new Map();
const pairs = new Map();
const originCounts = {};

for (const study of source) {
    originCounts[study.id] = study.originalQueue.length;
    study.exact = new Map();
    for (const item of study.originalQueue) {
        const saved = study.queries.get(item.query_id);
        assert(saved && textKey(item.query) === textKey(saved.query) && item.query.lang === saved.query.lang,
            'Original queue does not match its saved query');
        const image = { family: study.family, artwork_id: item.artwork_id, sha256: item.sha256 };
        verifyImage(image);
        const key = reviewId(saved.query, item.artwork_id, item.sha256);
        const origin = { study: study.id, query_id: item.query_id };
        const identity = item.query_id + ':' + item.artwork_id;
        assert(!study.exact.has(identity), 'Duplicate pair inside an original queue');
        study.exact.set(identity, key);
        if (!pairs.has(key)) {
            const groupingKey = textKey(saved.query);
            if (!grouped.has(groupingKey)) grouped.set(groupingKey, new Map());
            const variants = grouped.get(groupingKey);
            if (!variants.has(item.artwork_id)) variants.set(item.artwork_id, new Set());
            variants.get(item.artwork_id).add(key);
            pairs.set(key, { review: { pair_id: key, group_id: sha256(groupingKey), priority: 'P4', ...saved.query, image },
                origins: [], memberships: [] });
        } else {
            assert.equal(pairs.get(key).review.lang, saved.query.lang);
        }
        pairs.get(key).origins.push(origin);
    }
}

function pairFor(study, queryId, query, artworkId) {
    const exact = study.exact.get(queryId + ':' + artworkId);
    if (exact) return exact;
    const versions = grouped.get(textKey(query))?.get(artworkId);
    if (!versions) return null;
    if (versions.size === 1) return [...versions][0];
    throw new Error('Image revisions are ambiguous for ' + study.id + '/' + queryId + '/' + artworkId);
}

const catalogs = [];
for (const study of source) {
    const queryMaps = {};
    for (const [id, { query, methods }] of study.queries) {
        const ranks = new Map();
        for (const [method, ids] of Object.entries(methods)) {
            for (const [offset, artworkId] of ids.entries()) {
                const key = pairFor(study, id, query, artworkId);
                assert(key || offset >= 20, 'Missing verified top-20 pair: ' + study.id + '/' + id + '/' + artworkId);
                if (key === null) continue;
                if (!ranks.has(artworkId)) ranks.set(artworkId, {});
                ranks.get(artworkId)[method] = offset + 1;
            }
        }
        const lookup = {};
        for (const artworkId of grouped.get(textKey(query))?.keys() ?? []) {
            const key = pairFor(study, id, query, artworkId);
            const item = pairs.get(key);
            const positions = ranks.get(artworkId) ?? {};
            const best = Math.min(Infinity, ...Object.values(positions));
            const priority = best <= 5 ? 'P1' : best <= 10 ? 'P2' : best <= 20 ? 'P3' : 'P4';
            if (priority < item.review.priority) item.review.priority = priority;
            item.memberships.push({ study: study.id, query_id: id, positions });
            lookup[artworkId] = key;
        }
        queryMaps[id] = { group_id: sha256(textKey(query)), artwork_to_pair: lookup };
    }
    catalogs.push({ id: study.id, family: study.family, split: study.split, cohort: study.cohort,
        rank_sha256: study.rank_sha256, queue_sha256: study.queue_sha256, queries: queryMaps });
}

function conditionPrompt(plan, ref, lang, queryId) {
    const ru = lang === 'ru';
    const [kind, slot] = ref.split(':');
    if (kind === 'object') {
        const { name } = plan.objects[Number(slot) - 1];
        return ru ? `На изображении есть «${name}».` : `The image contains “${name}”.`;
    }
    if (kind === 'count') {
        const { name, count } = plan.objects[Number(slot) - 1];
        assert(count);
        const number = { exact: ru ? `ровно ${count.n}` : `exactly ${count.n}`,
            at_least: ru ? `не менее ${count.n}` : `at least ${count.n}`,
            at_most: ru ? `не более ${count.n}` : `at most ${count.n}`,
            range: ru ? `от ${count.n} до ${count.max}` : `from ${count.n} to ${count.max}`,
            approx: ru ? `примерно ${count.n}` : `about ${count.n}`, vague: count.text }[count.op];
        return ru ? `На изображении видно ${number}. Что считать — «${name}».` : `Visible count of “${name}” is ${number}.`;
    }
    assert.equal(kind, 'condition');
    const item = plan.conditions[Number(slot) - 1];
    const target = plan.objects[(item.target ?? 0) - 1]?.name;
    const other = plan.objects[(item.other ?? 0) - 1]?.name;
    if (queryId === 'R07' && ref === 'condition:1') return 'Видны красные цветы. Оцените цвет самих цветов.';
    const prompt = ru ? {
        color: `Цвет объекта «${target}» — «${item.text}».`,
        background_color: `Цвет фона — «${item.text}». Оценивайте область позади объекта.`,
        attribute: `У объекта «${target}» виден признак «${item.text}».`,
        media: `Вид изображения — «${item.text}».`,
        style: `Изображение выполнено в стиле «${item.text}».`,
        scene: `Изображение показывает окружение «${item.text}».`,
        relation: `На изображении «${target}» находится «${item.text}» по отношению к «${other}».`,
        presence: `На изображении нет объекта «${item.text}».`,
    } : {
        color: `The color of “${target}” is “${item.text}”.`,
        background_color: `The background is “${item.text}”. Judge the area behind the subject.`,
        attribute: `The feature “${item.text}” is visible on “${target}”.`,
        media: `The image is a “${item.text}”.`,
        style: `The work uses the style “${item.text}”.`,
        scene: `The image shows the setting “${item.text}”.`,
        relation: `“${target}” is “${item.text}” relative to “${other}”.`,
        presence: `The image does not contain “${item.text}”.`,
    };
    assert(Object.hasOwn(prompt, item.kind), 'Unknown visual condition');
    if (item.kind !== 'presence' && item.mode?.includes('excluded')) {
        return ru ? `Проверка исключения. Следующее утверждение неверно для изображения: ${prompt[item.kind]}`
            : `Exclusion check. The following statement is false for the image: ${prompt[item.kind]}`;
    }
    return prompt[item.kind];
}

const conditionsStudy = source.find(item => item.id === 'constraints-evaluation');
const conditionReviews = [];
const conditionOrigins = {};
for (const [id, { query, row }] of conditionsStudy.queries) {
    // Use only the keys of the frozen control set. Preliminary labels and decisions are ignored.
    for (const [ref, controls] of Object.entries(row.checks)) {
        const text = conditionPrompt(row.oracle, ref, query.lang, id);
        const group_id = sha256(JSON.stringify([id, ref, text]));
        for (const artworkId of Object.keys(controls).map(Number)) {
            const pair = pairs.get(pairFor(conditionsStudy, id, query, artworkId));
            assert(pair, 'Condition has no frozen image');
            const image = pair.review.image;
            const pair_id = sha256(JSON.stringify([group_id, artworkId, image.sha256]));
            assert(!Object.hasOwn(conditionOrigins, pair_id));
            conditionOrigins[pair_id] = { query_id: id, condition: ref, artwork_id: artworkId, image_sha256: image.sha256 };
            conditionReviews.push({ pair_id, group_id, text, lang: query.lang, criterion: '', image });
        }
    }
}
assert.equal(conditionReviews.length, 119);
assert(Object.values(conditionOrigins).some(row => row.artwork_id === 467 && row.query_id === 'R07' && row.condition === 'condition:1'));

const compare = (a, b) => a < b ? -1 : a > b ? 1 : 0;
const blindOrder = (a, b) => compare(a.group_id, b.group_id)
    || compare(sha256(stageId + a.pair_id), sha256(stageId + b.pair_id));
const relevance = [...pairs.values()].map(item => item.review).sort((a, b) => compare(a.priority, b.priority) || blindOrder(a, b));
conditionReviews.sort(blindOrder);
const provenance = { schema_version: 1, catalogs,
    relevance: Object.fromEntries(relevance.map(({ pair_id }) => {
        const { origins, memberships } = pairs.get(pair_id);
        return [pair_id, { origins, memberships }];
    })), conditions: conditionOrigins };
const countByPriority = entries => Object.fromEntries(priorities.map(p => [p, entries.filter(row => row.priority === p).length]));
const table = Object.fromEntries(catalogs.map(study => [study.id, countByPriority(relevance.filter(row =>
    provenance.relevance[row.pair_id].memberships.some(entry => entry.study === study.id)))]));
const independent = relevance.filter(row => provenance.relevance[row.pair_id].memberships
    .some(entry => catalogs.find(study => study.id === entry.study).cohort === 'independent'));
const independentIds = new Set(independent.map(row => row.pair_id));
const summary = {
    source_rows: originCounts,
    source_total: Object.values(originCounts).reduce((a, b) => a + b, 0),
    unique_relevance: relevance.length,
    priority_totals: countByPriority(relevance),
    independent: countByPriority(independent),
    regression_only: countByPriority(relevance.filter(row => !independentIds.has(row.pair_id))),
    memberships_by_study: table,
    repeated_memberships_avoided: [...pairs.values()].reduce((count, row) => count + row.memberships.length - 1, 0),
    condition_count: conditionReviews.length,
    unique_images: new Set([...relevance, ...conditionReviews].map(row => row.image.sha256)).size,
};
summary.duplicates_removed_from_source_queues = summary.source_total - summary.unique_relevance;

const bytes = { relevance: jsonBytes(relevance), conditions: jsonBytes(conditionReviews), provenance: jsonBytes(provenance) };
const scoresFile = join(stageRoot, stages.constraints, 'metrics-evaluation.json');
const manifest = { schema_version: 1, summary,
    queues: { relevance: { sha256: sha256(bytes.relevance), count: relevance.length },
        conditions: { sha256: sha256(bytes.conditions), count: conditionReviews.length } },
    provenance_sha256: sha256(bytes.provenance),
    evidence_source_sha256: sha256(readFileSync(scoresFile)) };

mkdirSync(unifiedDirectory, { recursive: true });
const generated = [['relevance-queue.json', bytes.relevance], ['conditions-queue.json', bytes.conditions],
    ['provenance.json', bytes.provenance], ['unified-manifest.json', jsonBytes(manifest)]];
// Publish the manifest last and never replace a file tied to saved human reviews.
for (const [name, value] of generated) {
    const file = join(unifiedDirectory, name);
    if (existsSync(file)) assert.equal(sha256(readFileSync(file)), sha256(value), 'Refusing to replace frozen ' + name);
}
for (const [name, value] of generated) {
    const file = join(unifiedDirectory, name);
    if (!existsSync(file)) writeFileSync(file, value, { flag: 'wx' });
}
console.log(jsonBytes(summary));

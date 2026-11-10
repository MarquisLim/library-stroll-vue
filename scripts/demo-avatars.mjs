import { createAvatar } from '@dicebear/core';
import { lorelei } from '@dicebear/collection';
import { Resvg } from '@resvg/resvg-js';
let input = '';
for await (const chunk of process.stdin) input += chunk;
const seeds = JSON.parse(input);
process.stdout.write(JSON.stringify(seeds.map(seed => new Resvg(createAvatar(lorelei, { seed: String(seed), size: 128 }).toString()).render().asPng().toString('base64'))));

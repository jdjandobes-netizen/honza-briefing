import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';

const root = new URL('../', import.meta.url);
const read = path => readFileSync(new URL(path, root));
const html = read('index.html').toString();
const manifest = JSON.parse(read('manifest.webmanifest'));
const sw = read('service-worker.js').toString();
const assets = {
  'assets/brand/news-32-v10.png': [32, '52bdeffcc404d66e6736f50a7faec0fe75283cd7'],
  'assets/brand/news-192-v10.png': [192, '6a62be4f6fc383322b3c6245179bb74b8624253d'],
  'assets/brand/news-512-v10.png': [512, '67c49982f54406cf7df2d429d36efb06ec52ae64'],
  'assets/brand/news-maskable-512-v10.png': [512, 'eed820e4fb81701a2f03a0112042e7e1a94c17fc'],
  'apple-touch-icon.png': [512, 'eed820e4fb81701a2f03a0112042e7e1a94c17fc']
};
for (const [path, [size, sha]] of Object.entries(assets)) {
  const bytes = read(path);
  assert.equal(bytes.subarray(0, 8).toString('hex'), '89504e470d0a1a0a', path);
  assert.equal(bytes.readUInt32BE(16), size, path);
  assert.equal(bytes.readUInt32BE(20), size, path);
  assert.equal(createHash('sha1').update(`blob ${bytes.length}\0`).update(bytes).digest('hex'), sha, `Approved pixels changed: ${path}`);
}
assert.equal(manifest.id, './');
assert.equal(manifest.start_url, './');
assert.equal(manifest.scope, './');
assert.deepEqual(manifest.icons.map(i => i.purpose), ['any', 'any', 'maskable']);
for (const icon of manifest.icons) {
  assert.ok(assets[icon.src], icon.src);
  assert.equal(icon.sizes, `${assets[icon.src][0]}x${assets[icon.src][0]}`);
  assert.equal(icon.type, 'image/png');
}
const links = [...html.matchAll(/<link\s+rel="(?:icon|apple-touch-icon|manifest)"[^>]+href="([^"]+)"[^>]*>/g)].map(m => m[1]);
assert.equal(links.length, 4);
assert.ok(links.includes('assets/brand/news-maskable-512-v10.png'));
assert.match(html, /<img src="assets\/brand\/news-512-v10\.png"/);
assert.doesNotMatch(html + sw + JSON.stringify(manifest), /["'](?:\.\/)?icons\//);
for (const base of ['https://briefing.nacestach.online/', 'https://briefing.nacestach.online/test/', 'https://jdjandobes-netizen.github.io/honza-briefing/']) {
  for (const path of [...links, ...manifest.icons.map(i => i.src)]) assert.ok(new URL(path, base).href.startsWith(base));
}
let shell;
const handlers = {};
let notification;
const scope = 'https://briefing.nacestach.online/';
const context = {
  URL, Request,
  self: {
    location: { origin: new URL(scope).origin, href: scope + 'service-worker.js' },
    skipWaiting() {},
    addEventListener(type, handler) { handlers[type] = handler; },
    registration: { showNotification(title, options) { notification = options; return Promise.resolve(); } }
  },
  caches: { async open(name) { assert.equal(name, 'honza-briefing-v10'); return { async addAll(paths) { shell = [...paths]; } }; } }
};
vm.runInNewContext(sw, context);
let completion;
handlers.install({ waitUntil(promise) { completion = promise; } });
await completion;
for (const path of [...links, ...Object.keys(assets)]) assert.ok(shell.includes('./' + path), `Missing offline asset: ${path}`);
for (const path of shell) assert.ok(read(path === './' ? 'index.html' : path.split('?')[0]).length, path);
handlers.push({ waitUntil(promise) { completion = promise; } });
await completion;
assert.ok(assets[notification.icon.replace('./', '')]);
assert.ok(assets[notification.badge.replace('./', '')]);
for (const path of ['api/podcast.php?action=session', 'api/podcast.php?action=audio']) {
  handlers.fetch({ request: new Request(scope + path), respondWith() { assert.fail('Private API intercepted'); } });
}
console.log('PASS: approved PNG hashes/dimensions, favicon/iOS/Android links, stable PWA identity, root/subpath URLs, offline shell, notification icon, API bypass.');

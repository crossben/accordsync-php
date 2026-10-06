/**
 * One seed of the mixed-server fleet (plan-php.md §0 point 3): TypeScript devices
 * (@accordsync/client from the Accord workspace) whose every request goes to a randomly chosen
 * server, the TypeScript reference or the PHP port, both serving the same PostgreSQL database.
 * Started by run.sh; run from ACCORD_APP_DIR/conformance so `tsx` resolves:
 *
 *   node --import tsx --conditions=@accordsync/source <this file>
 *
 * Env: ACCORD_APP_DIR, ACCORD_FLEET_SEED, ACCORD_TS_URL/ACCORD_TS_CONTROL_URL,
 * ACCORD_PHP_URL/ACCORD_PHP_CONTROL_URL. Exits non-zero on any failed assertion.
 */
import assert from 'node:assert/strict';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const app = process.env.ACCORD_APP_DIR;
if (!app) throw new Error('ACCORD_APP_DIR is required');
const load = (p: string) => import(pathToFileURL(join(app, p)).href);
const { AccordClient, MemoryStorage, httpTransport, defineSchema, lww, conflict, counter, set } =
  await load('packages/client/src/index.ts');
const { canonicalJson } = await load('packages/core/src/index.ts');

const seed = Number(process.env.ACCORD_FLEET_SEED ?? 1);
const servers = {
  ts: { url: process.env.ACCORD_TS_URL ?? 'http://127.0.0.1:8841', control: process.env.ACCORD_TS_CONTROL_URL ?? 'http://127.0.0.1:8842' },
  php: { url: process.env.ACCORD_PHP_URL ?? 'http://127.0.0.1:8843', control: process.env.ACCORD_PHP_CONTROL_URL ?? 'http://127.0.0.1:8844' },
};
type Name = keyof typeof servers;
const names: Name[] = ['ts', 'php'];
const trace: string[] = [];
const badRefusals: string[] = [];

function mulberry32(s: number) {
  let a = s >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rng = mulberry32(seed);
const int = (n: number) => Math.floor(rng() * n);
const pick = <T>(xs: T[]): T => xs[int(xs.length)];

const schema = defineSchema({
  dossier: { agent: lww(), zone: lww(), client_name: lww(), status: conflict(), visits: counter(), docs: set() },
});

// Requests applied per server, by kind (a lost response still counts: the server applied it).
const served: Record<Name, { push: number; pull: number }> = { ts: { push: 0, pull: 0 }, php: { push: 0, pull: 0 } };

async function control(name: Name, method: string, path: string): Promise<any> {
  const res = await fetch(servers[name].control + path, { method });
  const body = await res.json();
  if (!res.ok) throw new Error(`${name} control ${path}: ${res.status} ${JSON.stringify(body)}`);
  return body;
}
const token = async (sub: string, zones: string[]) =>
  (await control(pick(names), 'GET', `/token?sub=${sub}${zones.map((z) => `&zone=${z}`).join('')}`)).token as string;

interface Device {
  id: string;
  user: string;
  client: any;
  net: { loss: number; losePushResponse: boolean };
}

function device(id: string, user: string, tokens: Record<string, string>, devSeed: number): Promise<Device> {
  const net = { loss: 0.25, losePushResponse: false };
  const r = mulberry32(devSeed);
  // Each request goes to a random server, and is lost before, or after the server applied it.
  const flaky = async (input: string, init: RequestInit) => {
    const name = names[Math.floor(r() * 2)];
    const url = new URL(input);
    const target = servers[name].url + url.pathname + url.search;
    if (r() < net.loss / 2) throw new TypeError('network down (request lost)');
    const res = await fetch(target, init);
    if (res.ok) served[name][url.pathname.endsWith('/push') ? 'push' : 'pull']++;
    if (process.env.ACCORD_FLEET_DEBUG && url.pathname.endsWith('/pull')) {
      const b = await res.clone().text();
      if (b.includes('dossier:4') && (b.includes('exit') || b.includes('snapshot'))) trace.push(`  ${id} pull via ${name} ${b.slice(0, 300)}`);
    }
    const forced = net.losePushResponse && url.pathname.endsWith('/push');
    if (forced) net.losePushResponse = false;
    if (forced || r() < net.loss / 2) {
      trace.push(`  ${id} lost response ${url.pathname}${url.search} via ${name}`);
      throw new TypeError('network down (response lost)');
    }
    return res;
  };
  return AccordClient.open({
    schema,
    storage: new MemoryStorage(),
    deviceId: id,
    pushBatch: 20,
    pullLimit: 10,
    transport: httpTransport({ url: servers.ts.url, getToken: () => tokens[user], fetch: flaky as typeof fetch }),
  }).then((client: any) => {
    for (const ev of ['refused', 'resync']) client.on(ev, (e: unknown) => trace.push(`${id} ${ev} ${JSON.stringify(e)}`));
    // The only refusals this fleet provokes are writes out of the writer's scope. A retry of such a
    // refusal whose response was lost, after a later op of the same device was applied, is refused
    // again as "numbered above" (the op was never stored): same outcome for the client. Any other
    // reason (e.g. "names another op") means a server refused an op it had applied.
    client.on('refused', (e: { reason: string }) => {
      const ok = e.reason.startsWith('out of scope') || e.reason.includes("this device's ops are numbered above");
      if (!ok) badRefusals.push(`${id}: ${JSON.stringify(e)}`);
    });
    return { id, user, client, net };
  });
}

const tokens: Record<string, string> = {
  awa: await token('awa', ['dakar']),
  moussa: await token('moussa', ['dakar']),
};
const devices = [
  await device('awa-1', 'awa', tokens, seed * 7 + 1),
  await device('awa-2', 'awa', tokens, seed * 7 + 2),
  await device('moussa-1', 'moussa', tokens, seed * 7 + 3),
  await device('moussa-2', 'moussa', tokens, seed * 7 + 4),
];
const shared = ['dossier:1', 'dossier:2', 'dossier:é'];
const extra = 'dossier:4'; // owned by moussa, in zone thies: awa sees it only with the thies zone
const moving = 'dossier:5'; // moussa's, in zone kaolack, moves into dakar mid-run (a feed scope row)
let awaHasThies = false;
let moved = false;

const syncQuiet = async (d: Device) => {
  try {
    await d.client.sync();
  } catch {
    /* the network is lossy: try again later */
  }
};
const setLoss = (l: number) => devices.forEach((d) => (d.net.loss = l));
async function settle() {
  setLoss(0);
  // Three rounds: a device's cursor is recorded one pull behind, and pushes made in round one by
  // later devices move the feed past earlier devices again.
  for (let round = 0; round < 3; round++) for (const d of devices) await d.client.sync();
}
// Both servers commit a scope delta (entering history, exits) when they answer the pull that
// carries it: if that response is lost, the next pull sees no change and the device never gets
// it (README "Known server bug"). Until that is fixed, scope changes are pulled on a healed
// network; ACCORD_FLEET_LOSSY_SCOPES=1 keeps the network broken and reproduces the bug.
async function retoken(zones: string[]) {
  tokens.awa = await token('awa', zones);
  awaHasThies = zones.includes('thies');
  if (process.env.ACCORD_FLEET_LOSSY_SCOPES) return;
  await settle();
  setLoss(0.25);
}

await devices[0].client.assign(shared[0], 'zone', 'dakar');
await devices[2].client.assign(shared[1], 'zone', 'dakar');
await devices[1].client.assign(shared[2], 'zone', 'dakar');
await devices[3].client.assign(extra, 'agent', 'moussa');
await devices[3].client.assign(extra, 'zone', 'thies');
await devices[2].client.assign(moving, 'agent', 'moussa');
await devices[2].client.assign(moving, 'zone', 'kaolack');

const awkward = () => [null, 2.5, 1e21, { '10': true, a: 1e21, n: int(9) }, 'é', '', '😀', 's0'][int(8)];
const element = () => (rng() < 0.5 ? pick(['doc-1', 'é', '', '😀', '1']) : pick([0, 1, 2.5, 1e21]));

let folded = 0;
const op = (o: any) => trace.push(`  op ${o.opId} ${o.record} ${o.field} ${JSON.stringify(o)}`);
const compactedBy: Name[] = [];
for (let step = 0; step < 200; step++) {
  const d = devices[int(devices.length)];
  const canSeeExtra = d.user === 'moussa' || awaHasThies;
  const visible = [...shared, ...(canSeeExtra ? [extra] : []), ...(d.user === 'moussa' || moved ? [moving] : [])];
  const record = pick(visible);
  if (process.env.ACCORD_FLEET_DEBUG) trace.push(`step ${step} ${d.id} ${record}`);
  switch (int(7)) {
    case 0: op(await d.client.inc(record, 'visits', int(9) - 3)); break;
    case 1: op(await d.client.add(record, 'docs', element())); break;
    case 2: op(await d.client.remove(record, 'docs', element())); break;
    case 3: op(await d.client.assign(record, 'status', awkward())); break;
    case 4: op(await d.client.assign(record, 'client_name', awkward())); break;
    case 5: await syncQuiet(d); break;
    // Every device at once: concurrent pushes and pulls on both servers (record locks, horizon).
    default: await Promise.all(devices.map(syncQuiet));
  }
  if (step === 40) {
    op(await devices[2 + int(2)].client.assign(moving, 'zone', 'dakar')); // enters awa's devices
    moved = true;
  }
  if (step === 60) await retoken(['dakar', 'thies']); // dossier:4 enters awa's devices
  if (step === 100) {
    // Heal, settle, compact through a random server, break the network again: later lost-response
    // retries then hit ops that were folded into snapshots.
    await settle();
    // A retired device's push is applied but its response lost, then its op is folded: its retry
    // must be acknowledged by whichever server answers (compacted_ops.op_hash), not refused.
    const retired = pick(devices);
    op(await retired.client.inc(shared[0], 'visits', 1));
    retired.net.losePushResponse = true;
    await syncQuiet(retired);
    for (let round = 0; round < 3; round++) for (const d of devices) if (d !== retired) await d.client.sync();
    await control(pick(names), 'POST', `/age-device?device=${retired.id}&days=31`);
    const by = pick(names);
    compactedBy.push(by);
    const result = await control(by, 'POST', '/compact');
    folded = Number(result.records);
    assert.ok(folded > 0, `the mid-run compaction via ${by} folded nothing: ${JSON.stringify(result)}`);
    setLoss(0.25);
  }
  if (step === 130) await retoken(['dakar']); // dossier:4 exits awa's devices
  if (step === 160) await retoken(['dakar', 'thies']); // and enters again
}

await settle();
const snapshots = Object.fromEntries(
  devices.map((d) => [d.id, canonicalJson(Object.fromEntries(d.client.records().map((r: string) => [r, d.client.read(r)])))]),
);
assert.deepEqual(badRefusals, [], 'unexpected refusals');
for (const d of devices) assert.equal(d.client.status().pending, 0, `${d.id} still has pending ops`);
const distinct = new Set(Object.values(snapshots));
if (distinct.size > 1) console.error(trace.join('\n'));
assert.equal(distinct.size, 1, `snapshots differ:\n${JSON.stringify(snapshots, null, 1)}`);
const snap = [...distinct][0];
for (const r of [...shared, extra, moving]) assert.ok(snap.includes(JSON.stringify(r)), `${r} missing from ${snap}`);
for (const n of names) {
  assert.ok(served[n].push > 0 && served[n].pull > 0, `${n} served ${JSON.stringify(served[n])}`);
}
for (const d of devices) await d.client.close();
console.log(
  `seed ${seed}: converged on ${snap.length} bytes; ${folded} records compacted via ${compactedBy}; ` +
    `served ts push=${served.ts.push} pull=${served.ts.pull}, php push=${served.php.push} pull=${served.php.pull}`,
);

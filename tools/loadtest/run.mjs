// Load test: signs in with a real browser (Playwright) to get session cookies,
// then sends concurrent page requests with fetch and reports latency.
//
//   node tools/loadtest/run.mjs --base=http://load-test.schoolsaas.test:8000 --concurrency=10 --seconds=30
//
// Needs Playwright with Chromium. Point it at a staging copy, never at production.
import http from 'node:http';
import { createRequire } from 'node:module';

// CommonJS require so a globally installed Playwright (NODE_PATH) also works.
const { chromium } = createRequire(import.meta.url)('playwright');

const arg = (name, fallback) => (process.argv.find((a) => a.startsWith(`--${name}=`)) ?? `=${fallback}`).split('=').slice(1).join('=');
const base = arg('base', 'http://load-test.schoolsaas.test:8000');
const concurrency = Number(arg('concurrency', 10));
const seconds = Number(arg('seconds', 30));
const resolver = arg('resolve', 'MAP *.schoolsaas.test 127.0.0.1');

async function cookiesFor(email, landing) {
    const browser = await chromium.launch({ args: [`--host-resolver-rules=${resolver}`] });
    const page = await (await browser.newContext()).newPage();
    await page.goto(`${base}/login`);
    await page.fill('#email', email);
    await page.fill('#password', 'password');
    await page.click('[data-test=login-button]');
    await page.waitForURL(landing);
    const cookies = (await page.context().cookies()).map((c) => `${c.name}=${c.value}`).join('; ');
    const url = page.url();
    await browser.close();

    return { cookies, url };
}

const staff = await cookiesFor(arg('staff', 'owner@load-test.test'), '**/app/dashboard');
const parent = await cookiesFor(arg('parent', 'parent@load-test.test'), '**/parent/children/*');
const child = parent.url.replace(base, '');

const scenarios = [
    { name: 'public home', path: '/', cookies: '' },
    { name: 'public notices', path: '/notices?page=2', cookies: '' },
    { name: 'staff dashboard', path: '/app/dashboard', cookies: staff.cookies },
    { name: 'student list', path: '/app/students?page=5', cookies: staff.cookies },
    { name: 'student search', path: '/app/students?search=Student%2012', cookies: staff.cookies },
    { name: 'fee invoices', path: '/app/fees/invoices?status=overdue', cookies: staff.cookies },
    { name: 'fees dashboard', path: '/app/fees', cookies: staff.cookies },
    { name: 'parent overview', path: child, cookies: parent.cookies },
    { name: 'parent attendance', path: `${child}/attendance`, cookies: parent.cookies },
    { name: 'parent fees', path: `${child}/fees`, cookies: parent.cookies },
];

// Requests go to the --connect address with the school's Host header
// (the same mapping the browser used), over keep-alive connections.
const url = new URL(base);
const connect = arg('connect', '127.0.0.1');
const agent = new http.Agent({ keepAlive: true, maxSockets: concurrency });
const get = (path, cookies) => new Promise((resolve, reject) => {
    const req = http.get({ host: connect, port: url.port || 80, path, agent, headers: { Host: url.host, Cookie: cookies, Accept: 'text/html' } }, (res) => {
        res.resume();
        res.on('end', () => resolve(res.statusCode));
    });
    req.on('error', reject);
});
const results = Object.fromEntries(scenarios.map((s) => [s.name, []]));
let errors = 0;
const end = Date.now() + seconds * 1000;

async function worker(id) {
    let i = id;
    while (Date.now() < end) {
        const s = scenarios[i++ % scenarios.length];
        const t0 = performance.now();
        try {
            const status = await get(s.path, s.cookies);
            if (status !== 200) errors++;
        } catch {
            errors++;
        }
        results[s.name].push(performance.now() - t0);
    }
}

await Promise.all(Array.from({ length: concurrency }, (_, i) => worker(i)));

const pct = (arr, p) => arr.length ? arr.slice().sort((a, b) => a - b)[Math.min(arr.length - 1, Math.floor((p / 100) * arr.length))] : 0;
const total = Object.values(results).reduce((n, a) => n + a.length, 0);
console.log(`\n${total} requests in ${seconds}s, ${concurrency} concurrent → ${(total / seconds).toFixed(1)} req/s, ${errors} errors\n`);
console.log('scenario'.padEnd(20), 'n'.padStart(5), 'p50 ms'.padStart(8), 'p95 ms'.padStart(8), 'max ms'.padStart(8));
for (const [name, arr] of Object.entries(results)) {
    console.log(name.padEnd(20), String(arr.length).padStart(5), pct(arr, 50).toFixed(0).padStart(8), pct(arr, 95).toFixed(0).padStart(8), Math.max(0, ...arr).toFixed(0).padStart(8));
}

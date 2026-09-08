import fs from 'node:fs';

const [, , url, out, width, height] = process.argv;

const listRes = await fetch('http://127.0.0.1:9222/json/new?' + encodeURIComponent(url), { method: 'PUT' });
const target = await listRes.json();
const ws = new WebSocket(target.webSocketDebuggerUrl);

let id = 0;
const pending = new Map();
const send = (method, params = {}) => new Promise((resolve) => {
  const msgId = ++id;
  pending.set(msgId, resolve);
  ws.send(JSON.stringify({ id: msgId, method, params }));
});

await new Promise((r) => ws.addEventListener('open', r));
ws.addEventListener('message', (e) => {
  const msg = JSON.parse(e.data);
  if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg.result); pending.delete(msg.id); }
});

await send('Page.enable');
await send('Emulation.setDeviceMetricsOverride', {
  width: Number(width), height: Number(height), deviceScaleFactor: 1, mobile: false,
});

// The page sets its title to READY only once page.render() has resolved, so
// polling that is the only honest signal that there is something to capture.
const deadline = Date.now() + 180000;
let title = '';
while (Date.now() < deadline) {
  const res = await send('Runtime.evaluate', { expression: 'document.title', returnByValue: true });
  title = res?.result?.value ?? '';
  if (title.startsWith('READY') || title.startsWith('ERROR')) break;
  await new Promise((r) => setTimeout(r, 500));
}
console.log('title:', title);

if (title.startsWith('READY')) {
  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
  fs.writeFileSync(out, Buffer.from(shot.data, 'base64'));
  console.log('written', out);
}
ws.close();
process.exit(0);

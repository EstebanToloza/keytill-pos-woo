import puppeteer from 'puppeteer-core';
const b = await puppeteer.launch({
  executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  headless: 'new',
  args: ['--allow-file-access-from-files', '--force-device-scale-factor=1'],
});
const p = await b.newPage();
await p.setViewport({ width: 300, height: 300, deviceScaleFactor: 1 });
await p.goto('file://' + process.cwd() + '/icon.html', { waitUntil: 'networkidle0' });
await p.evaluate(() => document.fonts.ready);
await new Promise(r => setTimeout(r, 400));
await p.screenshot({ path: 'icon-300.png', omitBackground: true });
// variantes que pide WordPress.org para el listado
for (const s of [128, 256]) {
  await p.setViewport({ width: 300, height: 300, deviceScaleFactor: s / 300 });
  await p.screenshot({ path: `icon-${s}.png`, omitBackground: true });
}
await b.close();
console.log('listo');

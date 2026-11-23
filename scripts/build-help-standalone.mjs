#!/usr/bin/env node
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// Web版を正本にし、同じ案内をネット接続なしで開ける単体HTMLへまとめる。
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const version = readFileSync(resolve(root, 'VERSION'), 'utf8').trim();
if (!/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/i.test(version)) throw new Error('Invalid version');
let html = readFileSync(resolve(root, 'website/help.html'), 'utf8');
for (const name of ['styles.css', 'help.css']) {
  const css = readFileSync(resolve(root, 'website', name), 'utf8');
  if (css.includes('</style')) throw new Error('Unexpected style terminator');
  html = html.replace(`<link rel="stylesheet" href="${name}">`, `<style>\n${css}\n</style>`);
}
const script = readFileSync(resolve(root, 'website/help.js'), 'utf8');
if (script.includes('</script')) throw new Error('Unexpected script terminator');
html = html.replace('<script src="help.js" defer></script>', '');
html = html.replace('</body>', `<script>\n${script}\n</script></body>`);
for (const name of ['logo.png', 'mark.png']) {
  const data = readFileSync(resolve(root, 'website/assets', name)).toString('base64');
  html = html.replaceAll(`assets/${name}`, `data:image/png;base64,${data}`);
}
// 単体ファイルでは存在しない製品ページへの相対リンクを残さない。
html = html.replaceAll('href="index.html"', 'href="#top"')
  .replaceAll('SiteGrowKit 製品紹介', 'SiteGrowKit ガイド')
  .replaceAll('>製品紹介へ戻る<', '>ガイドの先頭へ<')
  .replaceAll('>製品紹介<', '>ガイド<');
const folder = resolve(root, 'release', `agent-cms-kit-v${version}`);
mkdirSync(folder, { recursive: true });
for (const target of [resolve(folder, 'README_FIRST.html'), resolve(root, 'release', `README_FIRST-${version}.html`)]) {
  writeFileSync(target, html);
  console.log(target);
}

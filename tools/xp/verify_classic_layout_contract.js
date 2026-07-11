/*
 * Classic 2001-era layout contract (host-side, ships with tools/xp).
 *
 * Reads the shipped Pages sources so a broken DOCTYPE/centering/modern
 * layout cannot land without a red light. Node is the runner; assertions
 * target IE6-era files only (not Super-Modern).
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const failures = [];

function read(rel) {
  return fs.readFileSync(path.join(root, rel), 'utf8');
}

function stripComments(text) {
  var out = text.replace(/\/\*[\s\S]*?\*\//g, '');
  out = out.replace(/<!--[\s\S]*?-->/g, '');
  return out;
}

/* ---- classic HTML: DOCTYPE first, no XML prolog ---- */
['Pages/index.htm', 'Pages/chat.htm'].forEach(function (file) {
  const raw = read(file).replace(/^\uFEFF/, '').replace(/^\s+/, '');
  if (/^<\?xml\b/i.test(raw)) {
    failures.push(file + ' must not start with XML prolog (IE6 quirks)');
  }
  if (!/^<!DOCTYPE\b/i.test(raw)) {
    failures.push(file + ' must start with <!DOCTYPE');
  }
  if (!/XHTML 1\.0 Transitional/i.test(raw)) {
    failures.push(file + ' classic surface must stay XHTML 1.0 Transitional');
  }
});

/* ---- classic CSS: centering belt + no modern layout modes ---- */
const css = stripComments(read('Pages/css/main.css'));
if (!/body\s*\{[^}]*text-align\s*:\s*center/i.test(css)) {
  failures.push('main.css body needs text-align:center for IE6 centering');
}
if (!/\.wrap\s*\{[^}]*text-align\s*:\s*left/i.test(css)) {
  failures.push('main.css .wrap needs text-align:left');
}
if (!/\.app-shell\s*\{[^}]*text-align\s*:\s*left/i.test(css)) {
  failures.push('main.css .app-shell needs text-align:left');
}
if (!/\.login-wrap\s*\{[^}]*text-align\s*:\s*left/i.test(css)) {
  failures.push('main.css .login-wrap needs text-align:left');
}
if (!/\.wrap\s*\{[^}]*margin\s*:\s*0\s+auto/i.test(css)
    && !/\.wrap\s*\{[^}]*margin:\s*0 auto/i.test(css)) {
  failures.push('main.css .wrap needs margin:0 auto');
}
if (!/\.app-shell\s*\{[^}]*margin\s*:\s*0\s+auto/i.test(css)) {
  failures.push('main.css .app-shell needs margin:0 auto');
}
if (/display\s*:\s*(flex|grid)/i.test(css)) {
  failures.push('main.css must not use flex/grid (classic float era only)');
}
if (/box-sizing\s*:/i.test(css)) {
  failures.push('main.css must not use box-sizing');
}

/* ---- Super-Modern remains isolated contrast, not the chat client ---- */
const sm = read('Pages/super-modern.htm');
if (!/<main\b/i.test(sm)) {
  failures.push('super-modern.htm should use <main> for modern contrast');
}
if (!/@media\b/i.test(sm)) {
  failures.push('super-modern.htm should keep modern @media contrast');
}
const chat = read('Pages/chat.htm');
if (/<main\b/i.test(chat) || /@media\b/i.test(chat)) {
  failures.push('chat.htm must stay classic (no <main>/@media)');
}

/* ---- router: IE/Trident never gets Super-Modern ---- */
const router = read('Pages/router.php');
if (router.indexOf('MSIE') === -1 || router.indexOf('Trident/') === -1) {
  failures.push('router.php must gate Super-Modern off for MSIE/Trident UAs');
}
if (router.indexOf('super-modern.htm') === -1) {
  failures.push('router.php must reference super-modern.htm for modern hosts');
}
if (router.indexOf('sc_client_is_ie') === -1
    && router.indexOf('$sc_client_is_ie') === -1) {
  failures.push('router.php must compute client IE flag before modern redirect');
}

if (failures.length) {
  console.error('FAIL');
  failures.forEach(function (f) { console.error('- ' + f); });
  process.exit(1);
}
console.log('PASS classic layout + modern-contrast isolation contract');

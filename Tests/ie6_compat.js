/*
 * IE6 / XP-era frontend compatibility guard.
 *
 * Application pages must stay ES3-style and classic CSS must remain inside
 * IE6-era selector/property support.
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const files = [
  'Pages/js/api.js',
  'Pages/js/app.js',
  'Pages/js/chat.js',
  'Pages/js/i18n.js',
  'Pages/index.htm',
  'Pages/chat.htm'
];

const banned = [
  /\blet\s+/,
  /\bconst\s+/,
  /=>/,
  /\bfetch\s*\(/,
  /\bPromise\b/,
  /\blocalStorage\b/,
  /\bFormData\b/,
  /\bquerySelector\b/,
  /\.forEach\s*\(/,
  /\.map\s*\(/,
  /\.filter\s*\(/,
  /\bclassList\b/
];

const failures = [];

function stripComments(text) {
  var out = text.replace(/\/\*[\s\S]*?\*\//g, '');
  out = out.replace(/<!--[\s\S]*?-->/g, '');
  out = out.replace(/(^|[^:])\/\/.*$/gm, '$1');
  return out;
}

for (let i = 0; i < files.length; i++) {
  const file = files[i];
  const text = stripComments(
    fs.readFileSync(path.join(root, file), 'utf8')
  );
  for (let j = 0; j < banned.length; j++) {
    if (banned[j].test(text)) {
      failures.push(file + ' contains IE6-incompatible pattern '
        + String(banned[j]));
    }
  }
}

const css = stripComments(
  fs.readFileSync(path.join(root, 'Pages/css/main.css'), 'utf8')
);
const bannedCss = [
  /input\s*\[\s*type/i,
  /box-sizing\s*:/i,
  /border-radius\s*:/i,
  /box-shadow\s*:/i,
  /transition\s*:/i,
  /transform\s*:/i,
  /@keyframes/i,
  /linear-gradient/i,
  /display\s*:\s*(flex|grid)/i
];

for (let i = 0; i < bannedCss.length; i++) {
  if (bannedCss[i].test(css)) {
    failures.push('Pages/css/main.css contains XP-incompatible CSS pattern '
      + String(bannedCss[i]));
  }
}

if (failures.length) {
  console.error('FAIL');
  for (let i = 0; i < failures.length; i++) {
    console.error('- ' + failures[i]);
  }
  process.exit(1);
}

console.log('PASS');

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const failures = [];

function read(name) {
  return fs.readFileSync(path.join(root, name), 'utf8');
}

function addFailure(message) {
  failures.push(message);
}

function keysFromPhpLang(file) {
  const text = read(file);
  const out = {};
  const re = /'([^']+)'\s*=>/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    out[m[1]] = true;
  }
  return out;
}

function exposeClientTables() {
  let src = read('Pages/js/i18n.js');
  src = src.replace(/\}\)\(\);\s*$/,
                    'window.__BUNDLED=BUNDLED; window.__FALLBACK_EN=FALLBACK_EN;}());');
  const ctx = {
    window: {},
    XMLHttpRequest: function () { throw new Error('no xhr'); },
    ActiveXObject: function () { throw new Error('no activex'); }
  };
  ctx.window = ctx;
  vm.runInNewContext(src, ctx, { filename: 'Pages/js/i18n.js' });
  return { fallback: ctx.__FALLBACK_EN, bundled: ctx.__BUNDLED };
}

function collectUsedClientKeys() {
  const files = [
    'Pages/index.htm',
    'Pages/chat.htm',
    'Pages/js/app.js',
    'Pages/js/chat.js'
  ];
  const used = {};
  const patterns = [
    /data-i18n(?:-title)?="([^"]+)"/g,
    /\b(?:tr|sc_tr)\(\s*'([^']+)'/g,
    /\b(?:tr|sc_tr)\(\s*"([^"]+)"/g,
    /\bi18n\.t\(\s*'([^']+)'/g,
    /\bi18n\.t\(\s*"([^"]+)"/g
  ];
  for (let i = 0; i < files.length; i++) {
    const text = read(files[i]);
    for (let j = 0; j < patterns.length; j++) {
      let m;
      while ((m = patterns[j].exec(text)) !== null) {
        used[m[1]] = true;
      }
    }
  }
  return Object.keys(used).sort();
}

const tables = exposeClientTables();
const fallbackKeys = Object.keys(tables.fallback).sort();
const usedKeys = collectUsedClientKeys();

for (let i = 0; i < usedKeys.length; i++) {
  if (!tables.fallback[usedKeys[i]]) {
    addFailure('FALLBACK_EN missing used key ' + usedKeys[i]);
  }
}

const langs = ['zh-CN', 'zh-TW', 'en', 'ja', 'ko', 'ru', 'fr', 'de'];
for (let i = 0; i < langs.length; i++) {
  const lang = langs[i];
  const client = tables.bundled[lang] || {};
  for (let j = 0; j < fallbackKeys.length; j++) {
    if (!(fallbackKeys[j] in client)) {
      addFailure('BUNDLED ' + lang + ' missing ' + fallbackKeys[j]);
    }
  }
  const server = keysFromPhpLang('Server/langs/' + lang + '.php');
  for (let k = 0; k < fallbackKeys.length; k++) {
    if (!server[fallbackKeys[k]]) {
      addFailure('Server/langs/' + lang + '.php missing ' + fallbackKeys[k]);
    }
  }
}

const sameAllowed = {
  'app.title': true,
  'chat.api': true,
  'chat.tokens': true,
  'role.assistant': true
};
const englishClient = tables.bundled.en;
for (let i = 0; i < langs.length; i++) {
  const lang = langs[i];
  if (lang === 'en') {
    continue;
  }
  const client = tables.bundled[lang] || {};
  for (let j = 0; j < fallbackKeys.length; j++) {
    const key = fallbackKeys[j];
    if (!sameAllowed[key] && client[key] === englishClient[key]) {
      addFailure('BUNDLED ' + lang + ' still equals English for ' + key);
    }
  }
}

const app = read('Pages/js/app.js');
if (app.indexOf('then click "Reload config"') !== -1) {
  addFailure('Edit-config alert still tells user to click Reload config');
}

const hist = read('Server/api/history.php');
if (hist.indexOf("sc_t('new_chat'") !== -1) {
  addFailure('history API still uses stale i18n key new_chat');
}

const allText = [
  'CONF_SMP.INI',
  'README.org',
  'Pages/js/app.js',
  'Pages/js/chat.js',
  'Pages/js/api.js',
  'Pages/js/i18n.js',
  'Server/auth.php',
  'Server/config.php',
  'Server/api/config.php'
].map(read).join('\n');

[
  'auto_name',
  'font_profile',
  'allow_models',
  'allow_config',
  'guesspass'
].forEach(function (word) {
  if (allText.indexOf(word) !== -1) {
    addFailure('stale config/function word remains: ' + word);
  }
});

if (failures.length) {
  console.error('FAIL');
  for (let i = 0; i < failures.length; i++) {
    console.error('- ' + failures[i]);
  }
  process.exit(1);
}

console.log('PASS');

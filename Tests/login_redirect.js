const fs = require('fs');
const vm = require('vm');

const html = fs.readFileSync('Pages/index.htm', 'utf8');

function fail(message) {
  console.error('FAIL: ' + message);
  process.exit(1);
}

if (html.indexOf('result.data.lang') === -1) {
  fail('login redirect must read lang from the API envelope data field');
}

if (html.indexOf('result.lang ? String(result.lang) : defaultLang') !== -1) {
  fail('login redirect must not read result.lang or the local init defaultLang');
}

if (html.indexOf("window.location.href = 'chat.htm?lang='") === -1) {
  fail('login success should redirect to chat.htm with a language parameter');
}

const scriptMatch = html.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/);
if (!scriptMatch) {
  fail('login page script block not found');
}

const nodes = {
  password: { value: 'admin123', focus: function () {}, select: function () {} },
  'login-error': { textContent: '' },
  'login-submit': { disabled: false, value: 'Sign in' }
};

const context = {
  console: console,
  encodeURIComponent: encodeURIComponent,
  String: String,
  window: {
    location: { href: '' },
    SC: {
      Api: {
        login: function () {
          return { ok: true, data: { lang: 'en', username: 'Admin' }, error: '' };
        }
      },
      I18n: {
        t: function (key) { return key; },
        getLang: function () { return 'zh-CN'; }
      }
    }
  },
  document: {
    getElementById: function (id) {
      return nodes[id] || null;
    }
  }
};
context.window.window = context.window;
context.window.document = context.document;

try {
  vm.runInNewContext(scriptMatch[1], context, { filename: 'Pages/index.htm' });
  const ret = context.window.scLoginSubmit({});
  if (ret !== false) {
    fail('login submit should suppress the native form post');
  }
} catch (err) {
  fail('login success branch threw: ' + err.message);
}

if (context.window.location.href !== 'chat.htm?lang=en') {
  fail('login success redirected to ' + context.window.location.href);
}

console.log('PASS');

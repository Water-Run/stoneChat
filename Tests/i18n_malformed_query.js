const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('Pages/js/i18n.js', 'utf8');

function FakeXhr() {
  this.status = 0;
  this.responseText = '';
}
FakeXhr.prototype.open = function() {};
FakeXhr.prototype.send = function() {};

const context = {
  console: console,
  location: { search: '?lang=%E0%A4%', pathname: '/Pages/index.htm' },
  XMLHttpRequest: FakeXhr,
  ActiveXObject: function() { throw new Error('no activex'); }
};
context.window = context;

vm.createContext(context);
vm.runInContext(source, context, { filename: 'Pages/js/i18n.js' });

try {
  context.SC.I18n.init(['zh-CN', 'en'], 'en');
} catch (err) {
  console.error('FAIL: malformed query should not throw');
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(1);
}

if (context.SC.I18n.getLang() !== 'en') {
  console.error('FAIL: malformed query should fall back to default language');
  process.exit(1);
}

console.log('PASS');

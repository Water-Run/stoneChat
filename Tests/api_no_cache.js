const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('Pages/js/api.js', 'utf8');

function FakeXhr() {
  this.status = 200;
  this.responseText = '{"title":"stoneChat"}';
  this.headers = {};
  FakeXhr.last = this;
}

FakeXhr.prototype.open = function(method, url, async) {
  this.method = method;
  this.url = url;
  this.async = async;
};
FakeXhr.prototype.setRequestHeader = function(name, value) {
  this.headers[name] = value;
};
FakeXhr.prototype.send = function(payload) {
  this.payload = payload;
};
FakeXhr.prototype.abort = function() {};

const context = {
  console: console,
  JSON: JSON,
  XMLHttpRequest: FakeXhr,
  setTimeout: function() { return 1; },
  clearTimeout: function() {},
  Date: Date
};
context.window = context;

vm.createContext(context);
vm.runInContext(source, context, { filename: 'Pages/js/api.js' });

context.SC.Api.getConfig();

if (!FakeXhr.last || FakeXhr.last.url.indexOf('config.php?') === -1
    || FakeXhr.last.url.indexOf('_sc=') === -1) {
  console.error('FAIL: GET config.php must include a cache-buster query');
  process.exit(1);
}

if (FakeXhr.last.headers['Cache-Control'] !== 'no-cache'
    || FakeXhr.last.headers.Pragma !== 'no-cache'
    || FakeXhr.last.headers['If-Modified-Since'] !== 'Sat, 1 Jan 2000 00:00:00 GMT') {
  console.error('FAIL: XHR GET must send IE-safe no-cache headers');
  process.exit(1);
}

console.log('PASS');

const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('Pages/js/api.js', 'utf8');

function FakeXhr() {
  this.status = 200;
  this.responseText = '<?php broken backend page';
  this.headers = {};
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
  setTimeout: function(fn, ms) { return 1; },
  clearTimeout: function(id) {},
};
context.window = context;

vm.createContext(context);
vm.runInContext(source, context, { filename: 'Pages/js/api.js' });

let result;
try {
  result = context.SC.Api.getConfig();
} catch (err) {
  console.error('FAIL: getConfig threw instead of returning envelope');
  console.error(err && err.stack ? err.stack : String(err));
  process.exit(1);
}

if (!result || result.ok !== false
    || typeof result.error !== 'string'
    || result.error.indexOf('invalid_json:') !== 0) {
  console.error('FAIL: expected invalid_json envelope, got:');
  console.error(JSON.stringify(result));
  process.exit(1);
}

console.log('PASS');

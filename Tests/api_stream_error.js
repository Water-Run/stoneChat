const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('Pages/js/api.js', 'utf8');

function FakeXhr() {
  this.status = 200;
  this.readyState = 0;
  this.responseText = '';
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
FakeXhr.prototype.getResponseHeader = function(name) {
  if (String(name).toLowerCase() === 'content-type') {
    return 'text/event-stream';
  }
  return '';
};
FakeXhr.prototype.send = function(payload) {
  this.payload = payload;
  this.readyState = 3;
  this.responseText = 'data: {"error":"bad_chat_id"}\n\n';
  this.onreadystatechange();
  this.readyState = 4;
  this.onreadystatechange();
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

let chunks = [];
let complete = null;
context.SC.Api.sendMessageStream(
  'bad/chat',
  'hello',
  function(chunk) {
    chunks.push(chunk);
  },
  function(result) {
    complete = result;
  }
);

if (chunks.length !== 0) {
  console.error('FAIL: error event should not emit content chunks');
  process.exit(1);
}

if (!complete || complete.ok !== false || complete.error !== 'bad_chat_id') {
  console.error('FAIL: expected bad_chat_id error envelope, got:');
  console.error(JSON.stringify(complete));
  process.exit(1);
}

console.log('PASS');

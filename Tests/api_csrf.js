const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('Pages/js/api.js', 'utf8');
const requests = [];

function FakeXhr() {
  this.status = 200;
  this.readyState = 0;
  this.responseText = '{"ok":true}';
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
    return this.contentType || 'application/json';
  }
  return '';
};
FakeXhr.prototype.send = function(payload) {
  this.payload = payload;
  requests.push(this);
  if (this.url.indexOf('auth.php') !== -1
      && payload && payload.indexOf('"check"') !== -1) {
    this.responseText = JSON.stringify({
      ok: true,
      csrf: {
        'auth:logout': 'tok-logout',
        'chat:send': 'tok-send',
        'chat:name': 'tok-name',
        'config:reload': 'tok-reload',
        'history:delete': 'tok-delete',
        'history:new': 'tok-new',
        'history:rename': 'tok-rename'
      }
    });
  } else {
    this.responseText = '{"ok":true}';
  }
  if (this.async) {
    this.readyState = 4;
    if (typeof this.onreadystatechange === 'function') {
      this.onreadystatechange();
    }
  }
};
FakeXhr.prototype.abort = function() {};

function fail(message) {
  console.error('FAIL: ' + message);
  process.exit(1);
}

function lastJson() {
  const req = requests[requests.length - 1];
  if (!req || !req.payload) {
    fail('missing request payload');
  }
  return JSON.parse(req.payload);
}

const context = {
  console: console,
  JSON: JSON,
  XMLHttpRequest: FakeXhr,
  encodeURIComponent: encodeURIComponent,
  setTimeout: function() { return 1; },
  clearTimeout: function() {},
  Date: Date
};
context.window = context;

vm.createContext(context);
vm.runInContext(source, context, { filename: 'Pages/js/api.js' });

context.SC.Api.checkAuth();

context.SC.Api.sendMessage('chat-one', 'hello');
if (lastJson().csrf_token !== 'tok-send') {
  fail('sendMessage did not attach chat:send token');
}

context.SC.Api.nameChatAsync('chat-one', 'hello', function() {});
if (lastJson().csrf_token !== 'tok-name') {
  fail('nameChatAsync did not attach chat:name token');
}

context.SC.Api.createChat('MockLocal');
if (lastJson().csrf_token !== 'tok-new') {
  fail('createChat did not attach history:new token');
}

context.SC.Api.renameChat('chat-one', 'XP');
if (lastJson().csrf_token !== 'tok-rename') {
  fail('renameChat did not attach history:rename token');
}

context.SC.Api.deleteChat('a b/../x');
const deleteReq = requests[requests.length - 1];
if (deleteReq.method !== 'POST') {
  fail('deleteChat should use POST so it can carry a CSRF token');
}
if (lastJson().csrf_token !== 'tok-delete') {
  fail('deleteChat did not attach history:delete token');
}

context.SC.Api.reloadConfig();
if (lastJson().csrf_token !== 'tok-reload') {
  fail('reloadConfig did not attach config:reload token');
}

context.SC.Api.logout();
if (lastJson().csrf_token !== 'tok-logout') {
  fail('logout did not attach auth:logout token');
}

context.SC.Api.getChat('a b/../x');
if (FakeXhr.last.url.indexOf('history.php?id=a%20b%2F..%2Fx') === -1) {
  fail('getChat should URL-encode chat id query parameter');
}

console.log('PASS');

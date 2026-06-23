var fs = require('fs');
var vm = require('vm');

function Node(tag, text) {
  this.tagName = tag ? String(tag).toUpperCase() : '';
  this.nodeType = tag ? 1 : 3;
  this.className = '';
  this.children = [];
  this.parentNode = null;
  this.attributes = {};
  this._text = text || '';
}

Node.prototype.appendChild = function (child) {
  this.children.push(child);
  child.parentNode = this;
  return child;
};

Node.prototype.removeChild = function (child) {
  var kept = [];
  for (var i = 0; i < this.children.length; i++) {
    if (this.children[i] !== child) {
      kept.push(this.children[i]);
    }
  }
  this.children = kept;
};

Node.prototype.setAttribute = function (name, value) {
  this.attributes[name] = String(value);
  this[name] = String(value);
};

Node.prototype.getElementsByTagName = function (tag) {
  tag = String(tag).toUpperCase();
  var out = [];
  function walk(node) {
    for (var i = 0; i < node.children.length; i++) {
      var child = node.children[i];
      if (child.tagName === tag) {
        out.push(child);
      }
      walk(child);
    }
  }
  walk(this);
  return out;
};

function getText(node) {
  if (node.nodeType === 3) {
    return node._text;
  }
  var out = '';
  for (var i = 0; i < node.children.length; i++) {
    out += getText(node.children[i]);
  }
  return out;
}

Object.defineProperty(Node.prototype, 'textContent', {
  get: function () {
    return getText(this);
  },
  set: function (value) {
    this.children = [];
    this._text = String(value);
  }
});

var messages = new Node('div');
messages.scrollHeight = 0;
messages.scrollTop = 0;

var document = {
  body: new Node('body'),
  createElement: function (tag) {
    return new Node(tag);
  },
  createTextNode: function (text) {
    return new Node('', text);
  },
  getElementById: function (id) {
    return id === 'chat-messages' ? messages : null;
  },
  getElementsByTagName: function () {
    return { item: function () { return null; }, length: 0 };
  },
  querySelector: function () {
    return null;
  }
};

var context = {
  window: {},
  document: document,
  setInterval: function () { return 1; },
  clearInterval: function () {},
  Date: Date
};
context.window = context;
context.window.SC = { I18n: { t: function (key) { return key; } } };

vm.runInNewContext(
  fs.readFileSync('Pages/js/chat.js', 'utf8'),
  context,
  { filename: 'Pages/js/chat.js' }
);

context.window.SC.Chat.renderMessage(
  'assistant',
  '**bold** and `code`\n- first\n```html\n<x>\n```\n[bad](javascript:alert(1)) [ok](https://example.com)',
  new Date(2026, 0, 2, 3, 4)
);

var bubble = messages.children[0];
var body = bubble.children[1];
var failures = [];

if (body.getElementsByTagName('STRONG').length !== 1) {
  failures.push('bold markdown should render as STRONG');
}
if (body.getElementsByTagName('CODE').length < 2) {
  failures.push('inline and fenced code should render as CODE');
}
if (body.getElementsByTagName('LI').length !== 1) {
  failures.push('dash list item should render as LI');
}
if (body.getElementsByTagName('A').length !== 1) {
  failures.push('only safe links should render as anchors');
}
if (body.getElementsByTagName('A')[0]
    && body.getElementsByTagName('A')[0].href !== 'https://example.com') {
  failures.push('safe link href should be preserved');
}
if (body.textContent.indexOf('javascript:alert') === -1) {
  failures.push('unsafe link text should remain visible as text');
}

if (failures.length) {
  console.log('FAIL');
  for (var i = 0; i < failures.length; i++) {
    console.log('- ' + failures[i]);
  }
  process.exit(1);
}

console.log('PASS');

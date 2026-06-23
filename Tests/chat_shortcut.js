/* stoneChat chat shortcut regression. Node-only DOM shim. */
var fs = require('fs');
var vm = require('vm');

function Node(tag) {
  this.tagName = tag;
  this.children = [];
  this.className = '';
  this.value = '';
  this.disabled = false;
  this.textContent = '';
  this.innerText = '';
}
Node.prototype.appendChild = function (n) {
  this.children.push(n);
  this.lastChild = n;
  return n;
};
Node.prototype.getElementsByTagName = function () { return []; };

function makeDoc() {
  var nodes = {
    'chat-input': new Node('textarea'),
    'send-hint': new Node('span'),
    'char-count': new Node('span'),
    'countdown': new Node('span'),
    'send-button': new Node('a'),
    'stop-button': new Node('a'),
    'regenerate-button': new Node('a'),
    'chat-messages': new Node('div')
  };
  return {
    nodes: nodes,
    createElement: function (tag) { return new Node(tag); },
    getElementById: function (id) { return nodes[id] || null; },
    querySelector: function () { return null; },
    getElementsByTagName: function (tag) {
      if (tag === 'textarea') {
        return { item: function () { return nodes['chat-input']; } };
      }
      return { item: function () { return null; } };
    }
  };
}

function runCase(config, shiftKey, expectedCount, expectedHint) {
  var document = makeDoc();
  var calls = 0;
  var context = {
    window: {
      SC: {},
      setInterval: function () { return 1; },
      clearInterval: function () {}
    },
    document: document,
    Date: Date
  };
  context.window.window = context.window;
  context.window.document = document;
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('Pages/js/chat.js', 'utf8'), context);
  context.window.SC.Chat.init(config, []);
  context.window.SC.Chat.handleEnterKey({
    keyCode: 13,
    shiftKey: shiftKey,
    preventDefault: function () { this.prevented = true; }
  }, function () { calls++; });
  if (calls !== expectedCount) {
    throw new Error('send count expected ' + expectedCount + ' got ' + calls);
  }
  if (document.nodes['send-hint'].textContent !== expectedHint) {
    throw new Error('hint expected "' + expectedHint + '" got "'
      + document.nodes['send-hint'].textContent + '"');
  }
}

runCase({ send_shortcut: 'enter' }, false, 1,
        'Enter发送 / Shift+Enter换行');
runCase({ send_shortcut: 'enter' }, true, 0,
        'Enter发送 / Shift+Enter换行');
runCase({ send_shortcut: 'shift_enter' }, true, 1,
        'Shift+Enter发送 / Enter换行');
runCase({ send_shortcut: 'shift_enter' }, false, 0,
        'Shift+Enter发送 / Enter换行');

console.log('PASS');

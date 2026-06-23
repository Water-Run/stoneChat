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
    'name-button': new Node('a'),
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

function runNameButtonCase() {
  var document = makeDoc();
  var calls = 0;
  var savedCallback = null;
  var context = {
    window: {
      SC: {
        Api: {
          nameChatAsync: function (chatId, message, cb) {
            calls++;
            if (chatId !== 'chat-one') {
              throw new Error('chat id expected chat-one got ' + chatId);
            }
            savedCallback = cb;
          },
          getHistory: function () {
            return { ok: true, data: { conversations: [] } };
          }
        },
        App: {
          renderHistoryList: function () {}
        }
      },
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
  context.window.SC.Chat.init({}, []);
  context.window.SC.Chat.setActiveChatId('chat-one');
  if (typeof document.nodes['name-button'].onclick !== 'function') {
    throw new Error('name button should have onclick');
  }
  document.nodes['name-button'].onclick({});
  if (calls !== 1) {
    throw new Error('nameChatAsync expected 1 call got ' + calls);
  }
  if (document.nodes['name-button'].disabled !== true) {
    throw new Error('name button should be disabled while naming');
  }
  if (document.nodes['name-button'].textContent !== '[Generating...]') {
    throw new Error('name button should show progress');
  }
  savedCallback({ ok: true, data: { chat_name: 'XP Repair' } });
  if (document.nodes['name-button'].disabled !== false) {
    throw new Error('name button should be enabled after naming');
  }
  if (document.nodes['send-hint'].textContent !== 'Title updated') {
    throw new Error('name status should report completion');
  }
}

runNameButtonCase();

function runJapaneseTitleCase() {
  var document = makeDoc();
  var savedCallback = null;
  var context = {
    window: {
      SC: {
        Api: {
          nameChatAsync: function (chatId, message, cb) {
            savedCallback = cb;
          },
          getHistory: function () {
            return { ok: true, data: { conversations: [] } };
          }
        },
        App: {
          renderHistoryList: function () {}
        }
      },
      setInterval: function () { return 1; },
      clearInterval: function () {},
      location: { search: '?lang=ja', pathname: '/Pages/chat.htm' }
    },
    document: document,
    location: { search: '?lang=ja', pathname: '/Pages/chat.htm' },
    XMLHttpRequest: function () {
      this.open = function () {};
      this.send = function () { this.status = 0; this.responseText = ''; };
    },
    ActiveXObject: function () { throw new Error('no activex'); },
    Date: Date
  };
  context.window.window = context.window;
  context.window.document = document;
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('Pages/js/i18n.js', 'utf8'), context);
  context.window.SC.I18n.init(['zh-CN', 'zh-TW', 'en', 'ja'], 'zh-CN');
  if (context.window.SC.I18n.getLang() !== 'ja') {
    throw new Error('expected Japanese language state');
  }
  vm.runInContext(fs.readFileSync('Pages/js/chat.js', 'utf8'), context);
  context.window.SC.Chat.init({}, []);
  context.window.SC.Chat.setActiveChatId('chat-ja');
  document.nodes['name-button'].onclick({});
  if (document.nodes['name-button'].textContent !== '[生成中...]') {
    throw new Error('Japanese title button progress text lost: '
      + document.nodes['name-button'].textContent);
  }
  savedCallback({ ok: true, data: { chat_name: 'XP Repair' } });
  if (context.window.SC.I18n.getLang() !== 'ja') {
    throw new Error('generate title changed language state');
  }
  if (document.nodes['name-button'].textContent !== 'タイトル生成') {
    throw new Error('Japanese title button text lost after callback: '
      + document.nodes['name-button'].textContent);
  }
  if (document.nodes['send-hint'].textContent !== 'タイトルを更新しました') {
    throw new Error('Japanese title status lost after callback: '
      + document.nodes['send-hint'].textContent);
  }
}

runJapaneseTitleCase();

console.log('PASS');

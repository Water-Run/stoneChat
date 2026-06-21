/* Regression test for Pages/js/modern-banner.js.
 * The banner is a modern-Windows notice, not merely a modern-browser notice. */
var fs = require('fs');
var vm = require('vm');

function makeElement(tag) {
  return {
    tagName: tag,
    className: '',
    rel: '',
    href: '',
    type: '',
    style: {},
    children: [],
    parentNode: null,
    appendChild: function (node) {
      this.children.push(node);
      node.parentNode = this;
    },
    removeChild: function (node) {
      var next = [];
      for (var i = 0; i < this.children.length; i++) {
        if (this.children[i] !== node) {
          next.push(this.children[i]);
        }
      }
      this.children = next;
      node.parentNode = null;
    },
    querySelector: function () {
      return { addEventListener: function () {} };
    }
  };
}

function runWithCookie(cookieText) {
  var appended = 0;
  var head = makeElement('head');
  var body = makeElement('body');
  body.appendChild = function (node) {
    appended++;
    node.parentNode = body;
  };

  var document = {
    cookie: cookieText,
    readyState: 'complete',
    createElement: makeElement,
    createTextNode: function (text) {
      return { nodeValue: text };
    },
    getElementsByTagName: function (name) {
      return name === 'head' ? [head] : [];
    },
    querySelector: function () {
      return null;
    },
    addEventListener: function () {},
    body: body
  };

  var context = {
    window: {
      addEventListener: function () {},
      setTimeout: function (fn) { fn(); }
    },
    document: document,
    Promise: function () {},
    fetch: function () {},
    setTimeout: function (fn) { fn(); }
  };
  context.window.document = document;
  vm.runInNewContext(
    fs.readFileSync('Pages/js/modern-banner.js', 'utf8'),
    context
  );
  return appended;
}

var failures = [];
if (runWithCookie('') !== 0) {
  failures.push('banner should not render without sc_modern cookie');
}
if (runWithCookie('sc_modern=1; sc_super_modern_seen=1') !== 1) {
  failures.push('banner should render when sc_modern cookie is set');
}

if (failures.length) {
  console.log('FAIL');
  for (var i = 0; i < failures.length; i++) {
    console.log('- ' + failures[i]);
  }
  process.exit(1);
}

console.log('PASS');

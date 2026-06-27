const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const failures = [];

function read(name) {
  return fs.readFileSync(path.join(root, name), 'utf8');
}

if (fs.existsSync(path.join(root, 'Pages/js/modern-banner.js'))) {
  failures.push('Pages/js/modern-banner.js should be removed');
}

[
  'Pages/router.php',
  'Pages/index.htm',
  'Pages/chat.htm',
  'Pages/js/app.js',
  'Pages/js/api.js',
  'README.org'
].forEach(function(file) {
  const text = read(file);
  if (text.indexOf('modern-banner') !== -1
      || text.indexOf('sc_modern') !== -1) {
    failures.push(file + ' still references modern-banner plumbing');
  }
});

if (failures.length) {
  console.error('FAIL');
  for (let i = 0; i < failures.length; i++) {
    console.error('- ' + failures[i]);
  }
  process.exit(1);
}

console.log('PASS');

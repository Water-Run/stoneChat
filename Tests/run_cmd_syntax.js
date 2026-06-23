/*
 * stoneChat RUN.cmd syntax guard.
 *
 * Old CMD parses every parenthesized block before it runs it. A literal
 * ")" inside an echo line can close the block early and crash the
 * launcher with "was unexpected at this time".
 */

const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const run = fs.readFileSync(path.join(root, 'RUN.cmd'), 'utf8');

function fail(message) {
  console.error('FAIL');
  console.error('- ' + message);
  process.exit(1);
}

if (run.indexOf('PID(s):') !== -1) {
  fail('RUN.cmd must escape parentheses in the port-conflict PID line');
}

if (run.indexOf('PID^(s^):') === -1) {
  fail('RUN.cmd should keep the escaped PID^(s^): text for CMD blocks');
}

if (run.indexOf(':SC_FIND_FREE_PORT') === -1) {
  fail('RUN.cmd should have a free-port fallback when taskkill is denied');
}

if (run.indexOf('Using fallback port') === -1) {
  fail('RUN.cmd should tell the operator when it uses a fallback port');
}

if (run.indexOf('SC_GHOST_PIDS') === -1
    || run.indexOf('tasklist /FI "PID eq') === -1) {
  fail('RUN.cmd should detect a stale listening PID and switch ports');
}

console.log('PASS');

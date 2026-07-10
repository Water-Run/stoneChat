/*
 * XP shared-source launcher contract.
 *
 * The scripts are versioned with stoneChat because VMware mounts this
 * directory as the guest's xp_runtime share.
 */

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const scripts = path.join(root, 'tools', 'xp', 'scripts');
const launcher = path.join(scripts, 'start_stonechat_all.bat');
const probe = path.join(scripts, 'probe_stonechat_http.php');
const vmx = '/home/waterrun/VM/Windows/Windows_XP_SP3/'
  + 'Windows XP Professional/Windows XP Professional.vmx';

function read(file) {
  assert(fs.existsSync(file), file + ' must exist');
  return fs.readFileSync(file, 'utf8');
}

const launcherText = read(launcher);
const probeText = read(probe);
const vmxText = read(vmx);

assert(launcherText.includes('set APP_ROOT=\\\\vmware-host\\Shared Folders\\stoneChat'));
assert(launcherText.includes('probe_stonechat_http.php'));
assert(!launcherText.includes('cd /d C:\\stoneChat'));
assert(launcherText.includes('C:\\PHP54\\php.exe'));
assert(launcherText.includes('C:\\stoneChat-runtime\\main.log'));
assert(launcherText.includes('C:\\stoneChat-runtime\\mock.log'));
assert(launcherText.includes('127.0.0.1:9999'));
assert(launcherText.includes('127.0.0.1:9998'));
assert(launcherText.includes('Pages\\router.php'));
assert(launcherText.includes('Server\\api\\mock_llm.php'));
assert(launcherText.includes('pushd'));
const missingProbeContracts = [];
if (!launcherText.includes('http://127.0.0.1:9999/Pages/index.htm')) {
  missingProbeContracts.push('main probe must use /Pages/index.htm');
}
if (!launcherText.includes('http://127.0.0.1:9998/Server/api/mock_llm.php')) {
  missingProbeContracts.push('mock endpoint must be probed before success');
}
if (!launcherText.includes('"POST" "mock"')) {
  missingProbeContracts.push('mock probe must send its POST readiness input');
}
assert.deepStrictEqual(missingProbeContracts, []);
assert(!launcherText.includes('http://127.0.0.1:9999/"'));

assert(probeText.includes('file_get_contents'));
assert(probeText.includes("'timeout' => 5"));
assert(probeText.includes("'/^HTTP\\/1\\.[01] 200/'"));
assert(probeText.includes('exit(1)'));
assert(probeText.includes("$method = $argc > 2 ? strtoupper($argv[2]) : 'GET';"));
assert(probeText.includes("$expect_mock = $argc > 3 && $argv[3] === 'mock';"));
assert(probeText.includes("'method' => $method"));
assert(probeText.includes('Content-Type: application/json'));
assert(probeText.includes("'model' => 'MockLocal'"));
assert(probeText.includes("['choices'][0]['message']['content']"));

assert(vmxText.includes(
  'sharedFolder1.hostPath = "/home/waterrun/Project/stoneChat/tools/xp"'
));
assert(vmxText.includes('sharedFolder1.guestName = "xp_runtime"'));

console.log('PASS');

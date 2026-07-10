# XP Runtime Repair Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (\`- [ ]\`) syntax for tracking.

**Goal:** Make the VMware XP guest run the repository source and pass the IE6 MockLocal chat flow.

**Architecture:** XP consumes stoneChat and xp_runtime only through VMware Shared Folders. The launcher uses the shared application directory as the PHP working directory while keeping transient logs on XP. The router delegates validated non-PHP Pages/ files to PHP's static-server implementation, avoiding the PHP 5.4/XP reset observed after manual readfile() delivery.

**Tech Stack:** Windows XP SP3, VMware Workstation shared folders, PHP 5.4.45 built-in server, CMD, Windows Script Host/IE6, Node.js contract checks, PHP regression checks.

## Global Constraints

- PHP syntax must remain PHP 5.2-compatible; runtime target is PHP 5.4.45.
- Browser code remains IE6/ES3-compatible.
- Main and mock services bind only to 127.0.0.1 in XP.
- Never add or write real provider credentials.
- Test VM is powered off after each validation session.

---

### Task 1: Make shared-source startup explicit and testable

**Files:**
- Create: Tests/xp_runtime_contract.js
- Modify: /home/waterrun/xp_runtime/scripts/start_stonechat_all.bat
- Create: /home/waterrun/xp_runtime/scripts/probe_stonechat_http.php

**Interfaces:**
- Consumes: \\vmware-host\Shared Folders\stoneChat and C:\PHP54\php.exe.
- Produces: C:\stoneChat-runtime\main.log, C:\stoneChat-runtime\mock.log, and a non-zero launcher failure on missing prerequisites.

- [ ] **Step 1: Write the failing launcher contract**

~~~js
const text = fs.readFileSync(runtimeScript, 'utf8');
assert(text.includes('\\\\vmware-host\\Shared Folders\\stoneChat'));
assert(!text.includes('cd /d C:\\stoneChat'));
assert(text.includes('probe_stonechat_http.php'));
~~~

- [ ] **Step 2: Run test to verify it fails**

Run: node Tests/xp_runtime_contract.js

Expected: failure because the current launcher starts from C:\stoneChat and has no HTTP probe.

- [ ] **Step 3: Implement the shared-source launcher and probe**

Set APP_ROOT=\\vmware-host\Shared Folders\stoneChat. Verify APP_ROOT\Pages\router.php, APP_ROOT\Server\api\mock_llm.php, and C:\PHP54\php.exe. Create C:\stoneChat-runtime. Start each server in a child cmd /c that executes pushd "%APP_ROOT%"; bind 9999 and 9998 to 127.0.0.1; redirect output to main.log and mock.log.

The probe receives the target URL as argv[1], calls file_get_contents with an HTTP timeout of 5 seconds, and exits 1 unless the body is non-empty and the first $http_response_header line matches /^HTTP\/1\.[01] 200/.

- [ ] **Step 4: Run test to verify it passes**

Run: node Tests/xp_runtime_contract.js

Expected: PASS.

- [ ] **Step 5: Verify VMware shared folders in XP**

Start the VM. Run sc query VMTools, sc query VMHGFS, and dir "\\vmware-host\Shared Folders". Restart VMTools and reboot XP if needed. Do not continue until stoneChat and xp_runtime enumerate.

- [ ] **Step 6: Commit**

~~~bash
git add -f Tests/xp_runtime_contract.js
git commit -m "test: cover XP shared-source launcher"
~~~

### Task 2: Fix PHP 5.4 static-page delivery

**Files:**
- Modify: Pages/router.php
- Modify: Tests/regression.php
- Reuse: /home/waterrun/xp_runtime/scripts/probe_stonechat_http.php

**Interfaces:**
- Consumes: a validated /Pages/<static-file> request.
- Produces: false to PHP's built-in server for static files, while PHP pages remain required by the router and invalid paths stay 404.

- [ ] **Step 1: Run the failing real-XP test**

Start the shared-source launcher, then run:

~~~bat
C:\PHP54\php.exe "\\vmware-host\Shared Folders\xp_runtime\scripts\probe_stonechat_http.php" http://127.0.0.1:9999/Pages/index.htm
~~~

Expected: failure caused by the observed reset/empty body.

- [ ] **Step 2: Add a regression assertion**

In Tests/regression.php, assert that the validated non-PHP Pages/ branch delegates via return false; and does not manually call readfile($file). Run php Tests/regression.php and observe the new assertion fail.

- [ ] **Step 3: Implement the minimal router change**

Replace the non-PHP portion of the validated Pages/ branch with:

~~~php
        return false; /* PHP's static server serves validated Pages assets. */
~~~

Keep the explicit PHP require, traversal check, missing-file 404, and private-path blocks unchanged.

- [ ] **Step 4: Verify green**

Run: php Tests/regression.php && node Tests/ie6_compat.js

Expected: exit 0 and PASS.

Run the XP launcher and HTTP probe again.

Expected: PASS with a non-empty Pages/index.htm body and HTTP 200.

- [ ] **Step 5: Commit**

~~~bash
git add Pages/router.php Tests/regression.php
git commit -m "fix: delegate XP static pages to PHP server"
~~~

### Task 3: Automate XP/IE6 acceptance and capture failure evidence

**Files:**
- Create: /home/waterrun/xp_runtime/scripts/run_stonechat_xp_validation.bat
- Reuse: ie_login_stonechat.js, ie_stonechat_mock_send.js, start_stonechat_all.bat

**Interfaces:**
- Consumes: the Task 1 launcher and shared source.
- Produces: C:\stoneChat-runtime\validation.log and exit 0 only when login and MockLocal reply succeed.

- [ ] **Step 1: Write the failing acceptance wrapper**

The wrapper calls start_stonechat_all.bat, stops on a non-zero result, runs:

~~~bat
cscript //nologo "\\vmware-host\Shared Folders\xp_runtime\scripts\ie_login_stonechat.js"
cscript //nologo "\\vmware-host\Shared Folders\xp_runtime\scripts\ie_stonechat_mock_send.js"
~~~

It must require local stoneChat server or streaming mock response in its output; all output appends to C:\stoneChat-runtime\validation.log.

- [ ] **Step 2: Run the XP acceptance test**

Run: cmd /c "\\vmware-host\Shared Folders\xp_runtime\scripts\run_stonechat_xp_validation.bat"

Expected: the HTTP probe passes, IE reaches Pages/chat.htm, and the mock-reply marker is logged.

- [ ] **Step 3: Preserve actionable evidence on failure**

Copy main.log, mock.log, and validation.log with vmrun copyFileFromGuestToHost. Report the first failed gate without exposing configuration secrets.

- [ ] **Step 4: Final verification and cleanup**

Run php Tests/regression.php, node Tests/ie6_compat.js, and the XP validation batch. Confirm each exits 0, inspect git status --short, then stop XP with vmrun -T ws stop <vmx> hard.

- [ ] **Step 5: Commit**

~~~bash
git add -f /home/waterrun/xp_runtime/scripts/run_stonechat_xp_validation.bat
git commit -m "test: automate XP IE6 mock chat validation"
~~~

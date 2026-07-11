# XP Release Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute a release-grade validation campaign on Windows XP SP3 and Internet Explorer 6 and publish a detailed, evidence-backed ship/no-ship report.

**Architecture:** The Linux host coordinates tests and stores evidence, while the running XP VM is the authority for startup, UI, persistence, and model behavior. Destructive cases use `C:\stoneChat-qa` and disposable configuration/history; each test records command, IE6-visible state, protocol/runtime evidence, and restoration status before the next case.

**Tech Stack:** Windows XP SP3, Internet Explorer 6 COM automation/JScript, PHP 5.4.45, CMD, KpyM SSH wrapper, VMware `vmrun`, stunnel 5.26, host Bash/PHP/Node/curl, OpenAI-compatible APIs, Ollama.

## Global Constraints

- XP SP3 plus IE6 is the release acceptance platform; Linux UI behavior is never used as an XP pass.
- Preserve the user's `CONF.ini`, history, VM files, and the existing untracked `tools/xp/deploy_to_xp_vm.sh`.
- Run destructive tests only in `C:\stoneChat-qa` with `C:\stoneChat-qa-evidence` as the guest evidence root.
- Store host evidence under `/home/waterrun/Project/stoneChat/test-artifacts/xp-release-20260711`; do not commit credentials or raw secret-bearing configuration.
- Replace API keys, passwords, cookies, CSRF tokens, and auth signatures with `[REDACTED]` in report artifacts.
- Pair every decisive IE6 screenshot with command, HTTP, process, or log evidence.
- A case is PASS only when its exact expected behavior is directly observed; partial, indirect, or missing evidence is BLOCKED or NOT RUN.
- Restore and verify the baseline after every destructive test group.
- Zero open P0/P1 findings and a clean-reboot critical-path pass are mandatory for a ship recommendation.

## Specification Coverage

- Batch 0 (baseline and evidence harness) is implemented by Task 1 and the isolation proof in Task 2.
- Batch 1 (installation, startup, and shutdown) is implemented by Task 3.
- Batch 2 (configuration matrix) is implemented by Task 4.
- Batch 3 (accounts, authorization, and sessions) is implemented by Task 5.
- Batch 4 (IE6 page journeys) is implemented by Task 6.
- Batch 5 (chat interruption and recovery) is implemented by Task 7.
- Batch 6 (real models and networking) is implemented by Task 8.
- Batch 7 (persistence, damage, capacity, and longevity) is implemented by Task 9.
- Batch 8 (release gate and clean rerun) is implemented by Task 10.

---

### Task 1: Establish the Evidence Workspace and Immutable Baseline

**Files:**
- Create: `test-artifacts/xp-release-20260711/manifest.tsv`
- Create: `test-artifacts/xp-release-20260711/environment/`
- Create: `test-artifacts/xp-release-20260711/screenshots/`
- Create: `test-artifacts/xp-release-20260711/logs/`
- Create: `test-artifacts/xp-release-20260711/cases/`
- Create: `test-artifacts/xp-release-20260711/restoration/`
- Create: `docs/reports/2026-07-11-xp-release-validation.md`

**Interfaces:**
- Consumes: current Git checkout, XP VMX, `/home/waterrun/xp-ssh`, existing XP services.
- Produces: immutable baseline inventory and the report skeleton used by all later tasks.

- [ ] **Step 1: Record host and repository state**

Run:

```bash
mkdir -p test-artifacts/xp-release-20260711/{environment,screenshots,logs,cases,restoration}
git rev-parse HEAD > test-artifacts/xp-release-20260711/environment/git-head.txt
git status --short > test-artifacts/xp-release-20260711/environment/git-status.txt
php -v > test-artifacts/xp-release-20260711/environment/host-php.txt 2>&1
node --version > test-artifacts/xp-release-20260711/environment/host-node.txt 2>&1
vmrun list > test-artifacts/xp-release-20260711/environment/vm-list.txt 2>&1
```

Expected: the VM list contains exactly the XP VMX; Git status records but does not alter the existing untracked deployment script.

- [ ] **Step 2: Record XP platform and runtime state**

Run each through `/home/waterrun/xp-ssh`, saving stdout:

```text
ver
reg query "HKLM\Software\Microsoft\Internet Explorer" /v Version
C:\PHP54\php.exe -v
C:\PHP54\php.exe -m
ipconfig /all
route print
netstat -ano
tasklist
dir C:\ /-C
date /T & time /T
```

Expected: XP reports 5.1.2600, IE reports a 6.x version, PHP reports 5.4.45, and the active listener/process state is preserved in separate files.

- [ ] **Step 3: Capture the initial IE6 page and runtime logs**

Run:

```bash
vmrun -T ws captureScreen "/home/waterrun/VM/Windows/Windows_XP_SP3/Windows XP Professional/Windows XP Professional.vmx" test-artifacts/xp-release-20260711/screenshots/BASE-001-initial.png
```

Copy current `run.out`, `mock.out`, and runtime logs when present. Expected: screenshot opens successfully and log copies are readable or explicitly recorded as absent.

- [ ] **Step 4: Create a sanitized config inventory**

Use PHP to emit section names, key names, active flags, types, base hosts, model IDs, stream settings, and timeouts while replacing `api_key`, `password`, cookies, and token-like fields with `[REDACTED]`.

Expected: no known secret substring appears in the output when checked with `rg`.

- [ ] **Step 5: Write the report skeleton and manifest header**

The manifest columns are exactly:

```text
case_id	batch	severity	status	precondition	action	expected	actual	screenshot	log	restored	defect
```

The report starts with Environment, Executive Summary, Coverage, Results by Batch, Defects, Backend Matrix, Residual Risks, and Release Recommendation.

- [ ] **Step 6: Commit the report skeleton only**

```bash
git add -f docs/reports/2026-07-11-xp-release-validation.md
git commit -m "test: start XP release validation report"
```

Expected: evidence remains ignored/uncommitted; the report skeleton is committed.

### Task 2: Build and Prove the Disposable XP Test Copy

**Files:**
- Use: `tools/xp/deploy_to_xp_vm.sh`
- Use: `tools/xp/scripts/start_stonechat_all.bat`
- Use: `/home/waterrun/xp_runtime/scripts/ie_login_stonechat.js`
- Use: `/home/waterrun/xp_runtime/scripts/ie_stonechat_mock_send.js`
- Create: `test-artifacts/xp-release-20260711/environment/qa-copy-hashes.txt`

**Interfaces:**
- Consumes: repository files and baseline evidence from Task 1.
- Produces: isolated `C:\stoneChat-qa`, clean mock configuration, working IE6 automation, and a verified restoration procedure.

- [ ] **Step 1: Copy application files to the QA root without credentials/history**

Copy `Pages`, `Server`, `Assets`, `ModernNetwork`, `README.org`, `RUN.cmd`, and `CONF_SMP.INI` into `C:\stoneChat-qa`. Do not copy `CONF.ini`, `HISTORY`, `.git`, test artifacts, or the user's raw logs.

Expected: a guest-side recursive file list matches the intended host list; excluded paths are absent.

- [ ] **Step 2: Install a disposable mock configuration**

Create `C:\stoneChat-qa\CONF.ini` with one active `MockLocal` OpenAI-compatible model targeting `http://127.0.0.1:9998/Server/api/mock_llm.php`, two test users with different rights, and a QA-only cookie name.

Expected: `sc_validate_config()` reports no fatal errors and the sanitized copy contains no real key.

- [ ] **Step 3: Start main and mock services from the QA root**

Start PHP 5.4 on ports 9999 and 9998 with stdout/stderr redirected under `C:\stoneChat-qa-evidence`. Probe both endpoints using `tools/xp/scripts/probe_stonechat_http.php`.

Expected: both probes exit 0, listeners belong to PHP processes launched from `C:\stoneChat-qa`, and no listener from the user's original tree was killed without being recorded.

- [ ] **Step 4: Prove IE6 login and mock send visually**

Run the existing login and mock-send JScript against the QA credentials, inspect the DOM text, and capture screenshots before login, after login, during generation, and after the mock response.

Expected: IE6 reaches `Pages/chat.htm`, displays `MockLocal`, and renders the expected mock assistant text with no script-error dialog.

- [ ] **Step 5: Prove restoration**

Stop QA listeners, restore the pre-test listener state, and compare the original config/history inventory and hashes to Task 1.

Expected: all protected baseline artifacts are unchanged and the QA service can be cleanly restarted.

### Task 3: Execute Startup and Dependency-Failure Cases

**Files:**
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`
- Create: `test-artifacts/xp-release-20260711/cases/START-*.txt`
- Create: `test-artifacts/xp-release-20260711/restoration/START-*.txt`

**Interfaces:**
- Consumes: disposable QA copy and baseline restoration procedure.
- Produces: evidence for every Batch 1 scenario and defects for incorrect launcher behavior.

- [ ] **Step 1: Run clean and repeated-launch controls**

Test first launch, second launch with the same port, refusal to kill, approved termination of only the QA-owned PID, fallback-port selection, clean stop, forced PHP death, restart after death, and restart after XP reboot.

Expected: each branch is visible in the console, unrelated PIDs survive, and the final page is reachable when the launcher claims success.

- [ ] **Step 2: Run PHP dependency cases**

Execute `RUN.cmd` with a temporary PATH lacking PHP, with a QA-local invalid executable name, with a stub executable returning an old version, and with the valid `C:\PHP54` path restored.

Expected: missing/old PHP fails before service launch with actionable text; restored PHP launches successfully.

- [ ] **Step 3: Run configuration presence and syntax cases**

Test missing `CONF.ini` with sample present, both files missing, empty file, malformed section, duplicate sections, invalid encoding, read-only config, and valid restoration.

Expected: sample-copy behavior occurs only when documented, malformed input fails visibly, and the valid config is byte-for-byte restored.

- [ ] **Step 4: Run stunnel and CA cases**

Test missing stunnel, alternate valid path, directory instead of executable, missing CA, invalid relative CA, stale PID, occupied proxy port, and valid restoration.

Expected: startup or first HTTPS request fails at the correct boundary without exposing the key or leaving a false-running stunnel state.

- [ ] **Step 5: Run filesystem and path cases**

Test missing/read-only history, missing temp directory, path with spaces, parentheses, ampersand, percent, non-ASCII, and exclamation mark. Simulate low disk with a bounded small quota/filler only if restoration can be guaranteed.

Expected: supported paths work; explicitly rejected paths fail with the documented reason; no command injection or truncation occurs.

- [ ] **Step 6: Run network-loss cases and restore**

With the IE page already loaded, test LAN route loss, DNS failure, upstream refusal, and local page availability. Restore networking and verify a new mock request succeeds.

Expected: local UI remains usable, upstream failure is recoverable, and restoration evidence is PASS.

### Task 4: Execute the Configuration Matrix

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/CONFIG-matrix.tsv`
- Create: `test-artifacts/xp-release-20260711/cases/CONFIG-*.txt`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: validator behavior, disposable config, XP PHP 5.4, IE6 config editor.
- Produces: field-level and pairwise configuration results with page confirmation.

- [ ] **Step 1: Generate the matrix from documented keys**

For `[server]`, `[paths]`, `[proxy]`, `[auth]`, `[User NAME]`, `[Model NAME]`, and `[ui]`, enumerate missing, empty, normal, lower/upper boundary, wrong type, whitespace, case, quoted, inline-comment, duplicate, long, and non-ASCII variants applicable to that key.

Expected: every key in `CONF_SMP.INI` maps to at least one positive and one negative case; critical keys map to all applicable classes.

- [ ] **Step 2: Validate each variant with XP PHP 5.4**

For each config, run `sc_load_config`, `sc_validate_config`, `sc_config_fatal_errors`, and `sc_load_providers`, recording exact error codes and normalized public model data.

Expected: no crash/warning leakage; fatal/nonfatal classification matches actual launcher and page behavior.

- [ ] **Step 3: Exercise users and model combinations in IE6**

Cover zero/one/many active users; zero/one/many active models; wildcard, missing, inactive, and comma-separated model exclusions; all languages; both shortcuts; editor master/user permission combinations; and pairwise model stream/token/timeout/type/base combinations.

Expected: visible controls, model lists, labels, language, shortcut, and editor access match the active user's normalized config.

- [ ] **Step 4: Exercise web editing and reload semantics**

Save valid and invalid changes through IE6, cancel edits, refresh, reopen, reload config, and start a new session. Record which values apply immediately versus after session or process restart.

Expected: invalid save is rejected without destroying the prior usable config; secrets are masked or handled according to actual product behavior and any exposure is classified.

- [ ] **Step 5: Restore and hash-check the QA configuration**

Expected: the canonical mock config is restored byte-for-byte and the clean IE6 login/send control passes.

### Task 5: Execute Account, Authorization, and Session Cases

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/AUTH-*.txt`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: user variants from Task 4 and IE6/API access.
- Produces: account lifecycle, authorization, session, CSRF, and isolation evidence.

- [ ] **Step 1: Test account lifecycle and password ambiguity**

Create active/inactive users, change passwords, test blank/placeholder/duplicate passwords, duplicate case-variant names, whitespace/non-ASCII names, and many users. Login with correct, wrong, empty, very long, control-character, and rapid repeated inputs.

Expected: only active unambiguous users authenticate; ambiguous duplicate passwords are recorded as a release risk or defect according to observed behavior.

- [ ] **Step 2: Test brute-force lockout**

Exercise attempts at `max_attempts-1`, exactly max, and max+1; correct password while locked; expiry at the boundary; successful reset; two source identities where feasible; malformed cache; read-only cache; and restart persistence.

Expected: UI text and API status agree, no off-by-one behavior is unexplained, and recovery does not require deleting user data.

- [ ] **Step 3: Test session and cookie behavior**

Test logout, IE close/reopen, Back after logout, stale cookie, modified username/timestamp/signature, expired token, cookie-name change, two instances with same/different cookie names, and simultaneous Admin/Guest windows.

Expected: forged/expired sessions fail; cookie scoping does not cross instances when configured correctly.

- [ ] **Step 4: Test authorization and CSRF directly and visibly**

Call config/history/chat/provider endpoints unauthenticated, as Guest, and as Admin with missing, malformed, wrong-action, replayed, and stale CSRF tokens. Then repeat permitted operations through IE6.

Expected: denied API calls do not mutate state; IE6 shows an actionable auth/error state; no secret or other user's history is returned.

- [ ] **Step 5: Test per-user model, language, shortcut, editor, and history isolation**

Expected: each permission/config dimension follows the authenticated user, all-model exclusion is usable or clearly explained, and Admin/Guest history files and page lists never cross.

### Task 6: Execute IE6 User Journeys and Input Edge Cases

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/UI-*.txt`
- Create: `test-artifacts/xp-release-20260711/screenshots/UI-*.png`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: clean QA service, IE6 COM automation, mock model.
- Produces: visible end-user journey and layout evidence.

- [ ] **Step 1: Test navigation and browser lifecycle**

Cover first visit, refresh, cache refresh, Back/Forward, direct protected URLs, duplicate windows, closing/reopening IE, 800x600-equivalent viewport, focus/tab order, keyboard-only operation, and rapid repeated menu clicks.

Expected: no script-error dialog, blank page, unreplaced translation key, inaccessible control, or misleading stale screen.

- [ ] **Step 2: Test complete chat/history/config journeys**

Cover new chat, model selection, send, automatic title, rename, system prompt, history reopen, delete confirm/cancel, copy, regenerate, stop, error dismissal, config edit/cancel/save/reload, logout, and re-login.

Expected: every visible state matches persisted API/history state after reload.

- [ ] **Step 3: Test text and keyboard variants**

Use empty, spaces, tabs, CRLF multiline, Chinese, every supported language, quotes, backslashes, percent, ampersand, `<script>`-like text, HTML entities, Markdown, code fences, URLs, long unbroken text, very long input, and characters IE6 cannot encode cleanly.

Expected: no executable markup, broken request, silent truncation, double-send, or page corruption; unsupported characters fail visibly and recoverably.

- [ ] **Step 4: Test send shortcuts and repeated input**

For `enter` and `shift_enter`, verify Enter/Shift+Enter, held key repeat, IME composition where available, click send, double click, send while disabled, and paste then send.

Expected: exactly one message is sent for one intended action and multiline behavior matches the user setting.

### Task 7: Execute Chat Interruption and Protocol-Fault Cases

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/CHAT-*.txt`
- Create: `test-artifacts/xp-release-20260711/screenshots/CHAT-*.png`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: IE6 journey, mock endpoint variants, history evidence.
- Produces: complete state-machine and recovery results.

- [ ] **Step 1: Establish normal stream and non-stream controls**

Run a clean send for both settings and record request body, first visible token, completion, stored history, and reopen state.

Expected: one coherent assistant message and matching persisted history.

- [ ] **Step 2: Test user interruption timing**

Stop before first token, during stream, near completion, and twice; then resend and regenerate. Repeat while refreshing, navigating away, closing IE, logging out, switching model, and attempting a second send.

Expected: controls leave generating state, partial content handling is deterministic, no duplicate messages appear, and the next request succeeds.

- [ ] **Step 3: Test transport and response faults**

Return delayed headers, timeout, refusal, abrupt close, 400, 401, 403, 404, 429, 500, 502, empty 200, invalid JSON, SSE despite `stream=false`, split JSON/SSE boundaries, partial multibyte text, duplicate chunks, missing `[DONE]`, error event after content, and oversized content.

Expected: IE6 shows a bounded actionable error or valid partial result, history remains coherent, and retry does not require a restart.

- [ ] **Step 4: Verify persistence after every failure class**

Reload and reopen the chat, compare DOM messages with history JSON, then send a clean control message.

Expected: no phantom generating state, orphan message, hidden duplicate, or corrupted history.

### Task 8: Execute Real Backend and stunnel Matrix

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/MODEL-*.txt`
- Create: `test-artifacts/xp-release-20260711/screenshots/MODEL-*.png`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: DeepSeek credential supplied out-of-band, DGX Ollama endpoint, Mac MLX endpoint, stunnel.
- Produces: real-provider compatibility, latency, and failure evidence without stored secrets.

- [ ] **Step 1: Probe backend health from host and XP**

Record TCP reachability and one minimal non-stream request from each location. Redact authorization headers and keys before saving. The previously observed Mac empty reply must be reproduced, diagnosed, or cleared.

Expected: backend status is classified HEALTHY, FAILED, or ENVIRONMENT BLOCKED with direct evidence.

- [ ] **Step 2: Test each healthy backend in IE6**

For stream true/false, send Chinese, multi-turn context, system prompt, Markdown/code, long prompt, constrained token count, short timeout, and sequential requests. Verify automatic naming and reasoning-tag handling.

Expected: visible output, completion state, and history match backend responses without raw protocol artifacts.

- [ ] **Step 3: Test real-backend errors**

Use wrong/placeholder/missing key, wrong base path, nonexistent model, rate/auth failure when safely reproducible, tiny timeout, unreachable address, and recovery to valid config.

Expected: secrets never appear; errors are distinguishable enough to act on; valid config recovers without VM restart unless documented.

- [ ] **Step 4: Test stunnel target isolation and recovery**

Alternate HTTPS and local HTTP models, switch targets repeatedly, kill stunnel, create a stale PID, occupy proxy port, and retry after restoration.

Expected: no cross-provider host/path leakage, local HTTP remains direct, and restored HTTPS works.

### Task 9: Execute Persistence Damage, Concurrency, Capacity, and Soak Cases

**Files:**
- Create: `test-artifacts/xp-release-20260711/cases/HIST-*.txt`
- Create: `test-artifacts/xp-release-20260711/cases/SOAK-*.txt`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`

**Interfaces:**
- Consumes: disposable histories, IE6 windows, stable mock and real backend.
- Produces: durability, concurrency, resource-growth, and recovery evidence.

- [ ] **Step 1: Test history scale and names**

Create many chats and messages; test empty, duplicate, long, non-ASCII, path-like, and markup-like names; reorder by activity; rename, set system prompt, reopen, and delete.

Expected: list and files remain consistent and no path escapes the configured user history root.

- [ ] **Step 2: Test damaged and inaccessible history**

On disposable files only, test missing, empty, truncated, invalid JSON, duplicate ID, mismatched ID, read-only, locked, and directory-instead-of-file states.

Expected: page/API fails safely, identifies affected operation, preserves unrelated chats, and recovers after restoration.

- [ ] **Step 3: Test two-window concurrency and process death during save**

Send/rename/delete from two IE windows against the same user, then force PHP death during a disposable save and restart.

Expected: observed last-writer/conflict behavior is documented; no malformed durable file or cross-user mutation remains.

- [ ] **Step 4: Run bounded soak and resource checks**

Run repeated login/logout, new chat/send/reopen/delete, stop/resend, and model switching for a fixed iteration count and duration. Sample PHP/stunnel process count, working set, handles when available, disk usage, log size, latency, and IE responsiveness.

Expected: no unbounded process/file/log growth, hung UI, or progressive latency severe enough to block normal use.

- [ ] **Step 5: Reboot XP and verify recovery**

Expected: QA services start by the documented method, durable test history is coherent, temporary state is absent, and a clean chat succeeds.

### Task 10: Run the Clean Release Gate and Publish the Report

**Files:**
- Modify: `docs/reports/2026-07-11-xp-release-validation.md`
- Modify: `test-artifacts/xp-release-20260711/manifest.tsv`
- Create: `test-artifacts/xp-release-20260711/release-gate/`

**Interfaces:**
- Consumes: all case evidence and defect/retest states.
- Produces: authoritative final report and ship/no-ship recommendation.

- [ ] **Step 1: Audit manifest completeness**

Verify every case has status, actual result, evidence path, and restoration state. Reject PASS rows with missing evidence, and classify every planned-but-unrun case as BLOCKED, NOT RUN, or NOT APPLICABLE with reason.

- [ ] **Step 2: Re-run all P0/P1 and previously failed cases after fixes**

Expected: each retest links original and new evidence; unresolved failures remain open and are not overwritten.

- [ ] **Step 3: Perform a clean-reboot critical-path gate**

From a restored release-like config: reboot XP, launch through the documented user path, open IE6, log in as editor and non-editor users, verify model lists, complete one streaming real-model chat, stop and resend, reopen history, test config permission, logout/login, restart services, and repeat one chat.

Expected: every critical-path row passes with fresh screenshot and runtime evidence.

- [ ] **Step 4: Compute results and write findings**

Report exact counts for PASS, FAIL, BLOCKED, NOT RUN, and NOT APPLICABLE by batch and severity. For every defect include reproduction, expected/actual behavior, impact, evidence, suspected component, workaround, fix/retest state, and release relevance.

- [ ] **Step 5: Write the release recommendation**

Recommend SHIP only with zero open P0/P1, a passing clean gate, no unexplained blocked scope, and a truthful supported-backend statement. Otherwise recommend NO-SHIP and list the minimum gates required for reconsideration.

- [ ] **Step 6: Verify report redaction and links**

Search the report and committed files for supplied credentials, bearer headers, raw cookies, CSRF tokens, auth signatures, and private config values. Open every referenced screenshot/log and confirm it supports the linked claim.

Expected: no secret match and no broken evidence reference.

- [ ] **Step 7: Run final repository checks and commit**

```bash
php Tests/regression.php
for f in Tests/*.js; do node "$f"; done
git diff --check
git status --short
git add -f docs/reports/2026-07-11-xp-release-validation.md
git commit -m "test: report XP release validation results"
```

Expected: supported host-side contract tests pass, the report commit contains no raw evidence secret, and unrelated user files remain untouched.

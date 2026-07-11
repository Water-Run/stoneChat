# XP Release Validation Design

## Goal

Produce a detailed, evidence-backed release test report for stoneChat on its
real target platform: Windows XP SP3 with Internet Explorer 6. The report must
cover normal user journeys, realistic misuse, configuration variants,
dependency failures, upstream-model failures, interruption and recovery, and
release-blocking defects.

## Source of Truth

Release acceptance is decided by behavior observed inside the running XP VM,
not by behavior on the Linux host. The authoritative UI is IE6 rendering the
XP-local stoneChat service. Host-side tests may prove isolated PHP and
JavaScript contracts, but they cannot replace an XP page result.

The test environment is:

- Windows XP Professional SP3 at `192.168.69.135`.
- Internet Explorer 6 as the acceptance browser.
- PHP 5.4 at `C:\PHP54\php.exe`.
- stoneChat served on XP loopback, normally port `9999`.
- KpyM SSH accessed through `/home/waterrun/xp-ssh` for command automation.
- VMware guest operations and screenshots for recovery and evidence capture.
- DeepSeek's OpenAI-compatible API, DGX Spark Ollama, and Mac Studio MLX as
  real upstreams. Credentials are never written to the report or committed.

## Safety and Isolation

Before destructive tests, preserve the VM state, active processes, current
configuration, application revision, runtime logs, and history inventory.
Tests that remove dependencies, corrupt files, occupy ports, or alter access
rights run against an XP-local disposable test copy and disposable config.

Every destructive case has an explicit restoration check. The shared source
tree, the user's existing `CONF.ini`, credentials, and history are not used as
disposable fixtures. Secret values are replaced with `[REDACTED]` in commands,
screenshots where practical, logs, and reports.

## Test Organization

The campaign is risk-layered. A failed early gate does not cancel independent
tests; it records a blocker, captures evidence, restores the environment, and
continues where safe. Each batch is independently reviewable.

### Batch 0: Baseline and Evidence Harness

Record the Git revision, dirty worktree, VM identity, Windows version, IE
version, PHP version and extensions, stunnel version and path, network routes,
listening ports, running processes, clock, free disk space, config schema, and
existing test inventory. Prove that screenshots, XP command output, HTTP
responses, and logs can be collected and correlated by test-case ID.

### Batch 1: Installation, Startup, and Shutdown

Test a clean launch and realistic missing or damaged prerequisites:

- Missing PHP, PHP absent from `PATH`, PHP below the supported version, and a
  PHP executable path containing spaces.
- Missing `CONF.ini`, missing template, malformed INI, unreadable config, and
  creation of config from the sample.
- Missing stunnel, alternate stunnel path, missing CA file, invalid CA path,
  stale stunnel PID, and stunnel start failure.
- Missing, read-only, or unwritable history and temporary directories; low or
  exhausted disk space where safely simulatable.
- Configured port free, occupied by stoneChat, occupied by another process,
  protected PID, stale listener, fallback port, and out-of-range port.
- Application paths containing spaces, non-ASCII characters, parentheses,
  ampersands, percent signs, and exclamation marks.
- First start, second concurrent start, stop, forced process termination,
  restart after crash, restart after VM reboot, and orphan cleanup.
- LAN unavailable, DNS unavailable, upstream unavailable, and local-only UI
  availability while the external network is down.

For every rejected startup, the expected result is a specific actionable
message with no false success banner, leaked secret, hung console, or unwanted
destruction of an unrelated process.

### Batch 2: Configuration Matrix

For every documented scalar, test valid values, missing values, empty values,
wrong types, boundary values, excessive lengths, whitespace, case variants,
duplicates where INI permits them, inline comments, quoting, CRLF, and damaged
encoding. Critical fields are exhaustively tested; lower-risk combinations use
pairwise coverage.

Configuration dimensions include:

- `[server]`: port minimum, maximum, zero, negative, text, occupied port.
- `[paths]`: absolute and relative CA/stunnel paths, slash direction, spaces,
  nonexistent files, directories supplied instead of files.
- `[proxy]`: valid, conflicting, out-of-range, and reused tunnel ports.
- `[auth]`: attempt count, lockout duration, cookie name, duplicate cookie
  names across instances, and session lifetime aliases accepted by the code.
- `[User NAME]`: no users, one user, many users, active states, passwords,
  edit permission, exclusion lists, wildcard exclusion, language, shortcut,
  duplicate names, duplicate passwords, whitespace names, and non-ASCII names.
- `[Model NAME]`: zero/one/many models, active state, label, dispatch type,
  base URL, key, upstream model ID, stream boolean, token cap, timeout, duplicate
  names, old Provider sections, local HTTP targets, and HTTPS targets.
- `[ui]`: editor master switch, title, all eight languages, unsupported
  language, theme, and long/non-ASCII display text.

After web editing or reload, prove whether each setting takes effect
immediately, requires a new session, or requires process restart. Invalid
config must preserve the last usable state or fail visibly and recoverably.

### Batch 3: Accounts, Authorization, and Session Security

Exercise account creation by config, account activation/deactivation, password
changes, duplicate and blank passwords, login with each account, wrong and
malformed credentials, brute-force lockout, lockout expiry, successful-login
reset, logout, browser restart, session expiry, cookie tampering, stale cookies,
and simultaneous sessions.

Verify per-user edit permission, model exclusion, default language, send
shortcut, history isolation, and behavior when all models are excluded.
Attempt direct URL and API access without login, as a non-editor, with an
expired session, with a forged cookie, and with missing, wrong-action, stale,
or replayed CSRF tokens. Responses must not disclose keys, passwords, history
belonging to another user, filesystem paths unnecessarily, or stack traces.

### Batch 4: IE6 Page Journeys

Use real IE6 interactions and inspect what is visibly rendered, focused,
enabled, and persisted. Cover first visit, login, logout, page reload, cache,
Back/Forward, direct URLs, multiple windows, small viewport, focus and tab
order, keyboard-only use, and repeated rapid clicks.

Exercise model selection, config editor and fallback editor, language switching,
new chat, send, automatic title, manual rename, system prompt, history reopen,
delete and cancellation, copy, regenerate, stop, and error dismissal. Test
empty input, spaces only, multiline input, all send-shortcut combinations,
Chinese and all supported languages, emoji/surrogates as tolerated by XP,
HTML-like text, Markdown, code blocks, URLs, very long unbroken strings, large
messages, and many-message conversations.

The page must not show script errors, blank controls, unreplaced i18n keys,
broken layout that prevents operation, double submissions, stale model state,
or success indicators for failed actions.

### Batch 5: Chat State Machine, Interruption, and Recovery

Test stop before first token, stop during streaming, stop near completion,
double stop, resend after stop, regenerate after stop, regenerate after success,
and repeated regenerate. During generation test page refresh, navigation away,
window close, logout, model switch attempt, second send, VM network disconnect,
upstream disconnect, timeout, malformed stream frames, partial UTF-8, duplicate
SSE frames, missing completion marker, empty success, HTTP 4xx/5xx, and a very
large response.

For each interruption, verify button state, spinner state, partial message
handling, history persistence, retryability, absence of duplicate user or
assistant messages, and the next request's correctness. Reopening a chat must
show a coherent durable state rather than a phantom in-progress operation.

### Batch 6: Real Model and Network Matrix

Test DeepSeek, DGX/Ollama, and Mac/MLX independently through configurations the
product actually supports. Each healthy backend covers non-streaming and
streaming, Chinese, multi-turn context, system prompt, response naming,
reasoning-tag handling, token cap, timeout, and sequential requests.

Negative cases include wrong key, placeholder key, missing model ID,
nonexistent model, wrong base path, DNS failure, connection refusal, delayed
response, rate limit, authorization failure, upstream server error, invalid
JSON, SSE returned despite `stream=false`, and connection loss mid-stream.

The XP stunnel path additionally proves TLS target switching, certificate
validation, path preservation, query handling, stale process recovery, and no
cross-provider target leakage. Local HTTP endpoints must not be needlessly
routed through TLS. The currently observed Mac endpoint empty reply is tracked
as an environment finding until reproduced or cleared.

### Batch 7: Persistence, Damage, Capacity, and Longevity

Test history creation, ordering, rename, system prompt, reopen, deletion,
cross-user separation, many chats, long names, malformed history JSON,
truncated files, duplicate IDs, missing files, read-only files, concurrent
updates from two IE windows, process death during save, and restart recovery.

Run repeated sends, repeated login/logout, model switching between chats, and a
bounded soak session while recording memory, handles, process count, disk use,
response latency, UI responsiveness, and log growth. Confirm cleanup after the
soak and after VM reboot.

### Batch 8: Release Gate and Clean Re-Run

Restore a release-like config, restart XP, and repeat the critical path from a
clean state: launcher, IE6 login, visible model list, one streaming real-model
chat, stop and resend, history reopen, logout/login, config permission checks,
and clean shutdown/restart.

## Evidence Model

Every case receives a stable ID and records:

- Risk and release severity.
- Preconditions and sanitized configuration.
- Exact XP/IE6 actions.
- Expected visible and protocol behavior.
- Actual visible behavior.
- IE6 screenshot at decisive states.
- Relevant HTTP status/body summary and XP process/log excerpt.
- Pass, fail, blocked, or not-applicable status.
- Defect identifier, restoration result, and retest result when applicable.

Screenshots are not sufficient alone: page evidence is paired with the XP
runtime or HTTP evidence that explains what happened. Conversely, API success
is not a UI pass unless the expected IE6 state is visibly confirmed.

## Release Severity and Gate

- **P0 blocker:** data/credential exposure, cross-user access, unrecoverable
  history loss, arbitrary command/file access, or installation damage.
- **P1 blocker:** cannot launch on supported XP, cannot log in, cannot complete
  a real chat, stop/retry corrupts state, or common valid config is unusable.
- **P2 major:** important workflow has a recoverable failure or a documented
  configuration behaves incorrectly.
- **P3 minor:** cosmetic or low-frequency issue with an obvious workaround.

Release requires zero open P0/P1 defects, all critical-path cases passing on a
clean XP restart, every supported real-backend class either passing or clearly
removed from release claims, no unexplained blocked case, and successful
restoration after all destructive tests. P2/P3 defects may remain only when
explicitly documented with impact, workaround, and release decision.

## Final Report

The final report will contain the environment inventory, coverage matrix,
case-by-case results, defect list, backend comparison, screenshots and evidence
index, residual risks, and an explicit ship/no-ship recommendation. Counts will
distinguish passed, failed, blocked, not run, and not applicable; partial or
indirect evidence will never be reported as a pass.

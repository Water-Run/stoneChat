# XP Runtime Repair Design

## Goal

Run the current `Project/stoneChat` revision in the VMware Windows XP SP3
environment and prove the IE6 login and `MockLocal` chat flow work.

## Scope

The repair covers two failures observed in the XP test environment:

1. VMware Shared Folders are enabled in the VMX but unavailable inside XP, so
   XP runs a stale `C:\stoneChat` copy rather than the repository.
2. The local PHP server on port 9999 accepts a connection but returns an empty
   HTTP response after a clean XP restart.

It does not add real LLM credentials, expose the services outside the XP
loopback interface, or change the product's supported-platform target.

## Architecture

The VMware shared folders are the sole source of application and test assets:

- `\\vmware-host\Shared Folders\stoneChat` maps to
  `/home/waterrun/Project/stoneChat` and is the application working tree.
- `\\vmware-host\Shared Folders\xp_runtime` maps to
  `/home/waterrun/xp_runtime` and supplies the XP-only start and IE automation
  scripts.
- An XP-local runtime directory stores the PHP server logs and other ephemeral
  output. It is never used as a second application source tree.

The XP launcher must verify both shared paths and `C:\PHP54\php.exe` before
starting the main server on `localhost:9999` and the mock server on
`localhost:9998`. It must actively probe the main endpoint after startup and
report the log path when it does not receive a valid HTTP response.

## Service Behaviour

The launcher starts PHP 5.4 with the shared `stoneChat` directory as its
working directory and `Pages\router.php` as the PHP built-in-server router.
The MockLocal endpoint remains loopback-only and uses no external API key.

The router must return a concrete HTTP response for `/` and
`/Pages/index.htm` on PHP 5.4/Windows XP. Any startup or routing failure must
be represented in the local server log instead of silently closing the socket.

## Validation

Validation is ordered from the lowest-risk dependency to the user flow:

1. Confirm the two VMware shared paths are reachable from XP and point to the
   required host directories.
2. Start the two local PHP services and verify `localhost:9999/` has a valid
   HTTP status and non-empty response body.
3. Run the project regression and compatibility checks relevant to the router
   and IE6 client.
4. Run XP IE automation: open the login page, submit the test login, select
   `MockLocal`, send a message, and require the known mock-response text.

The test fails immediately at the first failed stage, preserves the XP-local
server logs, and returns a non-zero status. The VM is powered off when the
test session ends.

## Acceptance Criteria

- XP can read the `stoneChat` and `xp_runtime` VMware shared folders.
- XP runs the repository revision, not `C:\stoneChat` as an independent copy.
- `http://localhost:9999/` returns a non-empty valid HTTP response on XP.
- IE6 reaches the login page, signs in, opens the chat page, selects
  `MockLocal`, and displays the expected mock reply.
- No real provider credentials are required or written.
- The repository contains no unintended changes after the test other than the
  implementation and test assets required by this design.

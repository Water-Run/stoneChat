# Windows XP 150% Viewer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install a persistent, single-instance GTK-VNC viewer that keeps only the designated Windows XP guest at `1024×768` and displays it at exactly 150% physical size.

**Architecture:** A user-local Python/GTK 3 application connects to the XP VM's existing loopback VNC endpoint at `127.0.0.1:5997`. It checks or starts only the exact XP `.vmx`, enforces that guest's resolution through a command whose argument contains the same exact `.vmx`, and sizes the GTK-VNC content using the live GDK scale factor. A validated `.desktop` file makes the viewer persistent in the GNOME application menu.

**Tech Stack:** Python 3.14, PyGObject, GTK 3.24, GTK-VNC 1.5 (`GtkVnc 2.0` introspection), VMware `vmrun`/`vmcli`, pytest 9, freedesktop desktop files.

## Global Constraints

- The only mutable VM target is `/home/waterrun/VM/Windows/Windows_XP_SP3/Windows XP Professional/Windows XP Professional.vmx`.
- The XP guest framebuffer must remain exactly `1024×768`.
- The target physical image is exactly `1536×1152`; on the current GDK scale factor `2`, its logical content area is `768×576`.
- Do not edit VMware global preferences, VMware libraries, another `.vmx`, XP DPI, XP fonts, or XP desktop layout.
- Do not store or transmit the XP login password; the local VMware VNC endpoint does not use the Windows login password.
- Closing the viewer disconnects VNC only; it never stops, suspends, or resets the VM.
- Runtime files are intentionally user-local and outside the stoneChat repository. The repository tracks only the approved design and this plan, so runtime checkpoints use tests and SHA-256 hashes instead of source commits.

---

### Task 1: Install the scoped GTK-VNC viewer

**Files:**
- Create: `/home/waterrun/.local/bin/vmware-xp-150`
- Create temporarily for testing: `/tmp/test_vmware_xp_150.py`

**Interfaces:**
- Consumes: `Gtk.Application`, `GtkVnc.Display`, `/usr/bin/vmrun`, `/usr/bin/vmcli`, the exact XP `.vmx`, and `127.0.0.1:5997`.
- Produces: `target_logical_size(scale_factor: int) -> tuple[int, int]`, `parse_running_vms(output: str) -> set[Path]`, `build_vmrun_start_command() -> list[str]`, `build_resolution_command() -> list[str]`, and the single-instance application ID `io.github.waterrun.XpViewer150`.

- [ ] **Step 1: Write the failing unit tests**

Create `/tmp/test_vmware_xp_150.py` with:

```python
from importlib.machinery import SourceFileLoader
from importlib.util import module_from_spec, spec_from_loader
from pathlib import Path
import warnings

import gi
import pytest


warnings.filterwarnings("ignore", category=gi.PyGIDeprecationWarning)
pytestmark = pytest.mark.filterwarnings(
    "ignore:GLib.unix_signal_add_full is deprecated"
)


SCRIPT = Path("/home/waterrun/.local/bin/vmware-xp-150")


def load_viewer():
    assert SCRIPT.is_file(), f"viewer script is not installed: {SCRIPT}"
    loader = SourceFileLoader("vmware_xp_150", str(SCRIPT))
    spec = spec_from_loader(loader.name, loader)
    module = module_from_spec(spec)
    loader.exec_module(module)
    return module


def test_target_geometry_at_current_hidpi_scale():
    viewer = load_viewer()
    assert viewer.target_logical_size(2) == (768, 576)


def test_target_geometry_at_one_x_scale():
    viewer = load_viewer()
    assert viewer.target_logical_size(1) == (1536, 1152)


def test_target_geometry_rejects_invalid_scale():
    viewer = load_viewer()
    with pytest.raises(ValueError, match="scale factor"):
        viewer.target_logical_size(0)


def test_vmrun_parser_matches_only_the_exact_xp_vmx():
    viewer = load_viewer()
    xp = str(viewer.VMX)
    other = "/home/waterrun/VM/Windows/Windows 10/Windows 10.vmx"
    output = f"Total running VMs: 2\n{xp}\n{other}\n"
    assert viewer.VMX.resolve() in viewer.parse_running_vms(output)
    assert Path(other).resolve() in viewer.parse_running_vms(output)
    assert viewer.target_is_running(output)


def test_mutating_commands_contain_only_the_exact_xp_vmx():
    viewer = load_viewer()
    expected = str(viewer.VMX)
    start = viewer.build_vmrun_start_command()
    resize = viewer.build_resolution_command()
    assert start == ["/usr/bin/vmrun", "start", expected, "nogui"]
    assert resize == [
        "/usr/bin/vmcli",
        expected,
        "MKS",
        "SetGuestResolution",
        "1024",
        "768",
    ]
    for command in (start, resize):
        vmx_arguments = [arg for arg in command if arg.endswith(".vmx")]
        assert vmx_arguments == [expected]
```

- [ ] **Step 2: Run the tests and confirm the expected initial failure**

Run:

```bash
python3 -m pytest /tmp/test_vmware_xp_150.py -q
```

Expected: all 5 tests fail with `viewer script is not installed`; this proves the tests are red because the viewer feature is absent.

- [ ] **Step 3: Create the complete viewer application**

Create `/home/waterrun/.local/bin/vmware-xp-150` with exactly this content:

```python
#!/usr/bin/python3
from __future__ import annotations

import socket
import subprocess
import sys
import threading
import time
from pathlib import Path
from typing import Callable, TypeVar

import gi

gi.require_version("Gtk", "3.0")
gi.require_version("GtkVnc", "2.0")
from gi.repository import Gio, GLib, Gtk, GtkVnc


APP_ID = "io.github.waterrun.XpViewer150"
VMX = Path(
    "/home/waterrun/VM/Windows/Windows_XP_SP3/"
    "Windows XP Professional/Windows XP Professional.vmx"
)
VMRUN = "/usr/bin/vmrun"
VMCLI = "/usr/bin/vmcli"
VNC_HOST = "127.0.0.1"
VNC_PORT = 5997
GUEST_WIDTH = 1024
GUEST_HEIGHT = 768
ZOOM_PERCENT = 150
CONNECT_TIMEOUT_SECONDS = 45.0
MAX_RESOLUTION_ATTEMPTS = 3

T = TypeVar("T")


class ViewerError(RuntimeError):
    pass


def target_logical_size(scale_factor: int) -> tuple[int, int]:
    if not isinstance(scale_factor, int) or scale_factor < 1:
        raise ValueError("scale factor must be a positive integer")
    physical_width = GUEST_WIDTH * ZOOM_PERCENT / 100
    physical_height = GUEST_HEIGHT * ZOOM_PERCENT / 100
    return (
        int(round(physical_width / scale_factor)),
        int(round(physical_height / scale_factor)),
    )


def parse_running_vms(output: str) -> set[Path]:
    return {
        Path(line.strip()).resolve()
        for line in output.splitlines()
        if line.strip().lower().endswith(".vmx")
    }


def target_is_running(output: str) -> bool:
    return VMX.resolve() in parse_running_vms(output)


def build_vmrun_start_command() -> list[str]:
    return [VMRUN, "start", str(VMX), "nogui"]


def build_resolution_command() -> list[str]:
    return [
        VMCLI,
        str(VMX),
        "MKS",
        "SetGuestResolution",
        str(GUEST_WIDTH),
        str(GUEST_HEIGHT),
    ]


def run_checked(command: list[str], timeout: float) -> str:
    try:
        completed = subprocess.run(
            command,
            check=False,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise ViewerError(f"命令无法完成：{command[0]}：{exc}") from exc
    if completed.returncode != 0:
        detail = completed.stderr.strip() or completed.stdout.strip() or "没有详细信息"
        raise ViewerError(f"命令失败：{command[0]}：{detail}")
    return completed.stdout


def wait_for_vnc() -> None:
    deadline = time.monotonic() + CONNECT_TIMEOUT_SECONDS
    last_error = "端口尚未就绪"
    while time.monotonic() < deadline:
        try:
            with socket.create_connection((VNC_HOST, VNC_PORT), timeout=0.5):
                return
        except OSError as exc:
            last_error = str(exc)
            time.sleep(0.25)
    raise ViewerError(
        f"XP 已运行，但本机 VNC {VNC_HOST}:{VNC_PORT} 在 "
        f"{int(CONNECT_TIMEOUT_SECONDS)} 秒内未就绪：{last_error}"
    )


def prepare_target_vm() -> None:
    if not VMX.is_file():
        raise ViewerError(f"找不到 XP 虚拟机：{VMX}")
    running = run_checked([VMRUN, "list"], timeout=10.0)
    if not target_is_running(running):
        run_checked(build_vmrun_start_command(), timeout=60.0)
    wait_for_vnc()


def request_guest_resolution() -> None:
    run_checked(build_resolution_command(), timeout=15.0)


class ViewerApplication(Gtk.Application):
    def __init__(self) -> None:
        super().__init__(application_id=APP_ID, flags=Gio.ApplicationFlags.DEFAULT_FLAGS)
        self.window: Gtk.ApplicationWindow | None = None
        self.display: GtkVnc.Display | None = None
        self.stack: Gtk.Stack | None = None
        self.status_label: Gtk.Label | None = None
        self.spinner: Gtk.Spinner | None = None
        self.connect_timer = 0
        self.resolution_timer = 0
        self.resolution_attempts = 0
        self.enforcing_resolution = False
        self.ready = False
        self.closing = False
        self.failed = False
        quit_action = Gio.SimpleAction.new("quit", None)
        quit_action.connect("activate", self._on_quit_action)
        self.add_action(quit_action)

    def do_activate(self) -> None:
        if self.window is not None:
            self.window.present()
            return
        self._build_window()
        self._set_status("正在检查 Windows XP…")
        self._run_in_thread(prepare_target_vm, self._connect_vnc)

    def _build_window(self) -> None:
        self.window = Gtk.ApplicationWindow(application=self)
        self.window.set_title("Windows XP — 正在连接")
        self.window.set_position(Gtk.WindowPosition.CENTER)
        self.window.set_default_size(420, 180)
        self.window.connect("delete-event", self._on_delete_event)

        status_box = Gtk.Box(orientation=Gtk.Orientation.VERTICAL, spacing=12)
        status_box.set_border_width(24)
        status_box.set_valign(Gtk.Align.CENTER)
        status_box.set_halign(Gtk.Align.CENTER)
        self.spinner = Gtk.Spinner()
        self.spinner.start()
        self.status_label = Gtk.Label(label="")
        self.status_label.set_line_wrap(True)
        status_box.pack_start(self.spinner, False, False, 0)
        status_box.pack_start(self.status_label, False, False, 0)

        self.display = GtkVnc.Display()
        self.display.set_allow_resize(False)
        self.display.set_force_size(False)
        self.display.set_keep_aspect_ratio(True)
        self.display.set_scaling(True)
        self.display.set_smoothing(True)
        self.display.set_shared_flag(True)
        self.display.get_accessible().set_name("Windows XP 画面")
        self.display.connect("vnc-connected", self._on_vnc_connected)
        self.display.connect("vnc-initialized", self._on_vnc_initialized)
        self.display.connect("vnc-desktop-resize", self._on_desktop_resize)
        self.display.connect("vnc-auth-credential", self._on_auth_credential)
        self.display.connect("vnc-error", self._on_vnc_error)
        self.display.connect("vnc-disconnected", self._on_vnc_disconnected)

        self.stack = Gtk.Stack()
        self.stack.set_transition_type(Gtk.StackTransitionType.CROSSFADE)
        self.stack.add_named(status_box, "status")
        self.stack.add_named(self.display, "display")
        self.stack.set_visible_child_name("status")
        self.window.add(self.stack)
        self.window.show_all()
        self.stack.set_visible_child_name("status")
        self.window.present()

    def _set_status(self, message: str) -> None:
        if self.status_label is not None:
            self.status_label.set_text(message)
        if self.stack is not None:
            self.stack.set_visible_child_name("status")

    def _run_in_thread(
        self, work: Callable[[], T], on_success: Callable[[T], None]
    ) -> None:
        def worker() -> None:
            try:
                value = work()
            except Exception as exc:
                GLib.idle_add(self._deliver_failure, str(exc))
            else:
                GLib.idle_add(self._deliver_success, on_success, value)

        threading.Thread(target=worker, daemon=True).start()

    def _deliver_success(self, callback: Callable[[T], None], value: T) -> bool:
        if not self.closing:
            callback(value)
        return GLib.SOURCE_REMOVE

    def _deliver_failure(self, message: str) -> bool:
        if self.enforcing_resolution:
            width, height = self._current_dimensions()
            self.enforcing_resolution = False
            message = f"{message}\n当前 XP 分辨率：{width}×{height}"
        self._fail(message)
        return GLib.SOURCE_REMOVE

    def _connect_vnc(self, _unused: None) -> None:
        if self.display is None:
            return
        self._set_status("正在连接 Windows XP 画面…")
        if not self.display.open_host(VNC_HOST, str(VNC_PORT)):
            self._fail(f"无法打开本机 VNC {VNC_HOST}:{VNC_PORT}")
            return
        self.connect_timer = GLib.timeout_add_seconds(15, self._on_connect_timeout)

    def _on_connect_timeout(self) -> bool:
        self.connect_timer = 0
        self._fail("VNC 已监听，但 15 秒内没有完成画面初始化")
        return GLib.SOURCE_REMOVE

    def _on_vnc_connected(self, _display: GtkVnc.Display) -> None:
        self._set_status("已连接，正在读取 XP 分辨率…")

    def _on_vnc_initialized(self, _display: GtkVnc.Display) -> None:
        self._remove_timer("connect_timer")
        self._ensure_resolution()

    def _on_desktop_resize(
        self, _display: GtkVnc.Display, width: int, height: int
    ) -> None:
        self._ensure_resolution(width, height)

    def _current_dimensions(self) -> tuple[int, int]:
        if self.display is None:
            return (0, 0)
        return (self.display.get_width(), self.display.get_height())

    def _ensure_resolution(
        self, width: int | None = None, height: int | None = None
    ) -> None:
        if self.closing:
            return
        if width is None or height is None:
            width, height = self._current_dimensions()
        if (width, height) == (GUEST_WIDTH, GUEST_HEIGHT):
            self._show_ready()
            return
        if self.enforcing_resolution:
            return
        if self.resolution_attempts >= MAX_RESOLUTION_ATTEMPTS:
            self._fail(
                f"XP 分辨率仍为 {width}×{height}，无法固定为 "
                f"{GUEST_WIDTH}×{GUEST_HEIGHT}"
            )
            return
        self.ready = False
        self.enforcing_resolution = True
        self.resolution_attempts += 1
        self._set_status(
            f"正在把 XP 分辨率恢复为 {GUEST_WIDTH}×{GUEST_HEIGHT}…"
        )
        self._run_in_thread(request_guest_resolution, self._after_resolution_request)

    def _after_resolution_request(self, _unused: None) -> None:
        self.enforcing_resolution = False
        if self.ready:
            return
        if self._current_dimensions() == (GUEST_WIDTH, GUEST_HEIGHT):
            self._show_ready()
            return
        self._remove_timer("resolution_timer")
        self.resolution_timer = GLib.timeout_add_seconds(
            10, self._on_resolution_timeout
        )

    def _on_resolution_timeout(self) -> bool:
        self.resolution_timer = 0
        width, height = self._current_dimensions()
        self._ensure_resolution(width, height)
        return GLib.SOURCE_REMOVE

    def _show_ready(self) -> None:
        if self.window is None or self.display is None or self.stack is None:
            return
        if self._current_dimensions() != (GUEST_WIDTH, GUEST_HEIGHT):
            return
        self._remove_timer("resolution_timer")
        self.enforcing_resolution = False
        self.ready = True
        scale_factor = max(1, self.window.get_scale_factor())
        logical_width, logical_height = target_logical_size(scale_factor)
        self.display.set_size_request(logical_width, logical_height)
        self.window.set_resizable(False)
        self.stack.set_visible_child_name("display")
        if self.spinner is not None:
            self.spinner.stop()
        self.window.set_title("Windows XP — 1024×768 @ 150%")
        self.window.resize(logical_width, logical_height)
        self.window.present()
        GLib.timeout_add(
            100,
            self._verify_allocation,
            logical_width,
            logical_height,
            scale_factor,
            0,
        )

    def _verify_allocation(
        self,
        expected_width: int,
        expected_height: int,
        scale_factor: int,
        attempt: int,
    ) -> bool:
        if self.display is None or self.window is None or self.closing:
            return GLib.SOURCE_REMOVE
        actual_width = self.display.get_allocated_width()
        actual_height = self.display.get_allocated_height()
        if (actual_width, actual_height) == (expected_width, expected_height):
            physical_width = actual_width * scale_factor
            physical_height = actual_height * scale_factor
            description = (
                f"logical={actual_width}x{actual_height}; "
                f"physical={physical_width}x{physical_height}; "
                f"scale={scale_factor}"
            )
            self.display.get_accessible().set_description(description)
            print(description, flush=True)
            return GLib.SOURCE_REMOVE
        if attempt < 9:
            window_width, window_height = self.window.get_size()
            self.window.resize(
                max(1, window_width + expected_width - actual_width),
                max(1, window_height + expected_height - actual_height),
            )
            GLib.timeout_add(
                100,
                self._verify_allocation,
                expected_width,
                expected_height,
                scale_factor,
                attempt + 1,
            )
            return GLib.SOURCE_REMOVE
        self._fail(
            f"窗口内容区为 {actual_width}×{actual_height}，目标为 "
            f"{expected_width}×{expected_height}；已停止，避免错误缩放"
        )
        return GLib.SOURCE_REMOVE

    def _on_auth_credential(self, _display: GtkVnc.Display, _credentials: object) -> None:
        self._fail("本机 VMware VNC 意外要求凭据；不会使用或保存 XP 登录密码")

    def _on_vnc_error(self, _display: GtkVnc.Display, message: str) -> None:
        self._fail(f"VNC 错误：{message}")

    def _on_vnc_disconnected(self, _display: GtkVnc.Display) -> None:
        if not self.closing:
            self._fail("Windows XP 的 VNC 连接已断开")

    def _remove_timer(self, attribute: str) -> None:
        source_id = getattr(self, attribute)
        if source_id:
            GLib.source_remove(source_id)
            setattr(self, attribute, 0)

    def _fail(self, message: str) -> None:
        if self.failed or self.closing:
            return
        self.failed = True
        if self.spinner is not None:
            self.spinner.stop()
        if self.window is None:
            print(message, file=sys.stderr)
            self.quit()
            return
        dialog = Gtk.MessageDialog(
            transient_for=self.window,
            modal=True,
            message_type=Gtk.MessageType.ERROR,
            buttons=Gtk.ButtonsType.CLOSE,
            text="Windows XP 查看窗口无法启动",
        )
        dialog.format_secondary_text(message)
        dialog.run()
        dialog.destroy()
        self.closing = True
        if self.display is not None and self.display.is_open():
            self.display.close()
        self.window.destroy()
        self.quit()

    def _on_delete_event(self, _window: Gtk.Window, _event: object) -> bool:
        self.closing = True
        if self.display is not None and self.display.is_open():
            self.display.close()
        return False

    def _on_quit_action(self, _action: Gio.SimpleAction, _parameter: object) -> None:
        if self.window is not None:
            self.window.close()
        else:
            self.quit()

    def do_shutdown(self) -> None:
        self.closing = True
        self._remove_timer("connect_timer")
        self._remove_timer("resolution_timer")
        if self.display is not None and self.display.is_open():
            self.display.close()
        Gtk.Application.do_shutdown(self)


def main() -> int:
    return ViewerApplication().run(sys.argv)


if __name__ == "__main__":
    raise SystemExit(main())
```

- [ ] **Step 4: Make the launcher executable and run the unit tests**

Run:

```bash
chmod 0755 /home/waterrun/.local/bin/vmware-xp-150
python3 -m pytest /tmp/test_vmware_xp_150.py -q
```

Expected: `5 passed`.

- [ ] **Step 5: Run static and scope checks**

Run:

```bash
python3 -m py_compile /home/waterrun/.local/bin/vmware-xp-150
rg -n 'vmrun|vmcli|\.vmx' /home/waterrun/.local/bin/vmware-xp-150
sha256sum /home/waterrun/.local/bin/vmware-xp-150
```

Expected: compilation succeeds; the only `.vmx` value is the exact XP path; no login credential is present; the hash is recorded in the execution notes.

### Task 2: Add and validate the persistent GNOME launcher

**Files:**
- Create: `/home/waterrun/.local/share/applications/vmware-xp-150.desktop`

**Interfaces:**
- Consumes: executable `/home/waterrun/.local/bin/vmware-xp-150` and application ID `io.github.waterrun.XpViewer150`.
- Produces: GNOME application-menu entry `Windows XP（150%）` with desktop ID `vmware-xp-150.desktop`.

- [ ] **Step 1: Confirm the desktop entry does not exist yet**

Run:

```bash
test ! -e /home/waterrun/.local/share/applications/vmware-xp-150.desktop
```

Expected: exit status `0` on first installation. If the file already exists, inspect it and preserve it until its replacement has passed validation.

- [ ] **Step 2: Create the desktop entry**

Create `/home/waterrun/.local/share/applications/vmware-xp-150.desktop` with:

```ini
[Desktop Entry]
Type=Application
Version=1.0
Name=Windows XP（150%）
Comment=以固定 150% 显示 1024×768 的 Windows XP
Exec=/home/waterrun/.local/bin/vmware-xp-150
TryExec=/home/waterrun/.local/bin/vmware-xp-150
Icon=computer
Terminal=false
Categories=System;
StartupNotify=true
StartupWMClass=io.github.waterrun.XpViewer150
```

- [ ] **Step 3: Validate and register the desktop entry**

Run:

```bash
chmod 0644 /home/waterrun/.local/share/applications/vmware-xp-150.desktop
desktop-file-validate /home/waterrun/.local/share/applications/vmware-xp-150.desktop
update-desktop-database /home/waterrun/.local/share/applications
sha256sum /home/waterrun/.local/share/applications/vmware-xp-150.desktop
```

Expected: no validation output and exit status `0`; the hash is recorded in the execution notes.

### Task 3: Verify the live XP session, geometry, isolation, and persistence

**Files:**
- Read: `/home/waterrun/.local/bin/vmware-xp-150`
- Read: `/home/waterrun/.local/share/applications/vmware-xp-150.desktop`
- Create temporarily: `/tmp/xp-viewer-verify.png`
- Delete after tests: `/tmp/test_vmware_xp_150.py`, `/tmp/xp-viewer-verify.png`

**Interfaces:**
- Consumes: desktop ID `vmware-xp-150`, D-Bus application ID `io.github.waterrun.XpViewer150`, accessible object name `Windows XP 画面`, and VNC port `5997`.
- Produces: an open viewer whose accessible geometry report is `logical=768x576; physical=1536x1152; scale=2`, while `vmrun list` remains unchanged.

- [ ] **Step 1: Capture the VM list and verify the XP framebuffer before launch**

Run in one shell so the baseline remains in memory:

```bash
before_vms="$(vmrun list)"
printf '%s\n' "$before_vms"
/home/waterrun/.local/bin/vncdotool -s 127.0.0.1::5997 capture /tmp/xp-viewer-verify.png
identify /tmp/xp-viewer-verify.png
```

Expected: the list contains the exact XP `.vmx`; the image is `1024x768`.

- [ ] **Step 2: Launch twice and verify single-instance behavior**

Continue in the same shell:

```bash
gtk-launch vmware-xp-150
for attempt in $(seq 1 30); do
    if gdbus call --session \
        --dest org.freedesktop.DBus \
        --object-path /org/freedesktop/DBus \
        --method org.freedesktop.DBus.NameHasOwner \
        io.github.waterrun.XpViewer150 | rg -q 'true'; then
        break
    fi
    sleep 0.5
done
gtk-launch vmware-xp-150
sleep 1
test "$(pgrep -fc '^/usr/bin/python3 /home/waterrun/.local/bin/vmware-xp-150$')" -eq 1
```

Expected: the application ID appears within 15 seconds and exactly one viewer process remains after the second launch.

- [ ] **Step 3: Verify the live GTK content allocation through accessibility metadata**

Run:

```bash
python3 - <<'PY'
import time
import pyatspi


def walk(node):
    if node is None:
        return
    yield node
    for index in range(node.childCount):
        yield from walk(node.getChildAtIndex(index))


deadline = time.monotonic() + 15
while time.monotonic() < deadline:
    desktop = pyatspi.Registry.getDesktop(0)
    app = None
    for index in range(desktop.childCount):
        candidate = desktop.getChildAtIndex(index)
        if candidate is not None and candidate.name == "vmware-xp-150":
            app = candidate
            break
    for node in walk(app):
        if node.name == "Windows XP 画面":
            expected = "logical=768x576; physical=1536x1152; scale=2"
            assert node.description == expected, (node.description, expected)
            print(node.description)
            raise SystemExit(0)
    time.sleep(0.25)
raise SystemExit("找不到 Windows XP 画面或几何信息未就绪")
PY
```

Expected: `logical=768x576; physical=1536x1152; scale=2`.

- [ ] **Step 4: Verify input manually without exposing the XP password**

Click once inside the XP viewer, move the pointer, and press a harmless modifier such as `Shift`; confirm the XP pointer/input focus reacts. If XP is at its login screen, enter its password manually only in the guest login box. Do not place it in a shell command, clipboard automation, script, desktop file, document, log, or screenshot annotation.

- [ ] **Step 5: Close only the viewer and prove the VM is unchanged**

Continue in the shell that holds `before_vms`:

```bash
gapplication action io.github.waterrun.XpViewer150 quit
for attempt in $(seq 1 20); do
    if gdbus call --session \
        --dest org.freedesktop.DBus \
        --object-path /org/freedesktop/DBus \
        --method org.freedesktop.DBus.NameHasOwner \
        io.github.waterrun.XpViewer150 | rg -q 'false'; then
        break
    fi
    sleep 0.25
done
after_vms="$(vmrun list)"
test "$after_vms" = "$before_vms"
/home/waterrun/.local/bin/vncdotool -s 127.0.0.1::5997 capture /tmp/xp-viewer-verify.png
identify /tmp/xp-viewer-verify.png
```

Expected: the viewer exits, `vmrun list` is byte-for-byte unchanged, the XP VM is still running, and the framebuffer is still `1024x768`.

- [ ] **Step 6: Verify persistence and leave the viewer ready for use**

Run:

```bash
gtk-launch vmware-xp-150
for attempt in $(seq 1 30); do
    if gdbus call --session \
        --dest org.freedesktop.DBus \
        --object-path /org/freedesktop/DBus \
        --method org.freedesktop.DBus.NameHasOwner \
        io.github.waterrun.XpViewer150 | rg -q 'true'; then
        break
    fi
    sleep 0.5
done
desktop-file-validate /home/waterrun/.local/share/applications/vmware-xp-150.desktop
python3 -m pytest /tmp/test_vmware_xp_150.py -q
```

Expected: the menu launcher reopens the same 150% viewer, desktop validation stays silent, and all 5 tests pass.

- [ ] **Step 7: Remove temporary verification files**

Delete `/tmp/test_vmware_xp_150.py` and `/tmp/xp-viewer-verify.png` with a patch-based deletion. Then run:

```bash
test ! -e /tmp/test_vmware_xp_150.py
test ! -e /tmp/xp-viewer-verify.png
vmrun list
```

Expected: both temporary files are absent and the XP VM remains listed as running.

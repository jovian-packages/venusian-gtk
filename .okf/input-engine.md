---
type: Component
title: GTKInputEngine
description: The gtk input engine behind input.gtk — four GTK event controllers plus a focus hook per window, buffered callbacks, evdev gamepads rescanned on a tick cadence, failures recorded for errors().
tags: [gtk, input, keyboard, mouse, gamepad, evdev]
status: draft
generated: { by: claude-opus-5/claude-code, at: "2026-09-17T00:00:00Z" }
sources:
  - id: engine
    resource: src/Input/GTKInputEngine.php
    title: GTKInputEngine
  - id: factory
    resource: src/Input/GtkControllerFactory.php
    title: GtkControllerFactory
  - id: keymap
    resource: src/Input/EvdevKeyMap.php
    title: EvdevKeyMap
  - id: pads
    resource: src/Input/EvdevPadScanner.php
    title: EvdevPadScanner
  - id: windows
    resource: src/Input/NativeGtkWindowSpace.php
    title: NativeGtkWindowSpace
---

# Overview

`GTKInputEngine` implements Surface's `InputEngineDriver`; the provider binds
it as a singleton aliased `input.gtk`. Seams, each with a real class and an
ext-free test fake:

| Seam | Real | Job |
|---|---|---|
| `ControllerFactory` | `GtkControllerFactory` | attach / detach / forget the controller set, `currentButton()`, `unicode()` |
| `GtkWindowSpace` | `NativeGtkWindowSpace` | live window name → `GtkWindow` handle |
| `PadScanner` | `EvdevPadScanner` over `EvdevScanner` | list / open / close gamepad nodes |

`rescan_ticks` is a constructor arg, default `60`. No config file. Last arg
`input_nodes` (`Closure(): array<path, readable>`, default
`glob('/dev/input/event*')` + `is_readable`) feeds the access notice.

# Controller set

Per window, attached lazily in `poll()` to any window not seen yet:[^factory]

- `GtkEventControllerKey`, phase `CAPTURE` — focused child widgets cannot swallow game keys. `key-pressed`, `key-released`, `modifiers` (connected via `Bridge::connect`; jovian/gtk's `onModifiers` is untyped and forwards the emitter).
- `GtkEventControllerMotion` — `motion`, `enter`, `leave`.
- `GtkGestureClick`, `setButton(0)` — every button.
- `GtkEventControllerScroll(BOTH_AXES)` — handler reads `getUnit()` (valid only inside it) through a weak ref to the DTO and passes it on.
- `notify::is-active` on the window itself (`Bridge::connect`, handler id kept in `WindowControllers::$signals`); handler boxes the emitter and passes `GtkWindow::isActive()`.

The factory holds the controller DTOs by registry handle; dropping them
releases the handles. Detach = `Bridge::disconnect` the window handlers, then `removeController` each, on a live window. A
window that is gone (or whose name now maps to a new handle) is only
*forgotten* — no call touches it; its controllers died with it and send no
leave or release, so when it was the pointer's window the engine sets the
window to `null` (position kept) and releases every mouse button.
`disconnect()` detaches live windows and forgets gone ones.

# Failures

Nothing raises a PHP warning or escapes `poll()` — kernels install
`HandleExceptions`, which turns warnings into `ErrorException` and kills the
tick. A failure is recorded: `errors(): list<string>` returns messages
(`input.gtk: …`) since the last call and drains them. When
`function_exists('app') && app()->bound('log')`, each is also
`app('log')->warning()`; a throwing log is ignored.

- Window `attach()` throws → first try is immediate; retries only on rescan polls (`polls % rescan_ticks === 0`). Recorded once per failure streak; streak resets on success, window gone, or new handle.
- Scan throws → recorded, this rescan skipped. Node `open()` throws → recorded, that node skipped.
- Scan returns `[]` while input nodes exist and none is readable → one notice per connect: `no readable /dev/input/event*: add the user to the input group`.

`NativeGtkWindowSpace` answers `[]` when `native-window` is not bound
(as appkit). Otherwise it keeps `GTKWindowDelegate`s whose `isPresenting()` is
true (checks the closed flag before any native call), so a window gets
controllers once presented.

# Buffered callbacks

GTK fires signals while `os` pumps, before `input` ticks. Callbacks never
touch a device: each buffers a step. `poll()` = settle keyboard + mouse →
apply buffer → sync windows → rescan pads when due → poll each pad. Edges
land on this tick; a tap inside one poll reads pressed and released
together. Handler-only values (`currentButton()`) resolve inside the
callback, and so does the scroll unit. Never call `GtkEventControllerKey::getGroup` or
`GtkGesture::getBoundingBoxCenter` outside a handler; never call
`GtkGesture::getBoundingBox`.

# Keys

- `Key` = `EvdevKeyMap::key(keycode − 8)` — GTK hardware keycode is evdev + 8 on X11 and Wayland. Layout independent. Unmapped → `Key::UNKNOWN`.[^keymap]
- Text = `GdkKeyval::toUnicode(keyval)` when `> 0x1F`, `!== 0x7F`, and state has none of CONTROL (4) / ALT (8) / SUPER (67108864).
- `key-pressed` returns `false` — widgets still get the key.
- Modifiers: on a Pi 5 (labwc) the `modifiers` signal also carries the pre-key state (Shift press → no SHIFT). So once this focus has seen a sided modifier key, held keys decide that kind (either side down); kinds never keyed follow the last signal (modifier held before focus). Resolved after each poll's buffered steps; focus loss clears both. Signal bits (handler returns `false`): SHIFT 1, CONTROL 4, ALT 8; `meta` = SUPER (67108864) only. META (268435456) is ignored — common keymaps set it alongside ALT. Key-event `state` is pre-event (a Shift press reports no SHIFT, its release still reports SHIFT), so it is used only for the text filter.

# Focus loss

Window `is-active` false → buffered release-all, applied on the next poll
after settle (as appkit): `update(false)` on every down key, modifiers
cleared, `update(false)` on every `MouseButton`. Release edges
(`wasReleased`) read on that poll; text typed earlier in the poll is kept.
Pointer window and position kept.

A failing `attach()` rolls back: added controllers removed, the
`notify::is-active` handler (connected first) disconnected, then rethrown.
No ext-free test — every step is a native call.

# Pointer

Coordinates are window-widget based: the origin is the `GtkWindow`'s
top-left, client-side header bar included — not the content area.

- `motion` → position + window; delta against that window's previous sample (first sample: none). `enter` is a sample without delta.
- `leave` → window `null` only when the leaving window is current; position kept.
- click `pressed` / `released` → `currentButton()`: 1 LEFT, 2 MIDDLE, 3 RIGHT, 8 X1, 9 X2; else ignored. Also sets position.
- `scroll` → `addWheel(-dx / s, -dy / s)`; `s` = 10 for `GdkScrollUnit::SURFACE` (pixel deltas, touchpads), 1 for `WHEEL` (lines). GTK is down-positive, Surface up-positive. Returns `false`.
- Natural scroll is **not detectable** on GTK 4.8: deltas report content direction, so with natural scrolling on, the sign is the reverse of the physical roll.

# Gamepads

Linux evdev via `microscrap/scrapyard-evdev`.[^pads] `connect()` scans once;
every `rescan_ticks`-th poll: close + drop pads reporting
`connected() === false`, then open newly listed nodes. Each pad is a Surface
`ICInput` with id = node basename (`event3`); it is polled every tick and
reads all-released once disconnected. `gamePads()` / `gameControllers()`
list only pads whose `ICInput::connected()` is true (stick →
controllers, else pads); a dropped pad leaves the lists at once, closed on
the next rescan. A node that opens but answers no probe (`EvdevGamepadException`)
is not a pad. Connect / disconnect mail is the `input` resource's job.

Reading `/dev/input/event*` needs the user in the `input` group (nodes are
`root:input 0660`).

[^factory]: `src/Input/GtkControllerFactory.php`
[^keymap]: `src/Input/EvdevKeyMap.php`
[^pads]: `src/Input/EvdevPadScanner.php`

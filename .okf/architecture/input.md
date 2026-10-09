---
type: Module
title: Input
description: GTKInputEngine, HumanInput's gtk engine.
resource: src/Input/GTKInputEngine.php
tags: [gtk, human-input]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-09T12:00:00Z }
sources:
  - id: engine
    resource: src/Input/GTKInputEngine.php
    title: GTKInputEngine
---

# Engine

GTK 4: no application-wide event hook. Each `poll()` diffs `GTKBridgeDriver::all()` against the wired set; each new window's `GtkApplicationWindow` gets five capture-phase controllers (key, motion, scroll `BOTH_AXES`, click with button 0, focus) and a `notify::is-active` handler. Signals copy their arguments into a `SeenEvent` and call `GTKSession::see()`; the engine is one of the session's taps. A window gone from `all()` is forgotten (its controllers died with it); `disconnect()` removes controllers from windows still open. GTK stages nothing, so there are no staged windows here.

Keys by position: hardware keycode − 8 through Surface's `LinuxKeycodes` (X11, Wayland), or the macOS key code through `MacKeycodes`. Keyval only for text (`gdk_keyval_to_unicode`), withheld for Ctrl/Super/Meta chords, Alt chords off macOS, control characters. Modifiers from key events: the event's state (GTK's, from before the event) with the event's own modifier key applied, either side held keeping it; the `modifiers` signal is not read. Super and Meta are meta. Buttons through `getCurrentButton()` (1/2/3/8/9). Wheel: dy negated (GTK down-positive), dx as is, `SURFACE` units ÷ 10; both turned back when `GdkScrollEvent::getRelativeDirection()` (GTK 4.20+, through `getCurrentEvent()`) says `INVERTED`, as sdl3 undoes `SDL_MOUSEWHEEL_FLIPPED`. Focus leave, `is-active` false, or a window closing: release all unless another driver window is active.

Registered by `VenusianGTKServiceProvider::input()`: `extend('gtk', fn (InputFrame) => …)`.

# Measured

GTK's hardware keycode is the evdev code + 8 on X11 and Wayland, `[NSEvent keyCode]` on macOS (`gdkmacosdisplay-translate.c`). Signals hand keyval, keycode and modifier state over as ints. `notify::*` cannot be emitted from PHP (GParamSpec), so tests emit the focus controller's `leave`.

macOS GTK follows every key press with a second `key-pressed`: keyval `0xFFFFFF` (`GDK_KEY_VoidSymbol`), keycode 0, no release, after a `modifiers` signal with state 0 (seen 2026-10-09, GTK 4.24). Key-release state is from before the release, and no `modifiers` signal follows a modifier's release. The engine drops VoidSymbol events (keycode 0 is `a` on macOS) and reads modifiers from key events only.

---
type: Component
title: BridgedLinuxOSSession
description: >-
  The GTK side of Surface's bridge: gtk_init once, no-op connect and
  disconnect, a main-context pump that already speaks milliseconds, and the
  GtkWindow factory with its scaffold child.
tags: [gtk4, bridge, session, linux]
status: draft
generated: { by: claude-opus-5/cursor, at: "2026-08-29T21:37:00Z" }
sources:
  - id: session
    resource: src/Sessions/BridgedLinuxOSSession.php
    title: BridgedLinuxOSSession
  - id: delegate
    resource: src/Windows/GTKWindowDelegate.php
    title: GTKWindowDelegate
  - id: provider
    resource: src/Providers/VenusianGTKServiceProvider.php
    title: VenusianGTKServiceProvider
  - id: gtk-runtime
    resource: https://github.com/jovian/gtk/blob/main/.okf/runtime.md
    title: jovian/gtk runtime — GObject, Registry, Lifetime, Bridge
  - id: gtk-traps
    resource: https://github.com/jovian/gtk/blob/main/.okf/traps
    title: jovian/gtk traps — boot-required, handle recycling
---

# Overview

`BridgedLinuxOSSession` extends Surface's abstract session and fills its
four hooks.[^session] Surface owns the state machine — idempotency, the
drain on disconnect, the disconnected `pump()` answering zero — so this
class holds only GTK specifics.

The service provider binds it as a singleton behind the container alias
`linux.bridge`, which is the only name Surface looks for.[^provider]

# The four hooks

| Hook | GTK4 |
|---|---|
| `initializeEngine()` | `Lifetime::boot()` |
| `connectToEngine()` | nothing |
| `disconnectEngine()` | nothing |
| `pumpEngine(int $ms)` | `Bridge::pump($ms)` |

# Why connect and disconnect do nothing

They are honest no-ops, not stubs. `gtk_init` has no counterpart to undo,
and a Linux desktop derives taskbar and dock presence from *mapped windows*,
not from a library initialising. There is nothing to show and nothing to
withdraw until a window is presented.

macOS differs: connecting there flips activation policy, which raises a Dock
icon on its own. Surface's contract therefore promises only that the engine
will accept conjuring, never that the OS shows something.

# Boot is mandatory and never implicit

`Lifetime::boot()` is the only thing that initialises GTK. Constructing a
DTO before it throws `NotBooted`, and calling a raw C-named helper before it
crashes the process outright.[^gtk-traps] Surface's `$initialized` flag runs
this hook once at construction, before anything can reach a widget.

# Minting windows

`provisionNewWindow()` is the fifth verb on Surface's contract and the one
piece with no shared policy behind it. Surface cannot own it — constructing
a `GtkWindow` is GTK — so the session is the factory.[^session]

The session itself is two calls, `GtkWindow::new()` then
`setDefaultSize($width, $height)`. Everything else is the delegate.

# The scaffold child is deliberate

`GTKWindowDelegate` does not make the content root the window's child.
It builds a vertical `GtkBox` **scaffold**, puts a `GtkFixed` **content**
inside it, and sets the scaffold as the child.[^delegate]

```text
GtkWindow
  └── GtkBox (VERTICAL)        scaffold — chrome prepends here
        └── GtkFixed           content — where views go
```

Linux menus are widgets *inside* the window, unlike the macOS menu bar. A
later chrome slice therefore prepends into this box rather than reparenting
a live view tree, which GTK makes expensive and lossy. The empty box costs
nothing now and buys that.

`GtkFixed` for content is what mirrors AppKit's absolute-positioned `NSView`
placement; GTK's layout containers have no counterpart on the other side.
The fixed must be `hexpand` + `vexpand`: a box gives it full width but only
its natural height otherwise, and a GtkFixed's natural height is just the
extent of its children — not the window.

# Size is a request here, not a fact

`setDefaultSize()` asks. Real allocation exists only after `present()` plus
a pump, and `getWidth()` / `getHeight()` read 0x0 before that. macOS has no
such phase — an `NSRect` content rect *is* the size at construction. Nothing
in the current slice hides that difference from a caller.

# Close destroys, unlike macOS

`destroy()` maps to `GtkWindow::destroy()`, and a user closing the window
does the same thing. The handle is dead afterwards and GTK recycles it, so
polling `isPresenting()` on a closed window is a touch on a dead handle.
The macOS delegate is the opposite: `setReleasedWhenClosed(false)` keeps the
handle alive and close only hides. Any last-window-closed exit condition has
to be built per platform.

# Units and blocking

`Bridge::pump()` already takes integer milliseconds, so the budget passes
straight through — which is why Surface adopted milliseconds as its unit.
A non-zero budget may block for its full length when the main context has
nothing queued, and that measured behaviour is the source of the contract's
may-block promise.[^gtk-runtime]

# GtkApplication is deliberately untouched

`GApplication::run()` is bound but must never be called: a remote
`GApplication` instance segfaults on window creation. `GtkApplicationWindow`
may only be constructed inside an `activate` handler. PHP drives this loop by
iterating the default main context instead, which is what `pump()` does.

# Failure

`GTKBridgeException` (a `Surface\Contracts\Bridge\BridgeException`) wraps the
`RuntimeException` `Lifetime::boot()` raises when `gtk_init` fails, which in
practice means no display seat — check `DISPLAY` / `WAYLAND_DISPLAY` and a
logged-in session. Surface never names this type; a sketch catches the base
`BridgeException`.

[^session]: BridgedLinuxOSSession
[^provider]: VenusianGTKServiceProvider
[^gtk-runtime]: jovian/gtk runtime — GObject, Registry, Lifetime, Bridge
[^gtk-traps]: jovian/gtk traps — boot-required, handle recycling

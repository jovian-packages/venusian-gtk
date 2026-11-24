---
type: Module
title: Session
description: GtkApplication held while connected, a budgeted GMainContext pump, a one-shot fd wake, and AppKit activation on macOS.
resource: src/Gtk.php
tags: [gtk, session, linux, macos]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
sources:
  - id: gtk
    resource: src/Gtk.php
    title: Gtk (the Toolkit)
  - id: session
    resource: src/GtkSession.php
    title: GtkSession
  - id: driver
    resource: src/GtkAdapterDriver.php
    title: GtkAdapterDriver
---

# Overview

Three classes, on Topside's toolkit layer:

| Class | Extends / implements | Job |
|---|---|---|
| `GtkAdapterDriver` | `Surface\Toolkits\Drivers\ToolkitAdapterDriver` | refuses without ext-gtk, and without ext-appkit on macOS; makes the session from [config](/api/config.md); declares `GtkWindow` as its window class |
| `GtkSession` | `Surface\Toolkits\ToolkitSession` | builds the `Gtk` toolkit once; `application()` returns it typed |
| `Gtk` | `Surface\Contracts\Toolkits\Toolkit` | the GTK calls below |

The window list and the after-pump settle are Topside's (`ToolkitSession`); the session has no pump override.[^session]

# Calls

| When | GTK calls |
|---|---|
| built (once per process) | `g_set_prgname(id)` (X11 `WM_CLASS`, so a dock matches the window to `<id>.desktop`), `GtkApplication::new(id, unique ? DEFAULT_FLAGS : NON_UNIQUE)` (throws for an id GLib refuses), a no-op `activate` handler, `register(null)`, the default `GMainContext` |
| `connect()` | `hold()` (stays alive with no window), `activate()` |
| `disconnect()` | `release()` |
| `pump($ns)` | budget > 0: a one-shot `g_timeout_add(ceil ms)` and one blocking `iteration(true)`; the timeout is removed if it did not fire. Then up to `gtk.loop_drain_limit` (64) non-blocking iterations while `pending()` |

The drain limit keeps a source that is always ready (an idle handler re-adding itself) from holding one pump.[^gtk]

# Wake

`wakeDescriptor($fd)` keeps the loop's descriptor; each `pump()` arms `g_unix_fd_add($fd, IN)` if it is not armed. The callback returns `G_SOURCE_REMOVE`, so a descriptor the loop has not read yet ends one wait and cannot keep a drain busy.[^gtk]

# macOS

GTK runs on NSApplication there. GDK activates the app once, at display open, and macOS declines that for a process started from a terminal. `bringForward()` (called by [the window](/architecture/window.md)'s `present()`) asks `NSApplication::activateIgnoringOtherApps(true)`. Wayland and X11 focus on present.[^gtk]

[^gtk]: Gtk (the Toolkit)
[^session]: GtkSession

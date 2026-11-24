---
type: Module
title: Window
description: GtkApplicationWindow with a content box, wired to Topside's window lifecycle; content size compared after each pump.
resource: src/GtkWindow.php
tags: [gtk, windows]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
verified:
  - { by: claude-opus/5.5, at: 2026-11-20T21:20:00Z }
sources:
  - id: window
    resource: src/GtkWindow.php
    title: GtkWindow
---

# Overview

`GtkWindow extends Surface\Toolkits\ToolkitWindow`. The close path, confirm-close, `close()` from code, resize mail and the after-pump `settle()` call are Topside's; this class builds the native window and connects GTK's signals to them.[^window]

# Native

* `GtkApplicationWindow::new` of the session's application; title = name; `setDefaultSize`.
* A child window is `setTransientFor(parent)` + `setModal(true)` and joins the parent's `GtkWindowGroup`; a top-level window makes its own group, so a child's grab blocks only its parent.
* Child: a vertical scaffold box holding a content box that expands both ways (`contentArea()`).
* `native()` = the `GtkApplicationWindow`; `size()` = the content box's allocation.

# Signals

| GTK | Goes to |
|---|---|
| `close-request` | `allowsUserClose()`: kept (returns true) while confirming; else the close path, and false lets GTK destroy the window |
| `notify::is-active` | `WindowFocused`, once per activation (`seen_inactive` is set again in `settle()` when the window is inactive at the end of a pump; on X11 a popover grab makes it inactive and active within one dispatch) |
| `notify::is-active`, active / inactive | `gainedFocus()` / `lostFocus()` (topside's menus), every time |
| `notify::scale-factor`, the surface's `notify::scale` (connected on `realize`) | `WindowScaleChanged` when the scale differs from the last posted |

Every handler holds the window weakly, so a closed window is freed.

# Size

GTK 4 has no resize signal. `settle()` compares the content box's allocation with the last one and calls `resized()`; a size with a zero side (not yet allocated) is recorded without mail, so the first allocation is no resize.[^window]

# Close

* `closeNative()`: a realized window `close()`s (close-request runs inside); a never-realized one takes the close path here and is `destroy()`ed, since GTK skips close-request for it.
* `beforeClose()` clears the default widget (GTK keeps a destroyed default widget as the default).

# Not ported yet

From the [bridge-era window](/architecture/windows-and-menus.md): menu bars, focus tracking (FocusGained/FocusLost), window and button-role keys, right clicks, child-window rules.

[^window]: GtkWindow

---
type: Module
title: Window
description: GtkApplicationWindow with a content box, wired to Topside's window lifecycle; content size compared after each pump.
resource: src/GtkWindow.php
tags: [gtk, windows]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-26T16:00:00Z }
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

GTK 4 has no resize signal. `settle()` compares the content box's allocation with the last one and calls `resized()`; a size with a zero side (not yet allocated) is recorded without mail, so the first allocation is no resize. The same call tells each view that watches its size (`watchSize()` / `unwatchSize()`, held weakly) to compare its own: `ViewResized` is topside's `sizeChanged()`.[^window]

# Close

* `closeNative()`: a realized window `close()`s (close-request runs inside); a never-realized one takes the close path here and is `destroy()`ed, since GTK skips close-request for it.
* `beforeClose()` clears the default widget (GTK keeps a destroyed default widget as the default).

# Alert

`showAlert()`: a `GtkAlertDialog` (made with `g_object_new_with_properties`: `gtk_alert_dialog_new` is a printf), `choose()` on the window with a `GCancellable`; `_` in a label is doubled. Default and cancel buttons from the alert; Esc and the dialog's close answer the cancel button, a cancellation the `GError` path, also the cancel button. No style, check box or title: `GtkAlertDialog` has none. `closeAlert()` cancels and dispatches what is pending until the choose finished: cancelled outside a GLib dispatch, GTK finishes it in an idle and only then destroys the dialog's window, which by then went with the parent (destroy-with-parent) and was freed (`gtk_window_destroy` CRITICAL, gtkalertdialog.c 4.24). `tests/Integration/AlertTest.php`: 3 cases; `GtkAlertDialog` gives no way to its buttons.

# Save panel

`showSave()`: a `GtkFileDialog` with the request's title, `setInitialName()` and `setInitialFolder(GFile::newForPath())` (each only when given), modal, `save()` on the window with a `GCancellable`. A cancelled or dismissed panel makes `saveFinish()` throw `GError`: the answer is null. `closeSave()` cancels and dispatches what is pending until the save finished, as `closeAlert()` does; the late answer is not posted. `GtkFileDialog` has no getter for what it was given and no way to its panel, so `tests/Integration/SaveTest.php` (5 cases) covers the panel shown without waiting, a request with nothing set, the cancellation, the window's close, and one question at a time; the name, title and folder shown are not read back.

# Browse

`openUrl()`: `GtkUriLauncher::new($url)->launch()`, the window its parent.

# Not ported yet

From the [bridge-era window](/architecture/windows-and-menus.md): menu bars, focus tracking (FocusGained/FocusLost), window and button-role keys, right clicks, child-window rules.

[^window]: GtkWindow

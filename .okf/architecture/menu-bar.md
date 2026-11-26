---
type: Module
title: Menu bar
description: One GMenu model per window and one for none focused, with the GActions it points to - how nodes become items and sections, how GTK's macOS application menu is answered, and which shortcuts wait for the focused widget.
resource: src/Menus/
tags: [gtk, menus, macos, linux]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-26T09:00:00Z }
sources:
  - id: bar
    resource: src/Menus/GtkMenuBar.php
    title: GtkMenuBar
  - id: accelerator
    resource: src/Menus/GtkAccelerator.php
    title: GtkAccelerator
  - id: tests
    resource: tests/Windowless/MenuBarTest.php
    title: Windowless menu tests
  - id: gtk
    resource: https://gitlab.gnome.org/GNOME/gtk/-/tree/4.24.1
    title: GTK 4.24.1 source (gtkapplication-quartz.c, gtkapplication-quartz-menu.c, gtkapplication-quartz.ui, gtkmenutrackeritem.c, gtkwindow.c, gdk/macos/gdkmacosdisplay-translate.c)
---

# Overview

`GtkMenuBar` is topside's `TKMenuBar` on GTK: it is told single operations and keeps no rule of the menus. The driver names it. Only GTK's own API is used.[^bar]

| | Linux | macOS |
|---|---|---|
| Where GTK shows it | a `GtkPopoverMenuBar` over the model, put above the content by `GtkWindow::attachMenuBar()` | the application's menubar: `show()` calls `GtkApplication::setMenubar()` with the focused window's model, and the bar for no window when none is |
| A window's actions | the window's own (`win.menu-<uuid>`), resolved in that window only | the same: GTK's macOS menu resolves `win.` through the active window |
| The bar for no window | not shown | `app.menu-<uuid>`, in the application; its accelerators are on only while it is shown |

`GtkWindow` reports `notify::is-active` to topside: `gainedFocus()` when the window becomes active, `lostFocus()` when it stops.

# Natives

| What | How |
|---|---|
| A menu | a `GMenuItem` with a submenu, and an action with no handler: GTK reads a submenu's sensitivity from its action. `GtkPopoverMenuBar` does not for a top-level menu, so its button's sensitivity is set too (Linux) |
| A line | GTK draws a line between sections: a menu is a list of sections, and a line coming, going or moving lays that menu's sections out again. Every other change is one insert or remove at its place |
| An item | a plain action whose `activate` calls `activated(uuid)` |
| A toggle | a boolean stateful action; activating it asks to change the state, and topside sets it through `change()` |
| A choice | one string stateful action, one item per option with its value as the target |
| A label | `GMenuItem` is copied into the menu: the kept item is relabelled and put in again. `_` is doubled: GTK reads one as a mnemonic |
| Edit kinds | the focused widget's own action (`text.undo`, `clipboard.copy`, `selection.select-all` then `list.select-all`, …); Paste and Match Style is `clipboard.paste` too, as GTK's text widgets have one paste and it reads plain text |
| Close Window | the window's `close()`, through `close-request` and its confirm |
| Minimize, full screen | `supports()` is false: ext-gtk binds no call for either. Topside shows them disabled and logs once |
| About panel | `GtkSession::showAbout()`: `GtkAboutDialog`, not modal, one at a time; credits are its comments |

# The macOS application menu

GTK builds it from its own `gtkapplication-quartz.ui`: About, Settings, Services, Hide, Hide Others, Show All, Quit, with GTK's labels and order. Its About, Settings and Quit run `app.about`, `app.preferences` and `app.quit`, and GTK shows each disabled while the application has no such action.

* The shown bar's About, Preferences and Quit from the application's own menu are those three actions, added to the application when the bar is shown and taken out when another is. Their shortcuts are the accelerators of those names.
* Hide, Hide Others and Show All there have no native: GTK's are always there. Written elsewhere, they are shown disabled.
* Any other node of the application's own menu goes in an ordinary menu under the application's name, after GTK's. That menu is in the bar only while it holds an item.

Measured 2026-11-22, GTK 4.24.1, 8.4 NTS and ZTS, in the real system bar with no window shown. GTK's macOS menu reads `win.` actions through the active window only: with no window shown, a window's items are disabled there. Not measured: the system bar with a window active, a real key press, the bar inside a window on Linux.

# Shortcuts

GDK on macOS turns every key press into a GTK event (`gdkmacosdisplay-translate.c`): AppKit's key equivalents never see them, and a key equivalent in the system bar only shows the shortcut. GTK runs application accelerators in the window's capture phase, before the focused widget (`gtkwindow.c`, `gtk-application-shortcuts`). So:

| Shortcut | How |
|---|---|
| with Primary, Ctrl or Alt | `setAccelsForAction()` for the node's action. GTK shows it from there |
| none of the three (`yieldsToFocus()`) | the item's `accel` attribute only shows it; a `GtkShortcut` on the window's bubble-phase `GtkShortcutController` runs it when the focused widget did not use the key |
| an edit kind's | shown only: the widgets' own keys do the work (GTK's default accelerators on macOS, the widget's shortcuts on Linux) |

`GtkAccelerator::accelerator()`: Primary is `<Meta>` (Command) on macOS and `<Control>` elsewhere; Ctrl `<Control>`; keys by GDK name.

[^bar]: GtkMenuBar

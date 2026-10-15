---
type: Module
title: Windows and menus
description: GtkApplicationWindow per name, close/focus mail, GMenu bars in-window (Linux) or app-wide (macOS), About.
resource: src/Windows/
tags: [gtk, windows, menus]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T23:05:31Z }
sources:
  - id: window
    resource: src/Windows/GTKWindow.php
    title: GTKWindow
  - id: bar
    resource: src/Windows/GTKMenuBar.php
    title: GTKMenuBar
  - id: driver
    resource: src/Bridge/GTKBridgeDriver.php
    title: GTKBridgeDriver
---

# Window

`GTKWindow implements ToolkitWindow`. `GtkApplicationWindow` of the session's application; title = name; default size. Child = vertical scaffold box: bar (Linux) above a content area expanding both ways (`contentArea()`), which holds the [primitive](/architecture/primitives.md) content container (`content()`).[^window]

* `close-request` → remove the primitive tree, drop a pending resize, post `WindowClosed`, driver forgets, return `false` (GTK destroys).
* Content-area size polled by the session after each pump → `WindowResized` (latest per pump) on change, including an in-window bar arriving or going.
* `close()`: realized window → `GtkWindow::close()` (close-request runs inside). Never-realized window → GTK skips close-request, so the close path runs here and the window is destroyed.
* `notify::is-active` → active: macOS bar switch, post `WindowFocused`; inactive: driver shows the default bar.
* `present()` → `present()` + session `bringForward()`.

# Menu bar

`GTKMenuBar`: profile → `GMenu` model + `GSimpleActionGroup`.[^bar]

* Folders → submenus; separators → section boundaries.
* Scope: window bar names `win.<id>` (group inserted on the window as `win`); default bar names `app.<id>` (actions added to the application's map, so they work with no window).
* Edit role: `activate` → `GTKMenuBar::edit()` on the bar's window (default bar: active window) → focused widget's own action (`EDIT_ACTIONS`: `text.undo`, `clipboard.copy`, `selection.select-all` then `list.select-all`, …). No accelerator; posts nothing.
* Plain item: `activate` → `MenuActivated`; About → session About; Quit → `QuitRequested(window)`.
* Toggle: stateful boolean action, `change-state` sets state + posts `MenuToggled`. `setToggle` sets state, no mail.
* Mac text keys (macOS only): `GTKMacTextKeys` capture-phase key controller on each text input's entry and text area's text view; Cmd/Option arrows, Cmd-Backspace, Option-Backspace/Delete → `move-cursor` / `delete-from-cursor` on the GtkText or GtkTextView. Window's capture controllers (HumanInput) run first.
* Hotkeys: `<Meta>` (Command) on macOS, `<Control>` elsewhere. GTK 4 `<Primary>` = Control on every platform. Set through `setAccelsForAction`.

Placement:

* Linux: `GtkPopoverMenuBar` from the model, prepended in the window's scaffold; replaced on `setMenuBar`.
* macOS (`appWideBar()`): app menubar = active window's model (`showWindowMenuBar`), else the default bar.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar's actions removed from the application. `installDefaultMenuBar()` builds once, adds its `app` actions, turns its accelerators on, sets it as menubar. `showWindowMenuBar()` turns default accelerators off so a hotkey never names both a window action and an app action. `showDefaultMenuBar()` = install unless one of our windows is active. `defaultMenuBar()` for tests.[^driver]

[^window]: GTKWindow
[^bar]: GTKMenuBar
[^driver]: GTKBridgeDriver

# Right clicks

`GTKRightClicks` per window: capture-phase `GtkGestureClick` (button 0, never claims) and a capture-phase `GtkEventControllerLegacy` for holds. Secondary = button 3, or button 1 with `GDK_CONTROL_MASK` on macOS (GDK's macOS backend reports Ctrl-click as button 1 + Control; measured 2026-10-09). On Linux a primary press held within `gtk-dnd-drag-threshold` for `gtk-long-press-time` (read from `GtkSettings` with `g_object_get_property`) is a right click: the Pi's ft5x06 touchscreen is registered as a pointer, a finger arrives as `BUTTON_PRESS` button 1 (measured), so a held press is the only right click it can make. Timed with `g_timeout_add` from raw events, because a `GtkGestureLongPress` is cancelled when a widget's own gesture (a button's) claims the press. `at(x, y)`: `pick()` with `GTK_PICK_INSENSITIVE | GTK_PICK_NON_TARGETABLE`, walk `getParent()` to the first native a registry primitive owns → `View\RightClicked` from its border-box top-left (`computePoint`, less the widget's `computeBounds` origin: GTK's own coordinates start inside border and padding), nothing if it or a group is disabled; reaching the content box first → `WindowRightClicked` in content coordinates; outside the content (Linux menu bar) → nothing.

Context menus (slice 27): after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `GTKContextMenu` builds a `GtkPopoverMenu` over a `GMenu` (separators → sections, folders → submenus) whose items name `ctx.<id>` in a `GSimpleActionGroup` inserted on the popover itself; parented to the content box, `setPointingTo` the click (1×1), no arrow; `closed` → unparent on the next main-loop turn (GTK closes the menu before running the chosen action). An item's `activate` posts `MenuActivated`. `at()` returns whether a menu opened; the click gesture then claims its press (`GtkEventSequenceState::CLAIMED`), which cancels a widget's own context menu (a text input's). One open at a time: the next right click pops the last one down. `openMenu()` is the last one built.

Canvas clicks (slice 28): `GTKCanvasClicks` per window, its own capture-phase `GtkEventControllerLegacy` (never claims). Button 1 press (not with Control on macOS: a right click) → `pick()` walked up to a canvas's native, enabled with its groups → the press point from the canvas's border-box top-left; motion past `gtk-dnd-drag-threshold` cancels, and on Linux a release after `gtk-long-press-time` is no click (the hold was a right click); button 1 release over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point. Touch events that emulate the pointer count the same; a broken grab cancels.

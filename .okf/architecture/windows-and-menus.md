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
* Plain item: `activate` → `MenuActivated`; About → session About; Quit → `QuitRequested(window)`.
* Toggle: stateful boolean action, `change-state` sets state + posts `MenuToggled`. `setToggle` sets state, no mail.
* Hotkeys: `<Meta>` (Command) on macOS, `<Control>` elsewhere. GTK 4 `<Primary>` = Control on every platform. Set through `setAccelsForAction`.

Placement:

* Linux: `GtkPopoverMenuBar` from the model, prepended in the window's scaffold; replaced on `setMenuBar`.
* macOS (`appWideBar()`): app menubar = active window's model (`showWindowMenuBar`), else the default bar.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar's actions removed from the application. `installDefaultMenuBar()` builds once, adds its `app` actions, turns its accelerators on, sets it as menubar. `showWindowMenuBar()` turns default accelerators off so a hotkey never names both a window action and an app action. `showDefaultMenuBar()` = install unless one of our windows is active. `defaultMenuBar()` for tests.[^driver]

[^window]: GTKWindow
[^bar]: GTKMenuBar
[^driver]: GTKBridgeDriver

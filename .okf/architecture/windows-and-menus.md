---
type: Module
title: Windows and menus
description: GtkApplicationWindow per name, close/focus mail, GMenu bars in-window (Linux) or app-wide (macOS), About.
resource: src/Windows/
tags: [gtk, windows, menus]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-24T17:25:00Z }
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

* `close-request` → while `confirmClose()` and not inside `close()`: post `WindowCloseRequested`, return `true` (the window stays). Else: clear the default widget (GTK keeps a destroyed default widget as the default; a later read crashed, 2026-10-20), remove the primitive tree, drop a pending resize, post `WindowClosed`, driver forgets, return `false` (GTK destroys).
* Window group: each top-level window makes its own `GtkWindowGroup`; a child (`open(…, parent)`) is `setTransientFor(parent)` + `setModal(true)` and joins the parent's group, so its grab blocks only the parent. GTK ignores `GDK_DELETE` for a window whose group grab is another window's (`gtkmain.c`). `close()` closes the child first; `present()` on a child presents a hidden parent first.
* Focus (`GTKFocus`): the window's `notify::focus-widget`; the holder is the primitive whose `focusWidget()` (the native, or a text area's `GtkTextView`, a table's or tree's `GtkColumnView`) or native is the focused widget or its nearest ancestor (an entry's inner `GtkText`; a combo's options button is in the combo's native). `focus()`, `present()` (GTK focuses the first focusable widget on show) and the initial focus run inside `quietly()`: no mail, and the holder after it is what the user's next move leaves. Hide and disable run inside it too. GTK moves focus off a hidden focus widget only on its next frame, so a hide makes that move itself: the window's `childFocus(TAB_FORWARD)`, or no focus when that finds nothing. Holders are weak references. A focusable canvas: the box's `focusable` property, a `GtkGestureClick` that grabs focus on press, and a capture-phase `GtkEventControllerKey` that keeps Tab (0xff09) and ISO_Left_Tab (0xfe20) while `takesTab()`.
* Window keys (`GTKWindowKeys`): a bubble-phase `GtkEventControllerKey` on the window posts `WindowKeyPressed` (key from `GTKInputEngine::keyOf`, text from `gdk_keyval_to_unicode` when printable, modifiers from `GTKInputEngine::modifiers`, repeat false) and returns true; Tab and ISO_Left_Tab return false.
* Button roles (`GTKButtonKeys`): one `GtkShortcutController` on the window, capture phase, `Return|KP_Enter` and `Escape` with `GtkCallbackAction`s → `press()`: the holder `activate()`s (clicked 250 ms later) when `isSensitive()` and `getMapped()`; false (key goes on) for a focused text area's `GtkTextView`, a focus inside a `GtkPopover`, or a holder that cannot take the key; Esc in a child with no cancel holder → `userClose()` (`GtkWindow::close()`, through close-request). Look: `setDefaultWidget(holder)`; the holder clears it when it loses the role or is removed.
* Content-area size polled by the session after each pump → `WindowResized` (latest per pump) on change, including an in-window bar arriving or going.
* `close()`: realized window → `GtkWindow::close()` (close-request runs inside). Never-realized window → GTK skips close-request, so the close path runs here and the window is destroyed.
* `notify::is-active` → active: macOS bar switch, post `WindowFocused` when the window was inactive at the end of a pump (`settleActive()`, called by `GTKSession::pump`); inactive: driver shows the default bar. On X11 a popover's grab makes the window inactive and active again in one dispatch: no mail.
* `present()` → `present()` + session `bringForward()`.

# Menu bar

`GTKMenuBar`: profile → `GMenu` model + `GSimpleActionGroup`.[^bar]

* Folders → `GMenuItem` with an `action` attribute naming the folder's own `GSimpleAction` (no handler) + submenu link; separators → section boundaries.
* Enabled state: `MenuStates`; `apply(id)` sets each action to its effective state (own state and every folder above). GTK's menu tracker reads a submenu item's sensitivity from its `action` attribute (popovers, macOS menubar); `GtkPopoverMenuBar` does not for top-level entries, so `apply()` also sets the bar widget's child at the folder's index. `widget()` builds once. A disabled `GSimpleAction` does not activate, so its accelerator does nothing.
* Scope: the default bar, and every bar on macOS, names `app.<id>`; a window's bar on Linux names `win.<id>`. Each window's bar group is inserted on the window as `win` on both. GTK's macOS exporter reads item states (checks, radio marks) through the application's actions only, so on macOS the driver holds the shown bar's actions in the application's map (`GTKBridgeDriver::hold()`): showing a window's bar or the default bar swaps the previous bar's actions and hotkeys out.
* Edit role: `activate` → `GTKMenuBar::edit()` on the bar's window (default bar: active window) → focused widget's own action (`EDIT_ACTIONS`: `text.undo`, `clipboard.copy`, `selection.select-all` then `list.select-all`, …). No accelerator; posts nothing.
* Plain item: `activate` → `MenuActivated`; About → session About; Quit → `QuitRequested(window)`.
* Toggle: stateful boolean action, `change-state` sets state + posts `MenuToggled`. `setToggle` sets state, no mail.
* Mac text keys (macOS only): `GTKMacTextKeys` capture-phase key controller on each text input's entry and text area's text view; Cmd/Option arrows, Cmd-Backspace, Option-Backspace/Delete → `move-cursor` / `delete-from-cursor` on the GtkText or GtkTextView. Window's capture controllers (HumanInput) run first.
* Accelerators: `accelerator(Accelerator, $macos)`: command `<Meta>`, control `<Control>`, super `<Super>`, `<Alt>`, `<Shift>`, key by GDK name (`GDK_NAMES`; a punctuation character is not a GDK name: GTK refuses `<Control>,`). GTK 4 `<Primary>` = Control on every platform. Set through `setAccelsForAction`. App accelerators run in the window's capture phase, before the focused widget and `GTKMacTextKeys`.

Placement:

* Linux: `GtkPopoverMenuBar` from the model, prepended in the window's scaffold; replaced on `setMenuBar`.
* macOS (`appWideBar()`): app menubar = active window's model (`showWindowMenuBar`), else the default bar.

# Default bar (macOS)

`setDefaultMenuBar(profile)`: same instance → no-op; else old bar's actions removed from the application. `installDefaultMenuBar(blocked)` builds once, adds its `app` actions, turns its accelerators on, sets it as menubar; blocked (a child is active), `GTKMenuBar::block(true)` disables every item's action but the edit roles, `MenuStates` unchanged; every other install unblocks it. `showWindowMenuBar()` turns default accelerators off so a hotkey never names both a window action and an app action. `showDefaultMenuBar()` = install unless one of our windows is active. `defaultMenuBar()` for tests. `setItemEnabled()` / `isItemEnabled()` keep the default profile's `MenuStates` on every platform; a bar built later gets the states that differ from the profile.[^driver]

[^window]: GTKWindow
[^bar]: GTKMenuBar
[^driver]: GTKBridgeDriver

# Right clicks

`GTKRightClicks` per window: capture-phase `GtkGestureClick` (button 0, never claims) and a capture-phase `GtkEventControllerLegacy` for holds. Secondary = button 3, or button 1 with `GDK_CONTROL_MASK` on macOS (GDK's macOS backend reports Ctrl-click as button 1 + Control; measured 2026-10-09). On Linux a primary press held within `gtk-dnd-drag-threshold` for `gtk-long-press-time` (read from `GtkSettings` with `g_object_get_property`) is a right click: the Pi's ft5x06 touchscreen is registered as a pointer, a finger arrives as `BUTTON_PRESS` button 1 (measured), so a held press is the only right click it can make. Timed with `g_timeout_add` from raw events, because a `GtkGestureLongPress` is cancelled when a widget's own gesture (a button's) claims the press. `at(x, y)`: `pick()` with `GTK_PICK_INSENSITIVE | GTK_PICK_NON_TARGETABLE`, walk `getParent()` to the first native a registry primitive owns → `View\RightClicked` from its border-box top-left (`computePoint`, less the widget's `computeBounds` origin: GTK's own coordinates start inside border and padding), nothing if it or a group is disabled; reaching the content box first → `WindowRightClicked` in content coordinates; outside the content (Linux menu bar) → nothing.

Context menus: after `View\RightClicked`, the menu of the primitive or its nearest enabled group with one opens. `GTKContextMenu` builds a `GtkPopoverMenu` over a `GMenu` (separators → sections, folders → submenus) whose items name `ctx.<id>` in a `GSimpleActionGroup` inserted on the popover itself; parented to the content box, `setPointingTo` the click (1×1), no arrow; `closed` → unparent on the next main-loop turn (GTK closes the menu before running the chosen action). An item's `activate` posts `MenuActivated`. `at()` returns whether a menu opened; the click gesture then claims its press (`GtkEventSequenceState::CLAIMED`), which cancels a widget's own context menu (a text input's). One open at a time: the next right click pops the last one down. `openMenu()` is the last one built.

Canvas clicks: `GTKCanvasClicks` per window, its own capture-phase `GtkEventControllerLegacy` (never claims). Button 1 press (not with Control on macOS: a right click) → `pick()` walked up to a canvas's native, enabled with its groups → the press point from the canvas's border-box top-left; motion past `gtk-dnd-drag-threshold` cancels, and on Linux a release after `gtk-long-press-time` is no click (the hold was a right click); button 1 release over the same canvas → `View\CanvasClicked` (`view.clicked`) at the press point. Touch events that emulate the pointer count the same; a broken grab cancels. The routing is shared: `GTKCanvasClicks::canvasAt(window, native, x, y)` and `canvasPoint(native, canvas, x, y)` are public static, and the pointer below uses both.

# Canvas pointer

Canvas pointer and wheel (C4): `GTKCanvasPointer` per window, built after `GTKRightClicks` — GTK runs a widget's newest controller first (`gtkwidget.c` prepends), so it sees a press before a context menu opens for it. Its own capture-phase `GtkEventControllerLegacy` (never claims, always returns false), whose `event` handler is a `static` closure through a `WeakReference` so the window native never holds the pointer. Not gestures: `GtkGestureSingle` resets on a second button (gtk 4.14.5 `gtkgesturesingle.c:209-216`), and a reset `GtkGestureDrag` emits `drag-end` while the first button is still down and then tracks nothing.

* Events → `pressed(x, y, button, state, ?at)`, `moved(x, y, state)`, `released(x, y, button, state)`, `interrupt()` (window points, `$at` microtime): `BUTTON_PRESS` / `TOUCH_BEGIN`, `MOTION_NOTIFY` / `TOUCH_UPDATE`, `BUTTON_RELEASE` / `TOUCH_END`, `GRAB_BROKEN` / `TOUCH_CANCEL`. Surface coordinates less `getSurfaceTransform`, as the clicks read them. A touch counts only with `getEmulatingPointer()`, as button 1. Buttons and modifiers through `GTKInputEngine::buttonOf()` / `modifiers()`; a button GDK numbers otherwise posts nothing.
* The first button down on a watching, enabled canvas owns the press: another button's press or release posts nothing until it ends. `CanvasDragged` only when the canvas point changed, and not for a first move under a point from the press (X11 reports a press in fractions of a pixel and the motion GTK follows it with in whole pixels, so every click on the gaming PC posted a drag), and the owning canvas keeps the drag and release wherever the pointer goes (points then outside its bounds). Exactly one `CanvasReleased` per `CanvasPressed` — the owning button's release, or `interrupt()` at the last point posted, which `GTKRightClicks` calls right after `popupAt` so a menu opening ends the press.
* Every event of an owned press re-checks the canvas: removed, no longer watching, or disabled itself or inside a group drops the press silently, posting nothing then or for the rest of it (no mail comes from code).
* `clicks` counts presses of the same button on the same canvas within `gtk-double-click-time` and `gtk-double-click-distance` (read once from `GtkSettings`, 400 ms / 5 px with none), so a triple click is 3.

# Dialogs

Dialogs (C7): `GTKDialogs extends ToolkitDialogs` (Surface holds the request ids and the one-modal-per-parent rule). Each request holds its native and a `GCancellable`.

* Alert: `g_object_new_with_properties('GtkAlertDialog', ['message'], [$message])` (ext-gtk binds no `gtk_alert_dialog_new`: it is a printf), then `setDetail`, `setButtons`, `setDefaultButton`, `setCancelButton`, `setModal(true)`, `choose()`. `chooseFinish` answers the index; a `GError` answers the cancel index.
* Files: `GtkFileDialog` `open` / `openMultiple` / `selectFolder` / `save` with `setTitle`, `setInitialFolder` (a folder only), `setInitialName`, `setModal(true)`. A finish answers each file's `getPath()` (a file with no local path is left out); a `GError` (dismissed, cancelled, failed) answers `[]`.
* `closeOn(parent)` (from `GTKWindow::close()`, after the child) forgets the id, cancels its `GCancellable`, then posts `-1` / `[]`; the late callback finds no id and posts nothing. `close-request` keeps the window while `openOn()` has an id.
* Callbacks reach `GTKDialogs` through `WeakReference` from `static` closures.
* GTK 4 cannot make a key event and keeps the alert's window private: the answers by key and by button are checked by eye.
* Button labels go through `GTKDialogs::buttonLabel()`: GtkAlertDialog's buttons read `_` as a mnemonic, so each `_` is doubled. `clipboard()` and `desktop()` connect the session first: GTK starts with it, and `GdkDisplay::getDefault()` is null before.

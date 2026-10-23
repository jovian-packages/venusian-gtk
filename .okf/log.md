# Log

## 2026-10-23

* Review fixes (C9): hiding or removing the focused primitive or a container holding it posts no focus mail (`GTKPrimitive::movingFocusOff`, `GTKFocus::within`: GTK's next-frame focus move made at once inside `quietly()`); a canvas that stops being focusable lets go; `setInitialFocus()` refuses another window's primitive. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-22

* By-eye fixes (C9): a focusable canvas is a Tab stop (its shown child, the picture or a lent GL area, is focusable too: a GtkBox moves Tab focus only among its children); a modifier pressed alone posts no WindowKeyPressed, as AppKit sends none; a text input posts TextChanged only when its text differs (GTK on macOS emits changed twice per typed character).
* Hide and disable of the focused widget post no focus mail: they run quietly, and a hide makes GTK's next-frame focus move at once (`childFocus(TAB_FORWARD)` on the window, else no focus). [windows and menus](architecture/windows-and-menus.md)
* Focus and window keys (compare-app toolkit C9): `focus()` / `hasFocus()` on every primitive through `focusWidget()`, `focused()` / `setInitialFocus()` on the window, focus mail from `notify::focus-widget` (`GTKFocus`), focusable canvases (click and Tab controllers), `WindowKeyPressed` from a bubble-phase key controller (`GTKWindowKeys`). Found: GTK focuses the first focusable widget when a window shows, so `present()` moves focus quietly. [windows and menus](architecture/windows-and-menus.md)
* Dialogs, clipboard and desktop (compare-app toolkit C7): `GTKDialogs` (`GtkAlertDialog` made with `g_object_new_with_properties`, so the message is never a printf format; `GtkFileDialog` open, open-multiple, select-folder, save; a `GCancellable` per request, cancelled by the parent's close), `GTKClipboard` (`GdkClipboard::setText`), `GTKDesktop` (`GtkFileLauncher`). A window with an open dialog keeps the window manager's close. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-20

* Modal children, close veto and button roles (compare-app toolkit C6): window groups per top-level window, transient modal children, `confirmClose()` through close-request, `GTKButtonKeys` (capture-phase shortcut controller), the default bar blocked while a child is active (macOS). Found: GTK keeps a destroyed default widget as the window's default, and reading it crashed; the driver clears it first. [windows and menus](architecture/windows-and-menus.md)
* Menu enabled state and accelerators (compare-app toolkit C5): folder items name their own actions, `setItemEnabled()` on bars, windows and the driver's default bar, top-level bar entries set by hand, `accelerator()` from `Accelerator` with GDK key names (punctuation hotkeys installed nothing before). [windows and menus](architecture/windows-and-menus.md)
* A first move under a point from the press is the press itself, not a `CanvasDragged`: on X11 (gaming PC) every click posted a drag, the press in fractions of a pixel and the motion after it in whole pixels. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-19

* Canvas pointer and wheel (compare-app toolkit C4): `GTKCanvasPointer` per window, a capture-phase `GtkEventControllerLegacy` built after `GTKRightClicks` (GTK runs the newest controller first), posting `CanvasPressed`, `CanvasDragged` and `CanvasReleased` with one press owned at a time and clicks counted from `gtk-double-click-time` / `gtk-double-click-distance`; `GTKRightClicks` interrupts an owned press right after `popupAt`, so a menu opening releases it at the press point. The wheel is each canvas's own `GtkEventControllerScroll(BOTH_AXES)`: `GTKCanvas::wheeled()` posts `CanvasWheeled` and handles the event, keeping it from an enclosing scroll view. `GTKCanvasClicks::canvasAt()` and `canvasPoint()` are public static, shared with the pointer. [windows and menus](architecture/windows-and-menus.md), [primitives](architecture/primitives.md)

* Removed primitives are freed. The Mac text keys controller on the text input and text area held a callable capturing the primitive, a cycle through the widget that PHP's collector cannot see; it now reaches the primitive through a WeakReference. `FreedTest` builds, removes and collects every kind (video where GTK has a media backend).

## 2026-10-18

* GTKTree: a GtkColumnView over a GtkTreeListModel of node-id string lists, a GtkTreeExpander in the first column, and cell colours from one CSS provider per tree. NodeSelected, NodeExpanded and NodeActivated post only for user actions; the rows GTK tears down under a collapsed node post nothing. Requires ext-gtk ^0.10.3 and Surface contracts and windows ^0.10.3.

* GTKSplitView: one GtkPaned slot per pane, placed once (no rebuild on add or remove, so focus, realized views and dividers stay), slot and tail visibility synced so trailing hidden panes leave no handle, dividers measured from each slot's paned position, positions set while unmapped land on map, SplitMoved only for a press inside a handle's rectangle (mouse or touch) with no code move meanwhile. GTKTabView: no TabSelected when GTK switches away from a hidden page.
* GTKSplitView (GtkBox over a chain of GtkPaneds, equal first layout, positions as fractions placed against each paned's laid-out start until within a pixel, SplitMoved at the release around a handle drag from a capture-phase legacy controller) and GTKTabView (GtkNotebook, TabSelected for user switches); venusian-surface contracts and windows ^0.10.2, which carry TKSplitView and TKTabView (compare-app split and tabs, C2). 0.10.5.

## 2026-10-14

* GTKTextArea: read-only, wrap, monospace (CSS), tag line backgrounds and range colours, line selection, adjustment scroll position and ViewScrolled (compare-app text panes, C1). Hooks that change something GTK lays out hold ViewScrolled, and any scroll asked for meanwhile, until the adjustments settle or 500 ms pass; a user scroll inside the settle posts once at its end. Requires ext-gtk 0.10.2.

## 2026-10-10

* GTKLink: TKLink over GtkLinkButton (toolkit links).
* Edit menu: edit roles run GTK's own actions on the focused widget, no accelerator; `GTKMacTextKeys` gives text inputs and areas the Mac navigation and deletion keys on macOS (HumanInput slice 29).

## 2026-10-09

* Canvas clicks: `GTKCanvasClicks` posts `view.clicked` with the point for a press and release on a canvas (HumanInput slice 28). [windows and menus](architecture/windows-and-menus.md)
* Context menus: `GTKContextMenu`, a `GtkPopoverMenu` opened by `GTKRightClicks` for the primitive or nearest group with a menu; items post `MenuActivated` (HumanInput slice 27). Requires the ext-gtk popover bindings. [windows and menus](architecture/windows-and-menus.md)
* The session sets GLib's program name to `app.id` before `GtkApplication` starts, so X11 `WM_CLASS` is the app id and docks match the window to its `.desktop` file. Requires ext-gtk 0.10.1. [session](architecture/session.md)
* Right-click mail: `GTKRightClicks` posts `view.right-clicked` / `window.right-clicked` for the right button, Ctrl-click on macOS and a touchscreen long press (HumanInput slice 26). [windows and menus](architecture/windows-and-menus.md)
* HumanInput's `gtk` input engine: `GTKInputEngine`, `SeenEvent`, `SeenKind`; the provider registers it when HumanInput is bound. Requires `venusian-surface/human-input`. [input](architecture/input.md)
* gtk engine on macOS: VoidSymbol presses dropped; modifiers from key events with the event's own key; natural scrolling undone on GTK 4.20+. [input](architecture/input.md)
* gtk engine: each window's input method makes the display's first use of GTK's Wayland text-input and waits its round trip (`GdkDisplay::sync`) before any focus, so a context let go before the main loop runs no longer leaves GTK 4.18 focused on a freed context. [input](architecture/input.md)
* gtk engine: buttons from raw events (legacy controller), text from a per-window input method, points in the content area. [input](architecture/input.md)
* The desktop identity is `config/app.php` `app.id`; `bridge.gtk.application_id` and the `org.venusian.Surface` default are gone, and connect throws without `app.id`. A packaged build names its `.desktop` file after the same id. [Config](api/config.md).

## 2026-10-08

* `GTKCanvas::applyPixels()` takes the damage list Surface now passes; the whole frame is still shown.

## 2026-10-06

* Dmabuf review fixes: the texture's destroy notify hands the export back, XBGR8888 import (opaque), the widget's own display, a format probe before offering the surface. [primitives](architecture/primitives.md)
* Canvas lends a dmabuf surface on Linux: the `vulkan` engine exports each frame, the canvas shows it as a `GdkDmabufTexture`. [primitives](architecture/primitives.md)
* Canvas lends a GL context: the native view is a `GtkBox` holding the picture or, while lent, a `GtkGLArea` whose render callback copies the `opengl` engine's frame. [primitives](architecture/primitives.md)

## 2026-10-04

* `GTKCanvas` pipes an ext-fb framebuffer: the texture is copied by address, and on GTK 4.16 or newer the next one is built with `GdkMemoryTextureBuilder` and an update region. [primitives](architecture/primitives.md)

## 2026-10-03

* `GTKCanvas`: the framebuffer a `TKCanvas` hands out, shown as a memory texture on a `GtkPicture`. [primitives](architecture/primitives.md)

## 2026-10-02

* [Primitives](architecture/primitives.md): scoped style targets, escaped font family, datepicker follows month/year navigation and refuses years outside 1-9999, table splices from the first changed row, watched views keyed by uuid; window resize polled ([windows and menus](architecture/windows-and-menus.md), [session](architecture/session.md)).
* New [primitives](architecture/primitives.md): factory, containers, all leaves, styling, sizes, watched allocations, mail rules. [Windows and menus](architecture/windows-and-menus.md): content area, primitive tree on close, WindowResized. [Session](architecture/session.md): watched sizes after each pump. [Testing](runbooks/testing.md): helpers, environment facts, fixtures.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [config](api/config.md), [testing](runbooks/testing.md).

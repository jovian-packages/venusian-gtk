# Log

## 2026-10-27

* Services: `GTKComposedAlert` for an alert with a style, a suppression box or a title (GtkWindow, transient and modal; icon, labels, check button, buttons; Return, Esc and close); `supports()` gives `AlertTitle`, not `ShowHidden`; file dialog filters as `GtkFileFilter`s in a `GListStore`; `GTKClipboard` reads text async, `hasText()` from the formats, `ClipboardChanged` from the `changed` signal. Needs ext-gtk's GtkImage, GtkFileFilter, GListStore, file dialog filters, clipboard text reads and GdkContentFormats. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-26

* Canvas keys: `GTKCanvasKeys` replaces the canvas's Tab controller: a capture-phase key controller posts `KeyPressed`, `EditCommanded` (a platform-split table: Ctrl chords on Linux, Cmd and Option on macOS) and `KeyReleased`, and feeds a `GtkIMMulticontext` whose `commit` posts `TextCommitted`; a focused canvas takes every key. `GTKCanvas::tabKeys()` became `keys()`. [windows and menus](architecture/windows-and-menus.md)
* A focusable canvas is one Tab stop: only its shown child is focusable (`focusWidget()`), not the box. Canvas releases post only after their press; a lone modifier's press carries its modifier and its release does not. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-24

* By-eye fix (C13): on macOS a window's bar showed no toggle or radio marks: its items named `win` actions, and GTK's macOS exporter reads states through the application's actions only. Bars on macOS now name `app` actions, held in the application while shown ([windows and menus](architecture/windows-and-menus.md), Scope).
* By-eye fix (C13): a relabel in the macOS app bar replaced the wrong item (Unimportant gave way to Show Line Numbers, Hide stayed): GTK's exporter applies a model change by index into the native menu, and macOS puts Show Tab Bar, Show All Tabs and a separator atop a View menu. A relabel of the shown bar now sets the menubar again, so GTK builds the native menu anew.

## 2026-10-23

* By-eye fixes (C11): a combo's options list sizes itself again when its popover shows (`GTKCompletionList::fit()`): a list view not in a window measures its rows short, and the list showed two of three options. Focus on a combo's options button is the combo's: `GTKFocus` maps each primitive's native as well as its focus widget; it went to the combo's group. `WindowFocused` posts only after the window was inactive at a pump's end: every popover open and close posted it on X11.
* Menus: radio items in the bar (a string action per item, its state the group's chosen id, the item's target its own id; `change-state` sets the group from `MenuStates` and posts `MenuRadioChosen` for another item), relabel (GMenu items are immutable: the node's item is replaced at its recorded section and index, `place()`, with the same action, target and submenu; a top-level folder's sensitivity applied again), `setRadio()` / `radio()` / `setItemLabel()` / `itemLabel()` on the window and the driver's default bar; `GTKMenuBar` takes a `MenuStates` to share, so the default bar keeps the driver's states with no replay. `GTKContextMenu` takes boolean and string stateful actions in `ctx` and an owner told the user's choices, so the next open shows them; `GTKPrimitive::applyPopupMenu()` ends an owned canvas press and opens the menu over the window's content at the point plus the bounds origin, returning at once (`openPopup()` while it shows); removal pops it down. `GTKTabView` inserts with `GtkNotebook::insertPage`. [primitives](architecture/primitives.md)
* Canvas scrolling: `GTKScrollBar` (a `GtkScrollbar` over a `GtkAdjustment` written whole with `configure()`; the user's `value-changed` posts `ScrollChanged` latest-wins; a capture-phase scroll controller moves `step` per wheel line, a precise wheel a line per 10 points, since GtkRange scrolls by its own amount), hover in `GTKCanvasPointer` (an unpressed motion posts `CanvasMoved` latest-wins and `CanvasEntered` / `CanvasLeft` as the watching canvas under the pointer changes; the window's leave posts `CanvasLeft`; nothing during an owned press; a release away posts `CanvasLeft` after `CanvasReleased`; the canvas's pending move flushed before its exit), wheel phases (the canvas's scroll controller is kinetic: `scroll-begin` → BEGAN then CHANGED, `scroll-end` → ENDED with no movement, no begin → NONE), `GTKCanvasCoast` (`decelerate` px/ms decaying exp(−t / 325 ms), ticked at each session pump into MOMENTUM, then one MOMENTUM_ENDED; a gesture or a press ends it, removal ends it silently), `WindowScaleChanged` from `notify::scale-factor` and the surface's `notify::scale`; `nativeScale()` reads `GdkSurface::getScale()`. [primitives](architecture/primitives.md)
* Path bar and toolbar: `GTKComboBox` (a linked box of a `GtkEntry` and a `GtkMenuButton` whose popover lists the options; completions in `GTKCompletionList`, a non-autohiding popover under the entry, at most eight rows tall; Up, Down, Return and Escape through a capture-phase key controller on the entry; focus leaving the entry hides them), button images (`GTKButtonImage`: a Framebuffer's RGBA8 as a `GdkMemoryTexture` in a `GtkPicture`, in a box with the label) and `has-frame` on push and toggle buttons, `GTKMenuButton` (a `GtkMenuButton` over the GMenu and "ctx" actions `GTKContextMenu` builds). Found: a picture's natural size is its texture's pixel size and a size request only raises a minimum, so the picture sits in a `GtkScrolledWindow` with external scrolling sized in points. `GTKContextMenu` exposes `model()` and `actions()`; its popover is made on first use. [primitives](architecture/primitives.md)
* Review fixes (C9): hiding or removing the focused primitive or a container holding it posts no focus mail (`GTKPrimitive::movingFocusOff`, `GTKFocus::within`: GTK's next-frame focus move made at once inside `quietly()`); a canvas that stops being focusable lets go; `setInitialFocus()` refuses another window's primitive. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-22

* By-eye fixes (C9): a focusable canvas is a Tab stop (its shown child, the picture or a lent GL area, is focusable too: a GtkBox moves Tab focus only among its children); a modifier pressed alone posts no WindowKeyPressed, as AppKit sends none; a text input posts TextChanged only when its text differs (GTK on macOS emits changed twice per typed character).
* Hide and disable of the focused widget post no focus mail: they run quietly, and a hide makes GTK's next-frame focus move at once (`childFocus(TAB_FORWARD)` on the window, else no focus). [windows and menus](architecture/windows-and-menus.md)
* Focus and window keys: `focus()` / `hasFocus()` on every primitive through `focusWidget()`, `focused()` / `setInitialFocus()` on the window, focus mail from `notify::focus-widget` (`GTKFocus`), focusable canvases (click and Tab controllers), `WindowKeyPressed` from a bubble-phase key controller (`GTKWindowKeys`). Found: GTK focuses the first focusable widget when a window shows, so `present()` moves focus quietly. [windows and menus](architecture/windows-and-menus.md)
* Dialogs, clipboard and desktop: `GTKDialogs` (`GtkAlertDialog` made with `g_object_new_with_properties`, so the message is never a printf format; `GtkFileDialog` open, open-multiple, select-folder, save; a `GCancellable` per request, cancelled by the parent's close), `GTKClipboard` (`GdkClipboard::setText`), `GTKDesktop` (`GtkFileLauncher`). A window with an open dialog keeps the window manager's close. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-20

* Modal children, close veto and button roles: window groups per top-level window, transient modal children, `confirmClose()` through close-request, `GTKButtonKeys` (capture-phase shortcut controller), the default bar blocked while a child is active (macOS). Found: GTK keeps a destroyed default widget as the window's default, and reading it crashed; the driver clears it first. [windows and menus](architecture/windows-and-menus.md)
* Menu enabled state and accelerators: folder items name their own actions, `setItemEnabled()` on bars, windows and the driver's default bar, top-level bar entries set by hand, `accelerator()` from `Accelerator` with GDK key names (punctuation hotkeys installed nothing before). [windows and menus](architecture/windows-and-menus.md)
* A first move under a point from the press is the press itself, not a `CanvasDragged`: on X11 (gaming PC) every click posted a drag, the press in fractions of a pixel and the motion after it in whole pixels. [windows and menus](architecture/windows-and-menus.md)

## 2026-10-19

* Canvas pointer and wheel: `GTKCanvasPointer` per window, a capture-phase `GtkEventControllerLegacy` built after `GTKRightClicks` (GTK runs the newest controller first), posting `CanvasPressed`, `CanvasDragged` and `CanvasReleased` with one press owned at a time and clicks counted from `gtk-double-click-time` / `gtk-double-click-distance`; `GTKRightClicks` interrupts an owned press right after `popupAt`, so a menu opening releases it at the press point. The wheel is each canvas's own `GtkEventControllerScroll(BOTH_AXES)`: `GTKCanvas::wheeled()` posts `CanvasWheeled` and handles the event, keeping it from an enclosing scroll view. `GTKCanvasClicks::canvasAt()` and `canvasPoint()` are public static, shared with the pointer. [windows and menus](architecture/windows-and-menus.md), [primitives](architecture/primitives.md)

* Removed primitives are freed. The Mac text keys controller on the text input and text area held a callable capturing the primitive, a cycle through the widget that PHP's collector cannot see; it now reaches the primitive through a WeakReference. `FreedTest` builds, removes and collects every kind (video where GTK has a media backend).

## 2026-10-18

* GTKTree: a GtkColumnView over a GtkTreeListModel of node-id string lists, a GtkTreeExpander in the first column, and cell colours from one CSS provider per tree. NodeSelected, NodeExpanded and NodeActivated post only for user actions; the rows GTK tears down under a collapsed node post nothing. Requires ext-gtk ^0.10.3 and Surface contracts and windows ^0.10.3.

* GTKSplitView: one GtkPaned slot per pane, placed once (no rebuild on add or remove, so focus, realized views and dividers stay), slot and tail visibility synced so trailing hidden panes leave no handle, dividers measured from each slot's paned position, positions set while unmapped land on map, SplitMoved only for a press inside a handle's rectangle (mouse or touch) with no code move meanwhile. GTKTabView: no TabSelected when GTK switches away from a hidden page.
* GTKSplitView (GtkBox over a chain of GtkPaneds, equal first layout, positions as fractions placed against each paned's laid-out start until within a pixel, SplitMoved at the release around a handle drag from a capture-phase legacy controller) and GTKTabView (GtkNotebook, TabSelected for user switches); venusian-surface contracts and windows ^0.10.2, which carry TKSplitView and TKTabView. 0.10.5.

## 2026-10-14

* GTKTextArea: read-only, wrap, monospace (CSS), tag line backgrounds and range colours, line selection, adjustment scroll position and ViewScrolled. Hooks that change something GTK lays out hold ViewScrolled, and any scroll asked for meanwhile, until the adjustments settle or 500 ms pass; a user scroll inside the settle posts once at its end. Requires ext-gtk 0.10.2.

## 2026-10-10

* GTKLink: TKLink over GtkLinkButton (toolkit links).
* Edit menu: edit roles run GTK's own actions on the focused widget, no accelerator; `GTKMacTextKeys` gives text inputs and areas the Mac navigation and deletion keys on macOS.

## 2026-10-09

* Canvas clicks: `GTKCanvasClicks` posts `view.clicked` with the point for a press and release on a canvas. [windows and menus](architecture/windows-and-menus.md)
* Context menus: `GTKContextMenu`, a `GtkPopoverMenu` opened by `GTKRightClicks` for the primitive or nearest group with a menu; items post `MenuActivated`. Requires the ext-gtk popover bindings. [windows and menus](architecture/windows-and-menus.md)
* The session sets GLib's program name to `app.id` before `GtkApplication` starts, so X11 `WM_CLASS` is the app id and docks match the window to its `.desktop` file. Requires ext-gtk 0.10.1. [session](architecture/session.md)
* Right-click mail: `GTKRightClicks` posts `view.right-clicked` / `window.right-clicked` for the right button, Ctrl-click on macOS and a touchscreen long press. [windows and menus](architecture/windows-and-menus.md)
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

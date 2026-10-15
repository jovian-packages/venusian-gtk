# Log

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

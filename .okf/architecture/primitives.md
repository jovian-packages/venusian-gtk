---
type: Module
title: Primitives
description: GTK concretes of Surface's TK primitives - factory, containers, leaves, CSS-per-primitive styling, border-box sizes, watched allocations, mail rules.
resource: src/Primitives/
tags: [gtk, primitives, layout, mail]
status: draft
generated: { by: grok/4.7, at: 2026-10-05T01:30:00Z }
sources:
  - id: trait
    resource: src/Primitives/Concerns/GTKPrimitive.php
    title: GTKPrimitive trait
  - id: factory
    resource: src/Primitives/GTKPrimitiveFactory.php
    title: GTKPrimitiveFactory
  - id: window
    resource: src/Windows/GTKWindow.php
    title: GTKWindow
  - id: session
    resource: src/Bridge/GTKSession.php
    title: GTKSession
---

# Overview

`GTK<Kind> extends Surface\Windows\Primitives\TK<Kind>`, `use GTKPrimitive`, `implements GTKView` (containers `GTKContainer`). Concrete calls `parent::__construct` (name + kind checks), builds its widget, `adoptNative()` tags it `tk-<uuid>`. `GTKPrimitiveFactory` (one per window) mints every kind with the host's pending placement; all kinds exist on GTK, video throws without a media backend.[^factory]

Native classes are referenced fully qualified (`\GtkLabel`): PHP class names are case-insensitive, `GtkLabel` = `GTKLabel`. Widget accessors are `widget<Kind>()` (container creation methods own `grid()`, `fixed()`, …).

# Window host

`GTKWindow use HostsPrimitives`. Content container appended to the content area (`contentArea()`, below the Linux bar), hexpand + vexpand. `size()` = content area allocation. The session polls each open window's content area after every pump: a change → `postLatest("window.resized.<name>")`, covering the window's own resize and an in-window menu bar arriving or going; a size with a zero side (not yet allocated) is recorded, never reported. `closed()`: `removeContent()` → `unwatchWindow` (drops a pending resize) → `WindowClosed`.[^window]

# Shared hooks

| Hook | GTK |
|---|---|
| visible / enabled | `setVisible` / `setSensitive` (cascades to descendants) |
| fill / align | `setHexpand`/`setVexpand` / `setHalign`/`setValign` (START, CENTER, END, FILL 1:1) |
| minSize | size request = max(min size, fixed frame) per axis |
| size | `computeBounds(self)` = border box (incl. CSS padding, border); `getWidth` is the content box |
| background, font, text colour, padding | one `GtkCssProvider` per primitive on the display, rebuilt whole: `.tk-<uuid> { background-color; background-image: none; color; font-size px; font-weight; font-family; padding }`, plus background and colour on the kind's `styleTargets()`, child-combinator nodes the theme paints itself: entry `> text`, text area `> textview`, `> textview > text`, table `> columnview`, `> columnview > listview`. Child combinators keep a container's paint off the inputs nested in it. Font family is a CSS string with quotes, backslashes and control characters hex-escaped. `stylesheet()` = loaded CSS |
| watchSize | session `watch`/`unwatch` |
| destroy | disconnect every handler (`connect()` records them), `releaseNative()`, parent's `removeNative` (or window `unmountContent`), drop provider |

Mail rules: interaction mail only through `post()` = not echoing a code write (`quietly()` sets `$applying`) and widget `isSensitive()` (self + ancestors). `gtk_widget_activate` emits on insensitive widgets; GTK blocks only real input. Video reports bypass both: the engine's state, whoever caused it.[^trait]

# Containers

| Kind | Widget | Notes |
|---|---|---|
| column / row | `GtkBox` V / H | spacing; padding = CSS padding (counted in `size()`); order via `reorderChildAfter(child, predecessor)` |
| grid | `GtkGrid` | `attach(child, column, row, columnSpan, rowSpan)`; spacing both axes |
| fixed | `GtkFixed` | `put`/`move` at frame x,y; frame size = size request. GTK never allocates below a widget's minimum: content larger than its frame gets its minimum |
| scroll view | `GtkScrolledWindow` | child wrapped in `GtkViewport` by GTK; scrollbar AUTOMATIC per scrolling axis, else NEVER |

# Leaves

| Kind | Widget | Mail |
|---|---|---|
| label | `GtkLabel`; alignment = xalign 0/0.5/1 + justify | - |
| button | `GtkButton` | `clicked` → ButtonClicked |
| image | `GtkPicture`; FIT=CONTAIN, FILL=COVER, CENTER=SCALE_DOWN, STRETCH=FILL | refuses missing, unreadable, or nothing-to-show files before any change (4.18 loads nothing from non-images; 4.24 shows unknown files as `GtkSvg`; a corrupt known format loads nothing on both) |
| canvas | `GtkPicture`, content fit FILL, can_shrink on so the layout sizes it. `present()` → `GdkMemoryTexture` in `R8G8B8X8` (alpha ignored) → `set_paintable`. An ext-fb framebuffer is piped: the texture is copied by address, and on GTK 4.16 or newer the texture after the first is built with `GdkMemoryTextureBuilder` and an update region, so only the damage is read. Scale = `gtk_widget_get_scale_factor`. Needs GTK 4.14; older throws at mint. |
| separator | `GtkSeparator` H / V | - |
| spinner | `GtkSpinner` | - |
| progress bar | `GtkProgressBar`; null fraction pulses from a 100 ms GLib timeout, removed on a fraction or removal | - |
| text input | `GtkEntry`; secret = visibility off | GtkEditable `changed` (once per user-visible edit) → TextChanged; `activate` (Enter) → TextSubmitted |
| text area | `GtkTextView` in `GtkScrolledWindow` (the widget) | one TextChanged per outermost buffer user action (a replace deletes then inserts); outside an action per change |
| checkbox / toggle button | `GtkCheckButton` / `GtkToggleButton` | `toggled` → Toggled |
| toggle | `GtkSwitch` | `notify::active` → Toggled |
| slider | `GtkScale` H, value hidden; step = range/100, page = range/10, rescaled per range | `value-changed` → ValueChanged |
| dropdown | `GtkDropDown` on `GtkStringList`; none = `GTK_INVALID_LIST_POSITION` ↔ -1 | `notify::selected` → SelectionChanged |
| datepicker | `GtkCalendar`; days only (years 1-9999, refused otherwise before any change), reported as local midnight; always shows a day, `date()` null until set or picked | `day-selected` and `notify::day/month/year` (the month/year arrows and scroll move the day with no `day-selected`) schedule one idle check: DateChanged once with the settled day, only when it differs from `date()` |
| table | `GtkColumnView` in `GtkScrolledWindow`, `GtkSingleSelection` (autoselect off, can unselect) over a `GtkStringList` of row indexes; per column a `GtkSignalListItemFactory` (setup label, bind cell text); rows kept up to the first changed one, the rest replaced in one `splice` (an append = one item) | `notify::selected` → RowSelected(row, cells) |
| video | `GtkVideo` + one `GtkMediaFile` per file | `notify::playing` → VideoPlaying/VideoPaused, `notify::ended` → VideoEnded, `notify::error` → VideoFailed; GTK loops a looping stream itself; removal pauses |

# Watched sizes

GTK has no per-widget resize signal. `GTKSession::watch(primitive)` records its size; after every pump's dispatch `checkWatched()` compares and `postLatest("view.resized.<uuid>", ViewResized)` on change (keyed by uuid: `<window>.<path>` collides across windows whose names hold dots). `unwatch` drops the entry and its pending key.[^session]

[^factory]: GTKPrimitiveFactory
[^window]: GTKWindow
[^trait]: GTKPrimitive trait
[^session]: GTKSession

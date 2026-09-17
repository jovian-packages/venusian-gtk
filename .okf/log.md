# jovian/venusian-gtk Update Log

## 2026-09-17 (modifier keys)

* **Update**: [GTKInputEngine](/input-engine.md) — the `modifiers` signal is pre-key on hardware; seen modifier keys decide their kind.

## 2026-09-17
* **Update**: [GTKInputEngine](/input-engine.md) — failures recorded for
  `errors()` (and `log` when bound), never `trigger_error`; failing window
  attach retried on the rescan cadence, recorded once per streak;
  unreadable-nodes notice once per connect; `meta` = SUPER only; focus loss
  via `notify::is-active` releases keys and mouse buttons; scroll unit read
  in the handler, SURFACE ÷ 10; natural scroll not detectable on GTK 4.8;
  coordinates window-widget based; pad lists hold connected pads only;
  `NativeGtkWindowSpace` answers `[]` without `native-window`.
* **Fix**: [GTKInputEngine](/input-engine.md) — focus-loss release keeps
  release edges (per-key `update(false)`, modifiers cleared), not
  `Keyboard::reset()`; a failing factory attach rolls back.
* **Creation**: [GTKInputEngine](/input-engine.md) — `input.gtk`: key /
  motion / click / scroll controllers per window, buffered callbacks,
  `EvdevKeyMap` (keycode − 8), evdev gamepads on a rescan cadence.
* **Update**: [index](/index.md) — concept listed; requires and aliases.
* **Update**: [GTKInputEngine](/input-engine.md) — modifiers from the key
  controller's `modifiers` signal, not key-event state; a removed pointer
  window clears the window and releases buttons; attach / scan / open
  failures warn and skip.

## 2026-09-14 (Stage, slice 4 — Surface side wording fix)
* **Fix**: [index](/index.md) and [GTKGLView](/gpu-view.md) — corrected
  the `mintGPU()` wording now that Surface's `SurfaceKind` has four cases
  (`LAYER`, `GL_CONTEXT`, `VULKAN_SURFACE`, `HOST_WINDOW`), not two.
  `hostsSurfaceKind()` only ever accepted `GL_CONTEXT`; the old phrasing
  ("refuses `LAYER` engines by kind") undersold the refusal. Now: `mintGPU()`
  hosts only `GL_CONTEXT` and refuses every other `SurfaceKind` by kind.
  No code change.

## 2026-09-13
* **Creation**: [GTKGLView](/gpu-view.md) — slice 2 replaces the blanket `mintGPU()` refusal with a `GtkGLArea` host for `SurfaceKind::GL_CONTEXT`.

## 2026-09-13 (GPU mint refuses)
* **Update**: `GTKWindowDelegate::mintGPU()` throws
  `GPUViewException::unsupported($engine, 'gtk')` for every engine.
  Slice 1 ships no OpenGL / `GtkGLArea` engine; the honest refusal is the
  Surface-level exception so a sketch catches one type without naming
  GTK. Slice 2 replaces the `opengl` branch with a `GtkGLArea` twin.

## 2026-09-12 (datePicker + table twins)
* **Update**: `GTKDatePicker` over `GtkCalendar` (aliased
  `GtkCalendarWidget`); `day-selected` behind `applying`; month is
  0-based on the widget, 1-based on Surface. `GTKTable` over
  `GtkColumnView` + per-column `GtkSignalListItemFactory` (`setup` →
  `GtkLabel`, `bind` via `getPosition`); `GtkStringList` placeholders;
  `GtkSingleSelection` autoselect off / can-unselect on;
  `notify::selected` behind `applying`. `examples/smoke-widgets.php`
  conjures both, writes through, reads native back.

## 2026-09-04 (visibility)
* **Update**: `applyVisible(bool)` lands for Surface's new
  `setVisible/show/hide`: GtkWidget setVisible through TranslatesGtkFrames — one edit covered all sixteen twins. Hiding a container hides the subtree
  natively.

## 2026-09-04 (Pi smoke + text read-back)
* **Verified live** (Pi, gtk4 4.18.6, over the fnk loop): the whole
  primitive wave — all eleven twins conjure, write through, and every
  signal seam lands driven from the native side, including
  `notify::active` and `notify::selected` through `Bridge::connect`;
  group hosting/cascade/subtree removal against real GtkFixeds; scroll
  extent honored. One measurement note: `gtk_fixed_get_child_position`
  answers the INNER allocation offset for widgets whose CSS box centres
  smaller content (buttons) — the rendered truth is `computeBounds`, which
  confirms placement exact.
* **Update**: `GTKTextArea` reads its buffer back — the ext unreserved
  `gtk_text_buffer_get_text` (iters as offsets, 0..-1 = whole buffer), so
  edits fire with the real text and the null-value lane is gone from
  Surface's TextArea contract. Proven live: hook receives the typed text,
  offset slicing works.

## 2026-09-03 (primitive wave + containers)
* **Creation**: Eleven twins for Surface's new view kinds. Controls:
  `GTKTextInput` (GtkEntry / GtkPasswordEntry via GtkEditable — the
  password entry has no placeholder, ignored stated), `GTKTextArea`
  (GtkTextView + GtkTextBuffer in a GtkScrolledWindow — the ext reserves
  `gtk_text_buffer_get_text`, so edits fire with a NULL value and value()
  answers the last write), `GTKSlider` (GtkScale, value-changed),
  `GTKToggle` (GtkSwitch, `Bridge::connect('notify::active')`),
  `GTKToggleButton` / `GTKCheckbox` (toggled), `GTKProgressBar`
  (setFraction), `GTKSeparator`, `GTKDropdown` (GtkDropDown +
  GtkStringList, `notify::selected` through the generic connect — Pi
  smoke owed). Containers: `GTKGroup` (own GtkFixed, overflow HIDDEN) and
  `GTKScrollView` (GtkScrolledWindow + inner GtkFixed sized by the
  extent; policy NEVER/AUTOMATIC). New seam: `HostsGtkChildren` — mints
  put into `childFixed()` when conjured `in:` a container, and the same
  fixed rides into the twin so frame moves land on the right parent.
  EVERY control twin carries an `applying` flag: GTK fires signals for
  programmatic writes, Surface's own setters must not echo as mail.
  Case-insensitivity bites again: `GTKDropdown` vs `GtkDropDown`, aliased
  `GtkDropDownWidget`. Class-load verified; Pi smoke owed.

## 2026-08-31 (video)
* **Creation**: `GTKVideo` over `GtkVideo` (aliased `GtkVideoWidget`) with a
  `GtkMediaFile` held per path; play/pause/mute ride the inherited GtkMediaStream
  DTO surface. Needed jovian/gtk's runtime type probe — the media backend hands back
  `GtkGstMediaFile`, unknown to the generated map. Runtime dep:
  `libgtk-4-media-gstreamer` (installed on the Pi 2026-08-31). Proven live:
  playing=true with the timestamp advancing on a real mp4.

## 2026-08-31 (spinner, image, wrap)
* **Creation**: `GTKSpinner` over `GtkSpinner` (aliased `GtkSpinnerWidget` — the GTKLabel
  case-collision again) and `GTKImage` over `GtkPicture` (CONTAIN + `setCanShrink(true)`,
  or a big picture floors measure() at its full size). `GTKLabel` wrap: `setWrap(true)`;
  wrapped height from `measure(VERTICAL, $width)` with the size request lifted first.
  Proven on the Pi through a throwaway boot script (wrap 150x131). Pre-existing note:
  `tests/Views/GtkCssTest.php` still targets the torn-out 0.8 surface tree
  (`Surface\NativeWindows\Enums\FontWeight`) and errors the suite before it runs.

## 2026-08-30 (style)
* **Creation**: `Styles\CssEngine` — one `GtkCssProvider` per window on its display
  (priority 600, the application #define), rule block per styled view under class
  `v-<window>-<view>`, live reload on change, block dropped on removal. Label and
  button style hooks all funnel through it; GTK's styling model is CSS, so the
  opinion is the funnel, not the properties.

## 2026-08-30 (button)
* **Creation**: `Views\GTKButton` over `GtkButton::newWithLabel` in the scaffold's
  GtkFixed, `clicked` → `fireClick()`. Frame mechanics extracted into the
  `TranslatesGtkFrames` trait, shared with `GTKLabel`.

## 2026-08-30 (about)
* **Update**: ABOUT role registered as a `win.<id>` action → `showAbout()` → fresh modal
  `GtkAboutDialog` transient for the window (program name, version, copyright, comments,
  website). Corrects the role table: ABOUT was never unresolvable here.

## 2026-08-30 (label)
* **Fix**: the scaffold's `GtkFixed` now sets hexpand + vexpand. A `GtkBox` gives a
  child full width by default but only its natural height, and a GtkFixed's natural
  height is the extent of its children — so `contentSize()` answered `[400, ~20]`,
  `center()` went negative in y, and the label rendered under the menubar on the Pi
  while x centred fine.
* **Creation**: `Views\GTKLabel` over a `GtkLabel` in the scaffold's `GtkFixed` —
  frames pass straight through (`move` + `setSizeRequest`), natural size from
  `gtk_widget_measure` pre-layout with the size request lifted first. Alignment =
  `setJustify` + `setXalign` (justify is multi-line only). Delegate gained
  `contentSize()` (0x0 until first layout — sketch shows and ticks before conjuring)
  and `mintLabel()`. The binding import is aliased `GtkLabelWidget`: PHP class names
  are case-insensitive and `GTKLabel` would collide.

## 2026-08-30 (menus, activation)
* **Update**: activation wired. `GTKWindowDelegate::applyMenuBar` now registers a
  window-scoped `GSimpleActionGroup` inserted as `win`: every PHP hook gets a
  `GSimpleAction` named by its spec id (dots are legal in action names — only a
  detailed name's first dot splits the group prefix), `onActivate` fires the sketch
  closure with a Surface `MenuEvent`. QUIT is the one PHP-backed role — destroys the
  window, GTK's honest equivalent. ABOUT/HIDE/FULLSCREEN stay unresolvable by design.
  Structure + activation ran clean on the Pi; click-through pending human eyes.

## 2026-08-30 (menus)
* **Update**: `GTKWindowDelegate` now builds an elected menu profile into a GMenu
  model + `GtkPopoverMenuBar` prepended into the scaffold — the scaffold's intended
  slot. **Gap**: activation wiring blocked; jovian/gtk has not bound
  `GSimpleActionGroup`, and the only bound `GActionMap` implementors are the forbidden
  `GApplication` family. Items reference `win.<id>` actions that do not exist and
  render insensitive. Role table: CLOSE_WINDOW=`window.close`, MINIMIZE=
  `window.minimize`; ABOUT/HIDE/FULLSCREEN have no honest GTK equivalent and are left
  unresolvable rather than faked. Unproven on the Pi.

## 2026-08-30
* **Update**: [BridgedLinuxOSSession](/session.md) — recorded `provisionNewWindow()`,
  the fifth verb on Surface's contract, and the delegate's `GtkBox` scaffold /
  `GtkFixed` content split that keeps a later chrome slice from reparenting a live
  tree. Added the two asymmetries the window slice exposes: `setDefaultSize()` is a
  request with a not-laid-out-yet phase, and close *destroys* here where macOS only
  hides.

## 2026-08-29
* **Update**: [BridgedLinuxOSSession](/session.md) now implements Surface's `LinuxOSBridge`
  marker so `GTKWindowDriver` receives a typed session. Dropped process-state notes that
  belong to no bundle.
* **Initialization**: Seeded the bundle. The package was emptied of its 0.8 view
  drivers, which were written against an older opinionated `ext-gtk`, and is being
  rebuilt on the strict 1:1 projection.
* **Creation**: [BridgedLinuxOSSession](/session.md) — the four GTK hooks behind
  Surface's bridge lifecycle, why connect and disconnect are honest no-ops rather than
  stubs, the mandatory-and-never-implicit boot, and why `GApplication::run()` stays
  untouched.

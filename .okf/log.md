# jovian/venusian-gtk Update Log

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

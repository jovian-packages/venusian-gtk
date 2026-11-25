---
type: Module
title: UI primitives
description: The GTK column, row, fixed, label, button, check box, switch and dropdown on Topside's TK classes, and the native widget each one is.
resource: src/UI/
tags: [gtk, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-25T16:20:00Z }
sources:
  - id: native
    resource: src/UI/Concerns/GtkNative.php
    title: GtkNative
  - id: box
    resource: src/UI/Concerns/GtkBoxChildren.php
    title: GtkBoxChildren
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKOverlay`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `GtkText`. Shared native work is in `GtkNative`, what column and row share in `GtkBoxChildren`.[^native]

| Class | Native |
|---|---|
| `GtkColumn`, `GtkRow` | `GtkBox` (vertical / horizontal), built with the spacing; padding as CSS padding, each side its own, inside the background, live through `applyPadding()`; children appended, removed, reordered with `reorderChildAfter` |
| `GtkOverlay` | `GtkOverlay` (the ext-gtk class aliased `NativeOverlay`) over an empty `GtkBox` as its main child; every child an overlay (`addOverlay`), later ones drawn above, placed by GTK from halign, valign and margin, inside the CSS padding. A child that fills both ways is left out of the measure (`setMeasureOverlay(false)`, re-set on each `childLayoutChanged()`), so it takes the overlay's size without giving it one. GTK places an overlay child by align alone, so a child that fills a way is FILL that way whatever its align (`GtkNative`). Reorder: from the first child out of place, each overlay is removed and added again. GTK still gives a child at least its minimum size: a filled label wider than the overlay spills past it (cut when the overlay clips); a picture's minimum is 0 |
| `GtkFixed` | `GtkFixed`: `put()` / `move()` at the frame, `setSizeRequest` to the larger of frame and minimum |
| `GtkLabel` | `GtkLabel`; left-aligned at build (`setXalign(0)` + `setJustify`, GTK centres by default); font and colour as CSS |
| `GtkButton` | `GtkButton::newWithLabel`; its `clicked` signal calls `clicked()`; focusable by default; style, font, colour, alignment, icon and role in [Settings](#settings) |
| `GtkCheckbox` | `GtkCheckButton`; `toggled` reports the new state (GTK emits it for `setActive()` too, which topside ignores as the state it holds) |
| `GtkToggle` | `GtkSwitch`; `notify::active` reports the new state |
| `GtkDropdown` | `GtkDropDown` over a `GtkStringList` (aliased `NativeDropDown`: PHP class names ignore case); `notify::selected` reports the choice, ignored while the model is replaced |
| `GtkTextInput` | `GtkEntry`; focus is its inner GtkText; Mac text keys from `Support/GtkMacTextKeys`; activates the window's default; search, borderless, read-only, maximum length in [Settings](#settings) |
| `GtkComboBox` | linked `GtkEntry` and menu button; completions in `Support/GtkCompletionList`, a popover without autohide. GTK on macOS logs `gdk_surface_thaw_updates` criticals when a popover is shown again (reproduced in bare GTK) |
| `GtkDatepicker` | menu button showing the day, `GtkCalendar` in its popover; one idle check per burst of notifications |
| `GtkLink` | `GtkLinkButton` without a button's padding |
| `GtkSeparator` | `GtkSeparator` |
| `GtkProgressBar` | `GtkProgressBar`, pulsed by a timeout while indeterminate; FILL down it is CENTER, as GTK draws the bar at the top |
| `GtkSpinner` | `GtkSpinner` |
| `GtkSlider` | `GtkScale` without its number |
| `GtkToggleButton` | `GtkToggleButton` |
| `GtkSegmentedControl` | linked `GtkToggleButton`s in a group |
| `GtkImage` | `GtkPicture` in a box, which keeps one at START from growing to the column; set by file name (ext-gtk returns an SVG's paintable as a plain GObject); GTK gives an empty GtkSvg for a file that is no picture, so that is refused |
| `GtkScrollView` | `GtkScrolledWindow` |
| `GtkTabView` | `GtkNotebook`, scrollable tabs |
| `GtkSplitView` | `GtkPaned` in a `GtkOverlay` (the paned claims the press before a controller on itself sees it); a move while a press that began on the handle is held is the user's, told as it happens; a 50 ms timeout follows the length (GTK has no resize signal) |
| `GtkGrid` | `GtkGrid`; padding as CSS padding, each side its own; only children whose cell changed are attached again |
| `GtkTable` | `GtkColumnView` over a list of row numbers; changed rows spliced |
| `GtkTextArea` | `GtkTextView` in a scrolled window; scrolls from code wait for GTK's layout to settle; a user action (paste over a selection) is one change |
| `GtkListView` | `GtkListView` (`GtkItemView`); its selection cleared after it is built (GTK selects the first) |
| `GtkCollectionView` | `GtkGridView`; a click activates |
| `GtkTree` | `GtkColumnView` over `GtkTreeListModel`; a header with no titles is hidden |

# Notes

* Resizing the window holds the pump until release on macOS (measured by hand 2026-11-22: 1.1 and 1.5 s with no pump return), so timers and mail wait; a repeating timer then fires once and skips the beats it missed.
* Styling: GTK 4 styles through CSS only and has no per-widget providers, so each widget carries a class named after its uuid and one display-wide provider holds that class's rules: one for `styleSelector()` (background, corner radius, padding, font, text colour; the text area adds its inner nodes), one for the widget's own node only (margin, border).
* Transitions: a CSS transition GTK runs; PHP only adds and removes two classes, sets one-shot timeouts and loads the two rules. FADE and SLIDE_*: opacity and a 24 px translate, eased out both ways (unchanged). MOVE_*: a translate by the widget's own border-box width or height toward its edge, no opacity, on topside's `curve()` as a CSS `cubic-bezier()` (`MOVE_IN_CURVE` 0.22, 1, 0.36, 1 appearing; `MOVE_OUT_CURVE` 0.64, 0, 0.78, 0 disappearing; PHP writes the floats with a dot whatever the locale); one going away reads its size at once, one appearing starts a window's size away and takes its own size once GTK has laid it out, then waits a frame. An appearing widget is shown at the away state and released once GTK has drawn it there (no animation otherwise). Clicks follow a moving widget (GTK picks through CSS transforms); a hidden one takes none.
* Clickable groups: `applyClickable()` in `GtkNative` puts a `GtkGestureClick` (button 1, bubble phase) on the group's widget and takes it off when turned off or on removal. A control inside (button, entry) handles the press or release first and claims it, which cancels the group's gesture; the group claims the release it takes, so a clickable group around it stays quiet. The point is from the widget's border-box top-left (GTK's coordinates start inside border and padding). An insensitive widget gets no events, and topside posts nothing while disabled.
* `hasFocus()`: the window's focus widget (`getRoot()->getFocus()`), not GTK's `hasFocus()`, which is false while the window is inactive. `size()`: the border box (`computeBounds`).
* Enabled: `setSensitive()` on the widget's own flag; GTK greys what is inside an insensitive container itself.

# Settings

What topside's optional hooks do on GTK. Each is GTK's own API through ext-gtk, or CSS in the primitive's rule; where GTK has no native way the hook stays empty and the setting is not shown (the getter still answers it).

| Setting | On GTK |
|---|---|
| Padding (column, row, grid, tab page) | CSS `padding: T R B L` on the widget, inside its background; 0 drops the declaration |
| Margin (every primitive) | CSS `margin: T R B L` on the widget's own node: outside its border and background; physical sides as `Insets` are (GTK's `margin-start`/`-end` would flip in right-to-left). Every GTK container lays a child out by its margin box |
| Cursor | `setCursorFromName()` with the CSS name (`default`, `pointer`, `text`, `crosshair`, `move`, `not-allowed`, `grab`, `grabbing`, `ew-resize`, `ns-resize`, `nwse-resize`, `nesw-resize`); null unsets. GTK's own text nodes (entry, text view) keep their text beam over the text |
| Tooltip | `setTooltipText()`; null unsets |
| Border | CSS `border: Wpx solid C` on the widget's own node; a null colour is `@borders`, which every theme GTK 4.24 ships defines; 0 drops it and the theme's own border is back |
| Button style | BORDERLESS: `setHasFrame(false)` (GTK adds `flat`); LINK: that and the theme's `link` class, which colours it; DEFAULT restores both. Topside sends the link's pointing hand |
| Button font, colour | CSS on the button, which its label inherits; a set colour wins over the theme's link colour |
| Button alignment | The label's `setXalign()`, again after each new label; with an icon, the image-and-label box's `halign` |
| Button icon | A horizontal `GtkBox` of a `GtkImage` (from the file, else `newFromIconName()`) and a `GtkLabel`, set as the button's child: GTK 4 has no single call for an image beside a label. The label keeps its text through `setLabel()`; no icon is a plain label child again. The theme underlines only a link button's direct label child, so a link-style button with an icon is not underlined |
| Button role DEFAULT | The window's default widget (`setDefaultWidget`): GTK gives it the `default` class and Return activates it; cleared when the role goes or the button is removed |
| Text input search | `setIconFromIconName(PRIMARY, 'system-search-symbolic')` on the entry (a `GtkSearchEntry` is another widget, without the entry's buffer) |
| Text input borderless, read-only, maximum length | `setHasFrame(false)`; `setEditable(false)`; `setMaxLength(n)`, null is 0 (none). GTK cuts longer text itself, whether the length or the value came first; the cut posts nothing and the entry's text becomes the value through topside's `cutTo()` |
| Return in a text input | `setActivatesDefault(true)` at build: it submits, then the window's default button is clicked |
| Corner radius (`Corners`) | One four-value CSS `border-radius` (top-left, top-right, bottom-right, bottom-left), from `applyCorners()`; `applyCornerRadius()` writes the same through it, so shorthand and longhands never mix; zero drops it. An image's radius rounds its picture too: its box cuts at the rounded edge (overflow hidden) while it has a radius or clips |
| Clip | `setOverflow(HIDDEN)`: GTK cuts the children at the widget's rounded edge; false gives back the overflow the widget was built with (a scrolled window clips of its own) |
| Link colour | CSS colour on the `GtkLinkButton` (through `GtkText`), which wins over the theme's `link` colour; null restores it |

Not shown on GTK:

* Button role CANCEL: a GtkWindow has a default widget for Return and nothing for Esc; Esc as cancel belonged to GtkDialog's responses (deprecated since GTK 4.10). The role is stored and nothing is set.

# Checked

Move curve (2026-11-25, a scratch real-window harness timed from the moment the transition's class goes on, window captures, 8 checks, pass on Homebrew PHP 8.4 NTS and ZTS; Pest 29 windowless and 20 integration pass on both): a 1 s MOVE_RIGHT drawer 640 pt wide is 0.82-0.83 of the way in at 0.3 s (x 106-114), 0.98 at 0.6 s (x 11-12), within 1 pt at 0.85 s, at x 0 at rest; closing is 0.006 out at 0.3 s (x 4) and 0.16-0.17 out at 0.7 s (x 100-109), mirroring the opening, then hidden. SLIDE_UP at 0.4 s 10 pt below its place and blended; FADE out at 0.4 s blended in place, then hidden. One NTS run of four saw the window present no frame between 0.3 s and 0.85 s (x 106 throughout, at x 0 at rest); the other three and the ZTS run passed.

Clickable groups and move transitions (2026-11-25, a scratch real-window harness with real pointer clicks and window captures, 26 checks, all pass on Homebrew PHP 8.4 NTS and ZTS; Pest 29 windowless and 20 integration pass on both): GroupClicked for a click on a clickable column at (4, 5) and on its label and image; a button inside posts only ButtonClicked, an entry nothing; a clickable overlay inside the clickable column posts alone, the column again once the overlay is off; disabled and off post nothing, and the controller is gone. A full-window drawer with MOVE_RIGHT over 1 s: at 0.5 s its left edge at x 278-284 (ease-out; linear would be about 384), pure colour to the right edge (no fade), the page left of it; landed at x 0 with opacity 1; it blocks a click on the page under it. Disappearing at 0.35 s: left edge at x 105-109 (ease-in), still opaque; then hidden, and the page's button clicks again. FADE midway blended in place; SLIDE_UP midway 8 pt below its place and blended; both solid at rest.

Overlay, corners and clip (2026-11-25, a scratch real-window harness, 35 checks, all pass on Homebrew PHP 8.4 NTS and ZTS; Pest 29 windowless and 20 integration pass on both): the native tree, children back to front, CSS padding, natural size the largest child plus padding, START and END children placed inside padding and margin, a fill-both child unmeasured and taking the area though aligned START, measured again when it fills one way, `moveTo()`/`moveBefore()` z-order with measure flags kept, removal; one four-value `border-radius` and no longhands, overflow on clip and back. In pixels (window capture): a picture filling a clipped 300x200 overlay with corners `0 0 14 14` at FILL covers it with the middle band of an 800x200 picture (the sides cropped), red to the top corners and white behind the cut bottom corners; a 3200x2400 picture filling an overlay with minimum 0x320 leaves it 616x320 and the window 640x560.

Maximum length cut (2026-11-24, a scratch real-window harness, 5 checks, NTS and ZTS): `abcdefgh` then length 5, and length 5 then `abcdefgh`, both leave `value()` and the entry at `abcde` with no mail; a short value is kept; no limit keeps the whole value; the window's content column takes `Insets` padding (the adapter builds no content column of its own; it mounts topside's). Pest 29 windowless and 20 integration pass on both builds.

New UI settings (2026-11-24, a scratch real-window harness through these classes, 58 checks, all pass on Homebrew PHP 8.4 NTS and ZTS; the two real Return key checks run when macOS lets the window be active): per-side padding on a column, row, grid and tab page, read back as the declared CSS and the child's offset (40, 10 for `10 20 30 40`) and live through `setPadding()`; margin offsets a label 12 down and 30 in and 0 restores; all twelve cursors read back by name from the widget's `GdkCursor`, null unsets; tooltip text and null; a 3 px `@borders` border adds 6 each way and 0 removes it; a text area's border on its own node only; borderless and link frames and classes, the link pointer and a set cursor winning over it; colour reaching the label (link and plain) and the theme colour back; a 28 pt font reaching the label; xalign kept across `setLabel()`; symbol, file over symbol, and back to a plain label; DEFAULT role as the window default, `default.activate` and a real Return clicking it, a text input's Return submitting then clicking it, cleared by another role, null and removal; search icon, frame, editable, maximum length (GTK cut `abcdefgh` to `abcde`, no mail) and null. A screenshot shows the per-side padding, a frameless search field with its icon, a link-style button with an icon, a normal button, a 24 pt margin between two labels and a theme-coloured border. Pest: 29 windowless and 20 integration pass on both builds.


List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness in a real window, NTS and ZTS): sizes, fill through a plain row, code changes post nothing, tab choice, removal. The live divider: a posted press with the move played by `setPosition()` (GDK takes a motion's buttons from the hardware).

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by activate() and setSelected().

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit 3ba4418.

[^native]: GtkNative

---
type: Module
title: UI primitives
description: The GTK column, row, fixed, label, button, check box, switch and dropdown on Topside's TK classes, and the native widget each one is.
resource: src/UI/
tags: [gtk, primitives]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T18:58:43Z }
sources:
  - id: native
    resource: src/UI/Concerns/GtkNative.php
    title: GtkNative
  - id: box
    resource: src/UI/Concerns/GtkBoxChildren.php
    title: GtkBoxChildren
---

# Classes

Each extends Topside's class (`TKColumn`, `TKRow`, `TKFixed`, `TKLabel`, `TKButton`, `TKCheckbox`, `TKToggle`, `TKDropdown`, `TKTextInput`, `TKComboBox`, `TKDatepicker`, `TKLink`, `TKSeparator`, `TKProgressBar`, `TKSpinner`, `TKSlider`, `TKToggleButton`, `TKSegmentedControl`, `TKImage`, `TKScrollView`, `TKTabView`, `TKSplitView`, `TKGrid`, `TKTable`, `TKTextArea`, `TKListView`, `TKCollectionView`, `TKTree`) and fills in its hooks; the driver's `$primitives` map names them. Fonts and alignment of what shows text are in `GtkText`. Shared native work is in `GtkNative`, what column and row share in `GtkBoxChildren`.[^native]

| Class | Native |
|---|---|
| `GtkColumn`, `GtkRow` | `GtkBox` (vertical / horizontal), built with the spacing; padding as CSS padding, inside the background; children appended, removed, reordered with `reorderChildAfter` |
| `GtkFixed` | `GtkFixed`: `put()` / `move()` at the frame, `setSizeRequest` to the larger of frame and minimum |
| `GtkLabel` | `GtkLabel`; left-aligned at build (`setXalign(0)` + `setJustify`, GTK centres by default); font and colour as CSS |
| `GtkButton` | `GtkButton::newWithLabel`; its `clicked` signal calls `clicked()`; focusable by default |
| `GtkCheckbox` | `GtkCheckButton`; `toggled` reports the new state (GTK emits it for `setActive()` too, which topside ignores as the state it holds) |
| `GtkToggle` | `GtkSwitch`; `notify::active` reports the new state |
| `GtkDropdown` | `GtkDropDown` over a `GtkStringList` (aliased `NativeDropDown`: PHP class names ignore case); `notify::selected` reports the choice, ignored while the model is replaced |
| `GtkTextInput` | `GtkEntry`; focus is its inner GtkText; Mac text keys from `Support/GtkMacTextKeys` |
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
| `GtkGrid` | `GtkGrid`; only children whose cell changed are attached again |
| `GtkTable` | `GtkColumnView` over a list of row numbers; changed rows spliced |
| `GtkTextArea` | `GtkTextView` in a scrolled window; scrolls from code wait for GTK's layout to settle; a user action (paste over a selection) is one change |
| `GtkListView` | `GtkListView` (`GtkItemView`); its selection cleared after it is built (GTK selects the first) |
| `GtkCollectionView` | `GtkGridView`; a click activates |
| `GtkTree` | `GtkColumnView` over `GtkTreeListModel`; a header with no titles is hidden |

# Notes

* Resizing the window holds the pump until release on macOS (measured by hand 2026-11-22: 1.1 and 1.5 s with no pump return), so timers and mail wait; a repeating timer then fires once and skips the beats it missed.
* Styling: GTK 4 styles through CSS only and has no per-widget providers, so each widget carries a class named after its uuid and one display-wide provider holds that class's rule (background, corner radius, padding, font, text colour).
* Transitions: a CSS transition GTK runs (opacity and a 24 px translate); PHP only adds and removes two classes and sets one-shot timeouts. An appearing widget is shown at the away state and released once GTK has drawn it there (no animation otherwise).
* `hasFocus()`: the window's focus widget (`getRoot()->getFocus()`), not GTK's `hasFocus()`, which is false while the window is inactive. `size()`: the border box (`computeBounds`).
* Enabled: `setSensitive()` on the widget's own flag; GTK greys what is inside an insensitive container itself.

# Checked

List, collection and tree (2026-11-22, a scratch harness, NTS and ZTS, and by screenshot): code changes post nothing; select, open, close and activate post; disabled items post nothing; removal. All sixteen primitives of that day's three batches also render from one Sharp file.

Table and text area (2026-11-22, a scratch harness, NTS and ZTS): fill, focus, code changes post nothing, the user's selection and typing post, line selection, scroll to a line, removal.

Scroll view, tab view, split view and grid (2026-11-22, a scratch harness in a real window, NTS and ZTS): sizes, fill through a plain row, code changes post nothing, tab choice, removal. The live divider: a posted press with the move played by `setPosition()` (GDK takes a motion's buttons from the hardware).

Separator, progress bar, spinner, slider, toggle button, segmented control and image (2026-11-22, a scratch harness, 25 checks in a real window on NTS and ZTS): sizes in a column and a row; code changes post nothing; the user's slide, press and choice post their mail; disabled posts nothing; PNG and SVG show, another file is refused; removal. Looked at in a screenshot.

Text input, combo box, date picker and link (2026-11-21, a scratch real-window harness, 30 checks, active and inactive window): code changes post nothing; typing, Return, option, completion and pick post one mail each; completions keep focus and hide on Esc, choice and focus leaving; disabled posts nothing; removal. The link is not clicked.

Check box, switch and dropdown (2026-11-21, a scratch real-window harness, 11 checks): changes from code post nothing; the user's toggle, flip and choice each post one mail with the new value; new options with a selection post nothing; nothing from inside a disabled column; removal. Driven by activate() and setSelected().

The real-window harness (a scratch topside-dev, 35 checks) through these classes passes: building, natives inside their containers, fixed frames, label text, alignment and font, a native click posting `ButtonClicked`, the enabled cascade, `focus()` moving between two buttons with `hasFocus()`, reordering, transitions, text colour, removal, close. Commit 3ba4418.

[^native]: GtkNative

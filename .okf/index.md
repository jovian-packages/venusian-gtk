---
okf_version: "0.2"
---

# jovian/venusian-gtk

* [Session](architecture/session.md) - GtkApplication held while connected, a budgeted GMainContext pump, a one-shot fd wake, and AppKit activation on macOS.
* [Window](architecture/window.md) - GtkApplicationWindow with a content box, wired to Topside's window lifecycle; content size compared after each pump.
* [Menu bar](architecture/menu-bar.md) - One GMenu model per window and one for none focused; GTK's macOS application menu answered through app.about, app.preferences and app.quit; bare keys after the focused widget.
* [UI primitives](architecture/ui-primitives.md) - The GTK column, row, fixed, label and button on Topside's TK classes, and the native widget each one is.

# API

* [Config](api/config.md) - config/gtk.php keys read by the driver and the toolkit.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.

# Deprecated

* [Windows and menus](architecture/windows-and-menus.md) - the bridge-era window, menus, focus and keys (deprecated2/).
* [Primitives](architecture/primitives.md) - the bridge-era GTK primitives (deprecated2/Primitives/).
* [Input](architecture/input.md) - the bridge-era input engine (deprecated2/Input/).

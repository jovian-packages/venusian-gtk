---
okf_version: "0.2"
---

# jovian/venusian-gtk

* [Session](architecture/session.md) - GtkApplication session: register once, hold while connected, budgeted context pump, one-shot fd wake, macOS activation.
* [Windows and menus](architecture/windows-and-menus.md) - GtkApplicationWindow per name, close/focus mail, GMenu bars in-window (Linux) or app-wide (macOS), About.

# API

* [Config](api/config.md) - bridge.gtk keys read by the driver.

# Runbooks

* [Testing](runbooks/testing.md) - Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.

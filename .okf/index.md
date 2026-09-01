---
okf_version: "0.2"
---

# jovian/venusian-gtk — knowledge bundle

The composition layer between `jovian/gtk` and `venusian/surface` on Linux.
This is where opinion is allowed: `jovian/gtk` may only project `ext-gtk`
one call at a time, and Surface may not know GTK exists, so everything that
bundles GTK calls into a policy lives here.

The OS bridge session and bare `GtkWindow` provisioning exist so far.
Widgets inside the window come next.

Read this index first. Every concept here is `status: draft` until a human
verifies it.

# Concepts

* [session.md](/session.md) - the GTK side of Surface's bridge lifecycle,
  why connecting and disconnecting are honest no-ops, and the `GtkWindow`
  factory with its scaffold child

# Related bundles

* [jovian/gtk](https://github.com/jovian/gtk) - the typed projection this
  package composes
* [venusian/surface](https://github.com/VenusianPHP/surface) - the
  cross-platform abstraction this package plugs into

# Fast facts

| | |
|---|---|
| Version | 0.8.0, PHP `^8.4\|^8.5\|^8.6`, Linux only |
| Namespace | `Jovian\Venusian\GTK\` at `src/` |
| Requires | `jovian/gtk`, `surface/bridge`, `surface/contracts`, `surface/native-windows`, `venusian-voyager/contracts` |
| Container alias | binds `linux.bridge` |
| Tests | `tests/Views` is orphaned from the torn-out drivers; scope runs around it |

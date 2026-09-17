---
okf_version: "0.2"
---

# jovian/venusian-gtk — knowledge bundle

The composition layer between `jovian/gtk` and `venusian/surface` on Linux.
This is where opinion is allowed: `jovian/gtk` may only project `ext-gtk`
one call at a time, and Surface may not know GTK exists, so everything that
bundles GTK calls into a policy lives here.

The OS bridge session, bare `GtkWindow` provisioning, and the nineteen
Surface view twins exist — including `GTKDatePicker` (`GtkCalendar`,
month 0-based), `GTKTable` (`GtkColumnView` + factories), and
`GTKGLView` / `GTKGLSurface` (`GtkGLArea` for `SurfaceKind::GL_CONTEXT`).
`mintGPU()` hosts only `GL_CONTEXT`; every other `SurfaceKind` is refused
by kind.

Read this index first. Every concept here is `status: draft` until a human
verifies it.

# Concepts

* [session.md](/session.md) - the GTK side of Surface's bridge lifecycle,
  why connecting and disconnecting are honest no-ops, and the `GtkWindow`
  factory with its scaffold child
* [gpu-view.md](/gpu-view.md) - the GtkGLArea twin: self-driving frames, the context lender, hosts only GL_CONTEXT
* [input-engine.md](/input-engine.md) - `input.gtk`: per-window event controllers, buffered callbacks, keycode − 8, evdev gamepads

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
| Requires | `jovian/gtk`, `microscrap/scrapyard-evdev`, `surface/bridge`, `surface/contracts`, `surface/drawing`, `surface/human-input`, `surface/native-windows`, `venusian-voyager/contracts` |
| Container aliases | binds `linux.bridge`, `input.gtk` |
| Tests | `tests/Views` is orphaned from the torn-out drivers; scope runs around it |

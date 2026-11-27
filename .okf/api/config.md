---
type: Reference
title: Config
description: config/gtk.php keys read by the driver and the toolkit.
resource: config/gtk.php
tags: [gtk, config]
status: draft
generated: { by: claude-opus/5.5, at: 2026-11-21T17:39:27Z }
sources:
  - id: config
    resource: config/gtk.php
    title: config/gtk.php
  - id: driver
    resource: src/GtkAdapterDriver.php
    title: GtkAdapterDriver
---

# Schema

Merged under `gtk` by `VenusianGTKServiceProvider` and published as `config/gtk.php` (tag `gtk-config`). The toolkit is chosen by Topside's `toolkits.default` (`TOOLKIT_DRIVER=gtk`).[^config]

| Key | Default | Meaning |
|---|---|---|
| `gtk.application_id` | `env('APP_ID', 'com.venusian.gtk')` | desktop identity: Wayland app_id, X11 `WM_CLASS`, bus name when unique; the driver throws without one |
| `gtk.unique_primary_instance` | `false` | `true`: one primary instance per id (a second process becomes remote) |
| `gtk.loop_drain_limit` | `64` | non-blocking iterations one pump runs after its wait ([session](/architecture/session.md)) |
| `gtk.video.driver` | `'gtk'` | the video manager's default driver: `gtk` (GTK's own media; none on macOS) or `gstreamer` (jovian/venusian-gstreamer) ([Video](/design/video.md)) |
| `gtk.video.position_interval` | `100` | milliseconds between the `gstreamer` driver's bar position updates while it plays (GStreamer's own `GstPlay` default) |

[^config]: config/gtk.php

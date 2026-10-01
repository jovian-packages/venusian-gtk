---
type: Reference
title: Config
description: bridge.gtk keys read by the driver.
resource: src/Bridge/GTKBridgeDriver.php
tags: [gtk, config]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-01T20:06:29Z }
---

# Schema

Read through the container's `config`; absent from Surface's published `config/bridge.php`, defaults in code.

| Key | Default | Meaning |
|---|---|---|
| `bridge.gtk.application_id` | `org.venusian.Surface` | desktop identity: Wayland app_id, bus name when unique |
| `bridge.gtk.unique` | `false` | `true` = one primary instance per id (a second process becomes remote) |
| `windows.about.*` | Surface config | About dialog fields |

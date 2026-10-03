---
type: Runbook
title: Testing
description: Pest suite against the real toolkit in a workbench of path repos; Mac and Pi.
resource: tests/
tags: [gtk, pest]
status: draft
generated: { by: claude-opus/5.5, at: 2026-10-02T22:33:44Z }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
---

# Overview

Suite drives the real toolkit: windows appear on screen. One driver per process (one application per process), built in `tests/Pest.php` with a `ControlPanel` container holding `config`, `toolkit-bridge`, `toolkit-windows`; helpers `driver()`, `session()`, `takeMail()` (empties the outbox), `viewMail()` (only primitive mail), `pumpFor($seconds)` and `pumpUntil($done)` (both through `ToolkitPump`, so latest-only mail flushes per pump as in the loop), `requireActive($window)`.[^pest]

Covers: session lifecycle, budgeted wait, loop join (kqueue/epoll fd ends the toolkit sleep), open/close/focus mail, menu build, item/toggle/quit mail, About, default bar, profile swap; every primitive kind's widget, state, mail and removal (`tests/Primitives`), window and watched-view resize.

Environment facts the tests account for:

* macOS activation is cooperative (14+): with another app in use the system may decline it; `requireActive()` skips the test there with that reason, and fails elsewhere. The first window a process presents takes ~200-300 ms to activate.
* A desktop in use can wake an idle pump with a foreign event: the budget test samples up to five waits.
* The Pi panel is 480 px wide; labwc caps windows there, so resize tests stay below it.
* Video plays only with a GTK media backend (Pi); on the Mac the playback test skips and the refusal test runs.
* Fixtures: `pixel.png`, `broken.png` (PNG header + garbage), `clip.mp4` (1.2 s H.264).

Workbench (never the repo root):

1. Copy the package (no vendor) to a scratch dir.
2. Path repositories, symlinked: `<framework>/src/Voyager/*`, `<surface>/src/Surface/*`.
3. `composer install`; `php84 vendor/bin/pest` and `zhp vendor/bin/pest`.
4. Pi: copy package + Surface + Voyager sources, path repos relative, run with `WAYLAND_DISPLAY=wayland-0 DISPLAY=:0 XDG_RUNTIME_DIR=/run/user/1000 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1000/bus`. Announce with `say` before windows light the panel. macOS-only tests skip on Linux.

Delete the workbench (and Pi copy) after.

[^pest]: tests/Pest.php

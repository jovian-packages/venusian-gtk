---
type: Component
title: GTKGLView
description: The GTK twin of Surface's GPUView over a GtkGLArea — self-driving frames inside the pump, a surface that lends the context, hosts only GL_CONTEXT and refuses every other SurfaceKind by kind.
tags: [gtk, gpu, opengl, glarea, views]
status: draft
generated: { by: cursor-grok-4.6/cursor, at: "2026-09-14T02:30:00Z" }
sources:
  - id: twin
    resource: src/Views/GTKGLView.php
    title: GTKGLView
  - id: surface
    resource: src/Views/GTKGLSurface.php
    title: GTKGLSurface
  - id: mint
    resource: src/Windows/GTKWindowDelegate.php
    title: GTKWindowDelegate::mintGPU
---

# Overview

`mintGPU()` accepts only `SurfaceKind::GL_CONTEXT`. It mints a `GtkGLArea`
(`GL | GLES` allowed, 3.0 required, auto-render off, no depth), puts it in
the scaffold's `GtkFixed`, wraps it in `GTKGLSurface`, passes that as
`GPUHost->gl`, attaches, then builds `GTKGLView` with the surface and the
executor.

# Self-driving frames

`drivesOwnFrames()` is true. `Windowable::renderFrames()` therefore calls
`requestFrame()` → `queueRender()`; GTK's frame clock fires `render` →
`handleRender()` → `GPUView::renderFrame()`. When Surface skips, the twin
paints the clear colour anyway and returns true. The draw hook runs inside
`Bridge::pump()`, the documented exception shared with `onClick`.

# The Pi's context

GLES 3.1 in GtkGLArea's own FBO; the engine reads `DRAW_FRAMEBUFFER_BINDING`
and never assumes 0. `present()` is a no-op — GTK swaps on signal return.
`onResize` is not wired; Surface's layout owns size and `applyFrame`
resizes the executor by `getScaleFactor()`.

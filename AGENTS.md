# Agent guidelines — jovian/venusian-gtk

## Knowledge Bundle (OKF)

This package ships an Open Knowledge Format bundle at [`.okf/`](.okf/)
(excluded from the Composer dist via `.gitattributes` `export-ignore`).
Before changing code or advising on this package: read
[`.okf/index.md`](.okf/index.md) first, open only the concepts the task
needs, prefer `status: stable` over `draft`. When you learn something
durable, update the affected concept(s) and append [`.okf/log.md`](.okf/log.md);
new or changed concepts stay `status: draft` until a human verifies them.

Do **not** create `.okf` folders under `src/Sessions` or any other component
tree — knowledge for this package lives at the package root only.

## Where this package sits

`ext-gtk` (1:1 binding, zero opinion) → `jovian/gtk` (enums, helpers, typed
projection) → **`jovian/venusian-gtk`** (composition) → `venusian/surface`
(cross-platform abstraction).

**This is the layer where opinion is allowed.** `jovian/gtk` may only project
one extension call per method, and Surface may not know GTK exists, so
everything that bundles GTK calls into a policy belongs here.

Never depend on `jovian/appkit` or `jovian/venusian-appkit`, and never build
a cross-platform abstraction here — that is Surface's job, and the two engine
packages are shape-parallel by design and share no code.

## Current state

The OS bridge session and bare `GtkWindow` provisioning exist. The 0.8 view
drivers were written against an older, opinionated `ext-gtk` and were torn
out; widgets inside the window come next. `tests/Views/**` is orphaned from
the removed drivers; scope test runs around it.

`GTKWindowDelegate` does **not** make the content root the window's child.
It sets a vertical `GtkBox` scaffold holding a `GtkFixed` content, so a later
chrome slice prepends menus into the box instead of reparenting a live tree.
Do not flatten it. See [`.okf/session.md`](.okf/session.md).

## Package rules (quick) — 0.8.x

- Composer: `jovian/venusian-gtk` **0.8.0**. PHP `^8.4|^8.5|^8.6`. Linux
  only. Requires `jovian/gtk`, `surface/bridge`, `surface/contracts`,
  `surface/native-windows`, `venusian-voyager/contracts`.
- Namespace root is `Jovian\Venusian\GTK\` at `src/`. Note the binding
  package underneath is `Jovian\Bindings\Gtk\` — mixed case, not `GTK`.
- **The provider binds `linux.bridge`.** That container alias is the entire
  seam to Surface; installing this package is the whole of what makes Linux
  windowing available. Do not rename it.
- **Implement Surface's contracts, do not re-declare policy.** The abstract
  in `surface/bridge` owns guards, idempotency, and state. Fill the hooks.
- **Exceptions subclass `Surface\Contracts\Bridge\BridgeException`** so a
  sketch catches one type without naming GTK.
- **`Lifetime::boot()` before anything.** Constructing a DTO before boot
  throws `NotBooted`; calling a raw C-named helper before it is a native
  crash. Nothing initialises GTK implicitly.
- **Never call `GApplication::run()`.** A remote `GApplication` instance
  segfaults on window creation. PHP drives the loop by iterating the default
  main context via `Bridge::pump($ms)`. `GtkApplicationWindow` may only be
  constructed inside an `activate` handler.
- **`pump($ms)` may block** for the full budget when the main context is
  empty. That measured behaviour is why Surface's contract promises a tick
  may block.
- **Signal callbacks deliver raw `int` handles.** Box them through
  `Registry::box()` yourself; unlike AppKit's bridge, nothing boxes for you.
  There is no signal mute — only `Bridge::connect` and `Bridge::disconnect`.
- **`setDefaultSize()` is a request, not a fact.** Real allocation exists
  only after `present()` plus a pump; `getWidth()` / `getHeight()` read 0x0
  before that. AppKit has no such phase — its content rect *is* the size at
  construction. Anything reading size has to tolerate the gap or Surface has
  to hide it.
- **Close destroys here.** `GtkWindow::destroy()` and a user closing the
  window do the same thing, and GTK recycles the handle, so polling a closed
  window touches a dead handle. macOS only hides. A last-window-closed exit
  condition has to be built per platform.
- **Bitfield values stay `int`** because PHP enums cannot be OR'd. Build them
  from `SomeEnum::CASE->value | ...`.
- Enums are int- or string-backed with FULLY UPPERCASE cases. **No class
  constants anywhere.** Prefer `is_null($var)` over `$var === null`.

## Verification

Pure-logic code should be covered by Pest with no extension present.
Anything that touches GTK runs on the Pi over the `fnk` zsh alias — sync
`src tests composer.json phpunit.xml` first, never `vendor/`. Never inline
the alias's credentials.

An extension-gated test that skips is not evidence. The standing acceptance
check for the bridge: boot, tick, clean exit, no `Gtk-CRITICAL` in the
output.

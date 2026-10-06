# Log

## 2026-10-06

* Canvas lends a GL context: the native view is a `GtkBox` holding the picture or, while lent, a `GtkGLArea` whose render callback copies the `opengl` engine's frame. [primitives](architecture/primitives.md)

## 2026-10-04

* `GTKCanvas` pipes an ext-fb framebuffer: the texture is copied by address, and on GTK 4.16 or newer the next one is built with `GdkMemoryTextureBuilder` and an update region. [primitives](architecture/primitives.md)

## 2026-10-03

* `GTKCanvas`: the framebuffer a `TKCanvas` hands out, shown as a memory texture on a `GtkPicture`. [primitives](architecture/primitives.md)

## 2026-10-02

* [Primitives](architecture/primitives.md): scoped style targets, escaped font family, datepicker follows month/year navigation and refuses years outside 1-9999, table splices from the first changed row, watched views keyed by uuid; window resize polled ([windows and menus](architecture/windows-and-menus.md), [session](architecture/session.md)).
* New [primitives](architecture/primitives.md): factory, containers, all leaves, styling, sizes, watched allocations, mail rules. [Windows and menus](architecture/windows-and-menus.md): content area, primitive tree on close, WindowResized. [Session](architecture/session.md): watched sizes after each pump. [Testing](runbooks/testing.md): helpers, environment facts, fixtures.

## 2026-10-01

* Bundle created: [session](architecture/session.md), [windows and menus](architecture/windows-and-menus.md), [config](api/config.md), [testing](runbooks/testing.md).

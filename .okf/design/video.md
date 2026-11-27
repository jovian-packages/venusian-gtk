---
type: Design
title: Video
description: The design for TKVideo on GTK - a video manager with drivers inside this package, the 'gtk' driver (GTK's own media, the default; unsupported on macOS, Linux left open), the 'gstreamer' driver (jovian/venusian-gstreamer's playbin drawing into a GtkPicture through gtk4paintablesink), the ext-gtk and ext-gstreamer bindings it needs, what was measured, and the build stages.
tags: [gtk, toolkits, video, gstreamer, design]
status: approved
generated: { by: claude-opus/5.5, at: 2026-11-27T22:00:00Z }
sources:
  - id: tkvideo
    resource: ../../venusian/topside/src/Surface/Toolkits/UI/TKVideo.php
    title: TKVideo (the hooks a toolkit fills in)
  - id: manager
    resource: ../../venusian/framework/src/Voyager/NutsAndBolts/Manager.php
    title: Manager (driver(), create{Name}Driver(), extend())
  - id: gtkvideo
    resource: src/UI/GtkVideo.php
    title: GtkVideo today (an empty GtkBox, supported() false)
  - id: session
    resource: architecture/session.md
    title: The pump (one blocking GMainContext iteration, then a drain)
  - id: qt-design
    resource: ../venusian-qt/.okf/design/video.md
    title: The Qt video design (the same manager and interfaces, built 2026-11-27)
  - id: appkit-design
    resource: ../venusian-appkit/.okf/design/video.md
    title: The AppKit video design (the avkit driver over jovian/venusian-avkit)
  - id: ext-gtk
    resource: ../../php-io-extensions/gtk/bindings.json
    title: ext-gtk glue (GObject has pointer() only), runtime.c phpgtk_box_gobject and the GValue conversion
  - id: ext-gstreamer
    resource: ../../php-io-extensions/gstreamer/AGENTS.md
    title: ext-gstreamer scope (every call on the PHP thread, no signals or streaming-thread callbacks) and bindings.json
  - id: debian
    resource: https://packages.debian.org/stable/gstreamer1.0-gtk4
    title: Debian's gstreamer1.0-gtk4 (trixie 0.13.5, source rust-gst-plugin-gtk4)
  - id: probes
    resource: probe runs 2026-11-27, GStreamer 1.28.9 and GTK 4.24.1 (Homebrew), macOS 15.4.1, through PyGObject
    title: playbin with gtk4paintablesink, the bus fd, seeks, buffering, failures
---

# Status: approved, built

Angel approved this document on 2026-11-27. Built: ext-gtk 68daef8 (`GObject::fromPointer`), ext-gstreamer 6502f99 (`GPollFD`, `gst_bus_get_pollfd`, `gst_message_parse_buffering`), jovian/venusian-gstreamer 9af2415, and this package.

**Built differently from the draft:**

* `GObject::fromPointer()` takes PHP's own reference with `g_object_ref`, never a sink: ext-gtk's usual box sinks a floating reference, and `gst_parse_launch` returns a floating playbin, so sinking took ext-gstreamer's reference and its `gst_object_unref` then freed the object twice (measured, SIGSEGV).
* `GstreamerPlayer::load()` takes a URI: topside hands every toolkit a source already made a URL (an absolute path arrives as its `file://` URL).
* `$videoSink` is a launch description (a factory with its properties), not only a factory name. On macOS GStreamer's GL elements need an NSApplication on the main thread; a sink that takes any memory (`fakesink`) gets the VideoToolbox decoder's GL memory, and without one that crashes (measured: SIGSEGV in `_io_surface_gl_memory_destroy`, at the second play of a clip). jovian/venusian-gstreamer's own tests, with no toolkit, use `appsink caps=video/x-raw max-buffers=1 drop=true wait-on-eos=false`; in a GTK process GTK runs the NSApplication, and the driver's `gtk4paintablesink` plays (VideoTest, NTS and ZTS).
* Failure texts are GStreamer's first error, measured: a missing file "Resource not found.", a file that is not media "This appears to be a text file", a refused connection "Internal data stream error.", a 404 "Not Found".

# What the author gets

`<Video>` on GTK plays through a player the app picks with one config value: `gtk` (the default, GTK's own media) or `gstreamer` (GStreamer's playbin, through jovian/venusian-gstreamer). With `gstreamer`, a source that is an absolute file path, a `file://` URL or an `https://` URL plays, with the mail and calls `TKVideo` already defines, on macOS and Linux. With `gtk`, on macOS nothing plays and every source posts `VideoFailed` (as today); its Linux player is left open. Nothing changes in topside or the framework.

# Manager and drivers

The same shape as AppKit's and Qt's, in this package's own classes. The composition asks for a `TKVideo`; `GtkVideo` asks the package's video manager for the loaded driver, and the driver makes the player.

| Piece | File | What it is |
|---|---|---|
| `GtkVideo` | `src/UI/GtkVideo.php` | The one `TKVideo` of this package (already in the primitive map). It keeps no player code: every `apply*` and `read*` hook forwards to its player, and `supported()` asks the driver. |
| `GtkVideoManager` | `src/Video/GtkVideoManager.php` | Extends the framework's `Manager`. `getDefaultDriver()` reads `gtk.video.driver`. `createGtkDriver()`, `createGstreamerDriver()`; `extend()` for more. |
| `GtkVideoDriver` | `src/Video/GtkVideoDriver.php` | Interface: `player(GtkVideoListener $listener): GtkVideoPlayer`, and `supported(): bool`. A driver checks what it needs when it is made and throws with the fix. |
| `GtkVideoPlayer` | `src/Video/GtkVideoPlayer.php` | Interface, one per view: `widget(): GtkWidget`, `load(?string $url, bool $muted, bool $loop, bool $controls)`, `play()`, `pause()`, `seek(float)`, `setMuted(bool)`, `setLoop(bool)`, `setControls(bool)`, `position(): float`, `duration(): ?float`, `release()`. |
| `GtkVideoListener` | `src/Video/GtkVideoListener.php` | Interface the player reports to: `videoPlaying()`, `videoPaused()`, `videoEnded()`, `videoFailed(?string)`, `videoMuted(bool)`. `GtkVideo` implements it. |
| `MediaFileVideoDriver`, `MediaFileVideoPlayer` | `src/Video/MediaFile/` | The `gtk` driver. |
| `GstreamerVideoDriver`, `GstreamerVideoPlayer` | `src/Video/Gstreamer/` | The `gstreamer` driver. |

`supported()` is on the driver for the reason it is on Qt's: GTK can be built with no media backend, and only the driver knows whether its player can play.

How the view reaches the manager: `GtkAdapterDriver::video(): GtkVideoManager`, built once with the framework core; `GtkWindow::adapter()` gives a primitive its window's driver. Neither exists today.

Config, in `config/gtk.php` (documented in [Config](/api/config.md)):

```php
'video' => [
    'driver' => 'gtk',
    'position_interval' => 100,
],
```

`position_interval` is the milliseconds between position updates of the `gstreamer` driver's bar while it plays; 100 is GStreamer's own default for the same job (`GstPlay`'s `position-update-interval`).

# The gtk driver

GTK's own player is `GtkVideo` over a `GtkMediaFile`, played by a media backend module GTK loads at run time (`libgtk-4-media-gstreamer` on Debian). ext-gtk binds the whole path already: `gtk_video_*`, `gtk_media_file_*`, `gtk_media_stream_*`.

* **macOS:** unsupported. Homebrew's gtk4 4.24.1 ships no media module (there is no `lib/gtk-4.0` in its prefix), so GTK has nothing to play with. `supported()` is false and the player is today's `GtkVideo`: an empty `GtkBox` that lays out, fills and takes a size like any primitive and draws nothing. Topside then posts `VideoFailed` with `TKVideo::NO_PLAYER` for every source itself.
* **Linux:** left open. The `GtkVideo` over `GtkMediaFile` player is its own stage, not in this work; until it is built, the driver answers on Linux as it does on macOS, which is what today's `GtkVideo` does everywhere.

# The gstreamer driver

The same split as AppKit's `avkit`: the driver is in this package, the player in jovian/venusian-gstreamer, and that package talks to ext-gstreamer. jovian/venusian-gstreamer knows nothing about GTK or Topside.

## jovian/venusian-gstreamer

Namespace `Jovian\Media\Gstreamer` (as jovian/venusian-avkit is `Jovian\Media\Avkit`). Requires ext-gstreamer only. Its scaffold today is house files and a `composer.json` copied from venusian-appkit; this work gives it its manifest, player, tests, README, CI and bundle.

`GstreamerPlayer`, one playbin:

| Member | What it does |
|---|---|
| `__construct(GstreamerPlayerListener $listener, string $videoSink)` | `gst_parse_launch('playbin video-sink="<videoSink> name=…"')`: `$videoSink` is a launch description the caller picks (a factory, with its properties); the launch grammar makes the element for playbin's element-typed property (measured: playbin's `video-sink` is then a `GstGtk4PaintableSink`). |
| `load(?string $uri)` | `GST_STATE_NULL`, `uri` set (`gst_util_set_object_arg`), then `GST_STATE_PAUSED`: paused at its start. Null unloads. |
| `play()`, `pause()` | `GST_STATE_PLAYING`, `GST_STATE_PAUSED`. `play()` after the end starts from the start. |
| `seek(float $seconds)` | `gst_element_seek_simple` with `GST_SEEK_FLAG_FLUSH \| GST_SEEK_FLAG_ACCURATE` (measured: 0.95 s reads 0.95 s). |
| `setMuted(bool)`, `isMuted()` | playbin's `mute`, through `gst_util_set_object_arg` (measured: "true" reads back true). |
| `setLoop(bool)`, `isLooping()` | At the end, while looping, a flushing seek to 0 in place of the end (measured: the clip ran three times, no error). |
| `position()`, `duration()` | `gst_element_query_position` / `_duration` in `GST_FORMAT_TIME`; null duration until the media reports it. |
| `isPlaying()` | Playing, and not held by buffering. |
| `videoSink(): ?GstElement` | The sink, by `gst_bin_get_by_name`: null until the media prerolls (measured: playbin puts the sink in its tree at preroll, not before) and for a source with no video. |
| `busFd(): int` | The bus's file descriptor, from `gst_bus_get_pollfd`: readable while messages wait. |
| `dispatch()` | Drains the bus with `gst_bus_timed_pop_filtered($bus, 0, …)` and reports to the listener. |
| `release()` | `GST_STATE_NULL`, the playbin unreferenced; nothing is reported after it. |

Why the bus fd and not a bus watch: ext-gstreamer's scope is every call on the PHP thread, no signals or streaming-thread callbacks. `gst_bus_get_pollfd` is GStreamer's own call for joining a bus to another event loop by its fd, and the player stays free of any loop: its owner says when the fd is readable.

`GstreamerPlayerListener`: `gstreamerLoaded()` (prerolled after a load: `videoSink()` is set), `gstreamerPlaying()`, `gstreamerPaused()`, `gstreamerEnded()`, `gstreamerFailed(?string $reason)`.

How bus messages become reports:

| Message | Report |
|---|---|
| The first `GST_MESSAGE_ASYNC_DONE` after a load | `gstreamerLoaded()` |
| `GST_MESSAGE_STATE_CHANGED` from playbin itself, to PLAYING | `gstreamerPlaying()` |
| The same, PLAYING to PAUSED, not by buffering and not at the end | `gstreamerPaused()` |
| `GST_MESSAGE_EOS`, not looping | `gstreamerEnded()` |
| `GST_MESSAGE_ERROR`, the first after a load | `gstreamerFailed()` with the error's message (`gst_message_parse_error` throws it as `GstError`); the ones after it are dropped (measured: each failure posts two or three) |
| `GST_MESSAGE_BUFFERING` | Below 100% while playing: held in PAUSED; at 100%: PLAYING again if it was asked to play. This is GStreamer's rule for network sources (measured: NASA's https mp4 sends 0 to 100%). A hold is not a pause. |

## The driver in this package

`GstreamerVideoDriver`, when it is made, throws with the fix when any of these is missing:

* ext-gstreamer: `pie install php-io-extensions/gstreamer`.
* jovian/venusian-gstreamer (a `suggest` of this package, `require-dev` for its tests): `composer require jovian/venusian-gstreamer`.
* `gst_init_check()` true.
* The `gtk4paintablesink` element (`gst_element_factory_make` gives null without it): Homebrew's `gstreamer` has it; Debian's package is `gstreamer1.0-gtk4`.
* `GObject::fromPointer()` in ext-gtk: names the ext-gtk version that has it.

`supported()` is true: the driver is made only when all of the above are there.

`GstreamerVideoPlayer`, one per view:

* **The widget:** a vertical `GtkBox` holding a `GtkPicture` (`GTK_CONTENT_FIT_CONTAIN`, the picture whole) over a bar shown while the view has controls: play or pause, a `GtkScale` for the position, the time, the sound. The icons are GTK's own, which GTK carries in its resources (`media-playback-start-symbolic`, `media-playback-pause-symbolic`, `audio-volume-high-symbolic`, `audio-volume-muted-symbolic`; measured in libgtk-4.1). The bar is the driver's, as the `qt` driver's is on Qt: GTK's `GtkMediaControls` drives a `GtkMediaStream`, and a playbin is not one.
* **The picture:** `GstreamerPlayer` with `gtk4paintablesink`. At `gstreamerLoaded()` the driver takes `videoSink()`, gives its address to ext-gtk (`GObject::fromPointer($sink->pointer())`), reads the sink's `paintable` with `g_object_get_property`, and sets it as the picture's `paintable` with `g_object_set_property`. ext-gtk boxes the paintable as a `GObject` (no PHP class maps `GstGtk4Paintable`), and its property setter takes any `GObject` whose native type is a `GdkPaintable` (`runtime.c`, the GValue conversion), so no new class is needed. The frames go from GStreamer to GTK in native code: PHP copies none.
* **The bus:** `g_unix_fd_add(busFd(), G_IO_IN, …)` calls `dispatch()`. The pump blocks in GLib's own `iteration(true)` ([Session](/architecture/session.md)), so a waiting message wakes it.
* **The bar's position:** a `g_timeout_add(gtk.video.position_interval)` while playing with controls shown, removed otherwise.
* **Reports:** the listener's calls map one to one onto `GtkVideoListener`; the bar's sound button reports `videoMuted()`.
* **Release:** both sources removed with `g_source_remove`, the picture's paintable set to null, `GstreamerPlayer::release()`: a removed view posts nothing.

## Who carries what

* jovian/venusian-gstreamer: ext-gstreamer.
* jovian/venusian-gtk: ext-gtk, as today. jovian/venusian-gstreamer and `gtk4paintablesink` are needed only by the `gstreamer` driver, checked when it is made.

# Bindings to add

## ext-gtk

| Binding | Kind | Why |
|---|---|---|
| `GObject::fromPointer(int $pointer): ?static` | glue: `fromPointer` added to `GObject`'s `methods` in `bindings.json` | Takes the sink from ext-gstreamer. `GObject` has `pointer()` only today. Same contract as ext-gstreamer's and ext-appkit's: the address is trusted, 0 gives null; boxed with `phpgtk_box_gobject` (an existing box, or a new one holding its own reference). |

## ext-gstreamer

| Binding | Kind | Why |
|---|---|---|
| `gst_bus_get_pollfd(GstBus $bus, GPollFD $fd): void` (gstbus.h) | function, plus a `GPollFD` glue class in `bindings.json`: a struct the caller allocates, `fd`, `events` and `revents` as properties, as `GstMapInfo` is | The bus's fd for the GTK main loop |
| `gst_message_parse_buffering(GstMessage $message, ?int &$percent): void` (gstmessage.h) | function | The buffering percentage |

Both functions are in GStreamer 1.26's headers (the floor), unbound today (SUPPORT.md).

# Measured

Probe runs on 2026-11-27: GStreamer 1.28.9 and GTK 4.24.1 from Homebrew, macOS 15.4.1, through PyGObject (the bindings above do not exist yet), with GTK initialized and no window. The clip is the 1.2 s fixture `clip.mp4` (H.264) on the external volume, the audio `tone.m4a`, NASA's https file the APOD mp4 `galaksi_merkezi_spherex.mp4`.

| Case | Result |
|---|---|
| `playbin video-sink="gtk4paintablesink name=vsink"` | playbin's `video-sink` is a `GstGtk4PaintableSink`; `get_by_name('vsink')` null at NULL, READY and right after PAUSED is asked, found once prerolled |
| The sink's `paintable` | a `GstGtk4Paintable`, a `GdkPaintable`; 160×120 from the clip, 1280×800 from NASA's file |
| `gtk4paintablesink` and ext-gtk | both linked to `/opt/homebrew/opt/gtk4/lib/libgtk-4.1.dylib` |
| The bus fd | not readable before any message; readable while messages wait; drained by popping |
| The clip, to the end | prerolled, duration 1.2 s, EOS at 1.4 s |
| NASA's https mp4, to the end | duration 6.875 s, buffering 0 to 100%, EOS at 7.44 s |
| Seek to 0.95 s, accurate | reads 0.95 s, file and https |
| `mute` by `gst_util_set_object_arg` | reads true |
| Looping by a flushing seek at EOS | three runs of the clip, still playing, no error |
| `tone.m4a` | plays; no video sink in the tree |
| A missing file | "Resource not found." (resource error 3), then a core error |
| An https 404 | "Not Found" (resource error 3), then two stream errors |

On Linux, Debian trixie's `gstreamer1.0-gtk4` (0.13.5) carries `gtk4paintablesink`.

# Tests

* `tests/Integration/VideoTest.php`: every case once per driver where the driver plays. The `gtk` driver on macOS: today's two cases (every source fails with `NO_PLAYER`, nothing plays; it lays out and fills). The `gstreamer` driver: the AppKit and Qt set (a file, the audio and both over HTTP to the end; paused until `play()`; an exact seek; muting, looping and controls before and after the source; looping with no end; four failures with a reason; a source left behind; a removed view), plus its bar's buttons. Each case reads the playbin's own state.
* `tests/Windowless/VideoManagerTest.php`: the default is `gtk`, `gstreamer` gives its driver, an unknown name throws, `extend()`, `supported()` from the driver, and the missing-package throw (a second PHP whose autoloader has no jovian/venusian-gstreamer).
* jovian/venusian-gstreamer: its own suite, with no GTK: load, play, pause, seek, mute, loop, the reports, the failures, release, on `fakesink` and `appsink`.
* ext-gtk: `fromPointer()` gives back the same object, null for 0. ext-gstreamer: a case for each new function.
* Run as each package's testing runbook says, only the files a change touches; NTS and ZTS.

# Stages

1. ext-gtk `GObject::fromPointer()`, with its tests.
2. ext-gstreamer `GPollFD` and `gst_bus_get_pollfd`, `gst_message_parse_buffering`, with their tests.
3. The manager, the three interfaces, `GtkVideo` on them, the config keys, `GtkAdapterDriver::video()` and `GtkWindow::adapter()`.
4. The `gtk` driver: today's `GtkVideo` moved; today's two tests pass unchanged on it.
5. jovian/venusian-gstreamer: `GstreamerPlayer`, its listener, manifest, tests, README, CI and bundle.
6. The `gstreamer` driver and the tests on both drivers.
7. The README line, this bundle's UI primitives page, config page and log.
8. stargazer2 with `TOOLKIT_DRIVER=gtk`, on each driver.

# Not in this work

* The `gtk` driver's Linux player (`GtkVideo` over `GtkMediaFile`).
* A mail that says "ready to play" (for a loading face): its own topside design, as on AppKit.

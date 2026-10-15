# jovian/venusian-gtk

GTK 4 toolkit driver for the Venusian Surface bridge.

## Canvas

`GTKCanvas` shows a `TKCanvas`'s framebuffer as a `GdkMemoryTexture` on a `GtkPicture`, in the `R8G8B8X8` layout (the fourth byte ignored, so opaque), stretched over the widget. Needs GTK 4.14.

An ext-fb framebuffer is piped. `present()` copies the framebuffer's memory by address, and on GTK 4.16 or newer the texture after the first is built with `GdkMemoryTextureBuilder` and an update region, so only the damage is read. No pixel byte passes through PHP. A framebuffer held in PHP (the native driver) takes the same path from a string.

With ext-opengl loaded the canvas lends a GL context to the `opengl` engine (jovian/venusian-opengl). The native view is a `GtkBox` holding the picture; lending swaps a `GtkGLArea` in, and the engine draws in the context GTK made for it. `present()` queues a render, and the area's render callback copies the engine's frame into the area's framebuffer on the GPU. Present the window first: before it is realized there is no context to lend.

```php
$canvas = $window->column('m')->canvas('view')->fill();
$window->present();
$engine = app('drawing')->renderer('opengl', ['output' => $canvas]);
$engine->frame(fn ($g) => $g->clear(Color::rgb(16, 24, 32))->fillEllipse(160, 120, 40, 40, Color::rgb(255, 128, 0)));
$canvas->present();
```

On Linux with GTK 4.14+ the canvas lends a dmabuf surface to the `vulkan` engine (jovian/venusian-vulkan, with ext-vulkan loaded). Nothing native is swapped in: the engine blits each frame into a linear image it exports as a dmabuf and waits for the copy, and the canvas shows a `GdkDmabufTexture` built over the export in its picture, opaque (imported as `DRM_FORMAT_XBGR8888`, linear). When GTK finalizes a texture it hands its export back to the engine, which never writes an export GTK may still sample. The canvas offers the surface only where its display imports linear XBGR8888 (GTK 4.14+). On the Pi 5 (V3DV, GTK 4.18) the screen follows every present.

```php
$engine = app('drawing')->renderer('vulkan', ['output' => $canvas]);
```

## Human input

The `gtk` input engine: keys, text, pointer and wheel of the GTK windows this package opens. HumanInput starts it when the `gtk` session connects. Pads come from the pad source (`human-input.pads.<os>`), not from GTK.

```php
use Surface\Contracts\HumanInput\Key;
use Surface\HumanInput\MagicAliases\HumanInput;

$keys = HumanInput::keyboard();
if ($keys->isPressed(Key::SPACE)) { $this->jump(); }   // the key in the space bar's place, whatever the layout
$this->name .= $keys->text();                          // typed text, from the keyval
$mouse = HumanInput::mouse();                          // x(), y(), window(): the window name under the pointer
```

* Keys are positions: GTK's hardware keycode, named as SDL names it, so the same key reads the same on the gtk and sdl3 engines. Text is what GTK's input method commits, so dead keys and input methods compose; shortcut chords (Ctrl, Super/Command, and Alt off macOS) type none.
* Mouse buttons 1, 2, 3, 8, 9 are left, middle, right, X1, X2, each read on its own, so a chord releases each. A touchscreen tap is the left button. `x()`/`y()` are in the window's content area. The wheel reads `dy > 0` rolled away from the user. A natural (inverted) scroll is turned back on GTK 4.20+, which reports it, so a trackpad reads as on the sdl3 engine; older GTK reports none, and the scroll reads as delivered.
* Focus leaving for no window of this package, or a window closing, releases every key and button.
* By hand, without the loop: `(new GTKInputEngine($frame, app('toolkit-bridge')->driver('gtk')))->connect()` once the session is connected, then `poll()` once a frame after the session's pump.

## Edit menu and Mac text keys

A profile's edit roles act on the focused widget through GTK's own actions (`text.undo`, `text.redo`, `clipboard.cut`, `clipboard.copy`, `clipboard.paste`, `selection.select-all`), with Select All falling back to a list's `list.select-all`. They take no accelerator: GTK's text widgets already take the standard keys (Command on macOS), and a list keeps its own Select All key.

On macOS, text inputs and text areas also take the Mac's navigation and deletion keys, which GTK 4 binds only for Linux there: Cmd-Left/Right to the line ends, Cmd-Up/Down to the text's ends, Option-Left/Right by words (Shift selects with any of these), Cmd-Backspace to the line start, Option-Backspace and Option-Delete by words. HumanInput's window controller still sees every one of these keys.

## Text areas

`textArea(name, value)` is a GtkTextView in a GtkScrolledWindow. Read-only turns editing off and keeps the caret, so the user can still select and copy. Unwrapped, the pane scrolls sideways as far as its longest line. A font with `monospace` and no family asks CSS for `monospace`. Line backgrounds are paragraph-background tags, range colours foreground and background tags; a new value clears both. Lines and offsets are code points, as GTK counts them. `selectLines()` selects whole lines and `selectedLines()` reads the selection back as lines.

`scrollPosition()` is the adjustments as fractions of the content. `scrollTo()` and `scrollToLine()` set them, and a target within half a pixel of where the pane is leaves it alone. With `watchScroll()` on, a scroll the user makes (scroll bar, wheel, a resize) posts `ViewScrolled` as `view.scrolled.<window>.<path>`, latest only. Hooks post nothing. GTK lays text out on later frames and moves the adjustments as it goes, so a hook that changes the value, wrap, font, colour or tags leaves the area settling (`isSettling()`) until its adjustments hold still for a few frames, or for at most 500 ms; the same input again changes nothing and starts no settle. A scroll asked for while settling is made when the settle ends, the latest one winning. GTK's own moves during a settle post nothing; a user scroll inside one (the value moving while the content and page size hold, or the page size changing in a resize) posts one `ViewScrolled` when the settle ends.

```php
$diff = $window->column('m')->textArea('diff', $text)->fill()->setReadOnly(true)->setWrap(false)
    ->setFont(new FontSpec(12.0, monospace: true))
    ->setLineBackgrounds([3 => Color::hex('#e6ffec')])
    ->watchScroll();
$diff->scrollToLine(3);
```

## Links

`link(name, text, url)` is a GtkLinkButton over the URL with the text: GTK opens the URL when it is clicked, in the browser for http and https, and nothing posts. A disabled link is insensitive. Alignment places the text inside the button.

## Right-click mail

A right click in a window posts mail naming what was clicked: `view.right-clicked.<window>.<path>` for the innermost primitive under the pointer (`x`, `y` in that primitive), or `window.right-clicked.<window>` on bare content (`x`, `y` in the content area). A right click is the right button, Ctrl-click on macOS, or (on Linux) a primary press held still for GTK's long-press time, which is how a touchscreen registered as a pointer right-clicks. A disabled primitive posts nothing.

```php
public function loop(array $mail): void
{
    foreach ($mail as $m) {
        if ($m instanceof \Surface\Contracts\Windows\Mail\View\RightClicked) {
            $this->menuFor($m->path, $m->x, $m->y);
        }
    }
}
```


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

## Menu state and accelerators

`$window->setItemEnabled('view.refresh', false)` disables a node of the window's bar; `isItemEnabled()` reads its own state back. The driver's `setItemEnabled()` does the same for the default bar (macOS shows it; a bar built later starts from it). A folder that is disabled disables every node below it; when it is enabled again, each node below it returns to its own state. A node that is not enabled shows as disabled, posts nothing, and its accelerator does nothing. The calls post nothing. `'enabled' => false` in a profile or a context menu makes a node start disabled.

Each node has a `GSimpleAction`; a folder is a `GMenuItem` whose `action` attribute names its own action, from which GTK's menu tracker takes the folder's sensitivity in popovers and in the macOS menu bar. `GtkPopoverMenuBar` does not take it for its top-level entries, so the driver sets the sensitivity of that entry itself.

`'keys' => 'Primary+Shift+F5'` becomes a GTK accelerator through `GTKMenuBar::accelerator()`: Primary is `<Meta>` (Command) on macOS and `<Control>` elsewhere, Meta is `<Meta>` on macOS and `<Super>` elsewhere; keys by their GDK names (`bracketright`, `Page_Down`, `BackSpace`, `Return`, …). GTK runs application accelerators in the window's capture phase, so an accelerator gets its key before the focused widget.

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

## Split and tab views

`split(name, horizontal)` is a GtkBox holding one GtkPaned slot per pane, nested: each pane is placed once, so adding or removing a pane leaves the others, their focus and the other dividers where they are. A hidden pane hides its slot's handle and gives its neighbour the length, and `positions()` / `setPositions()` are the dividers between visible panes as fractions of the split's length. Its first layout shares the length equally unless positions were set before it; positions set while the split is unmapped (a hidden tab page) land when it shows; a pane added later halves the last pane's share. A paned whose children both resize scales its position when its own size changes, so `setPositions()` places each divider against its paned's laid-out start, a frame at a time, until every divider sits within a pixel of its fraction; `isSettling()` is true meanwhile. A user's divider drag, mouse or touch, posts `view.split-moved.<window>.<path>` (`SplitMoved`) at the release, if a divider moved: a legacy controller on the box sees a press on a handle and its release around GtkPaned's own drag. Window resizes and `setPositions()` post nothing.

`tabs(name)` is a GtkNotebook with one page per `addPage()`, its tab a GtkLabel with the title. A user's tab switch posts `view.tab-selected.<window>.<path>` (`TabSelected`); `select()`, adding the first page, removing or hiding the selected one post nothing.

```php
$split = $window->column('main')->split('panes')->fill();
$split->column('left')->textArea('a', $left)->fill();
$split->column('right')->textArea('b', $right)->fill();
$split->setPositions([0.5]);

$tabs = $window->column('main')->tabs('sessions')->fill();
$tabs->addPage('one', 'left.txt ↔ right.txt')->label('hint', 'Drop files here');
```

## Trees

`tree(name, columns, nodes)` is a GtkColumnView in a GtkScrolledWindow, over a GtkSingleSelection of a GtkTreeListModel (no passthrough, no autoexpand). The model's roots, and each node's children, are GtkStringLists of node ids; a node's list is made when its row first opens. The first column wraps its label in a GtkTreeExpander. A cell's text colour is a class from one CSS provider per tree, and the class is swapped on the recycled label as it is bound.

GTK drops a collapsed node's rows, so the expansion recorded in Surface is re-applied below a row that opens. These post mail, but only for the user's actions:

- a user's selection: `view.node-selected.<window>.<path>` (`NodeSelected`), and only when the selected node changed;
- a user's expand or collapse of a row: `view.node-expanded.<window>.<path>` (`NodeExpanded`);
- the column view's `activate` (double click, or Enter): `view.node-activated.<window>.<path>` (`NodeActivated`).

`expand()`, `selectNode()`, `setNodes()` and GTK tearing down the rows under a collapsed node post nothing.

```php
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\NutsAndBolts\Color;

$tree = $window->column('main')->tree('files', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], [
    new TreeNode('src', ['name' => 'src'], [new TreeNode('src/a.php', ['name' => 'a.php', 'size' => '2 KB'], colors: ['name' => Color::rgb(200, 0, 0)])]),
])->fill();
$tree->expand('src')->selectNode('src/a.php');
```

## Child windows, close confirmation, button roles

`driver()->open('goto', 300, 120, null, $main)` opens a child: transient for `$main` and modal to it. `$main` takes no input until the child closes; other windows still do. `$main->close()` closes the child first. On macOS, while a child is active, the menu bar shows the default bar with every item but the edit roles disabled.

`$window->confirmClose()` keeps the window open on its close button or Alt+F4 and posts `WindowCloseRequested`; `close()` still closes it.

`$button->setRole(ButtonRole::DEFAULT)` makes Return and keypad Enter click the button, wherever the focus is, except in a text area or an open popover; `ButtonRole::CANCEL` does the same for Esc. GTK clicks the button 250 ms after the key, with its pressed look. The default button is the window's default widget. Esc in a child with no cancel button closes it (or posts `WindowCloseRequested` while it confirms close).

## Focus and window keys

`$input->focus()` gives the input keyboard focus through `gtk_widget_grab_focus` and posts nothing; `hasFocus()` and `$window->focused()` read it back. A text area, table or tree focuses its inner view. `$window->setInitialFocus($input)` focuses it on each `present()`, which posts no focus mail either (GTK's own first focus included). Code that hides or disables the focused widget posts nothing; a hide passes focus to the next widget, as Tab would move it. The user's moves, by click, Tab or GTK, post `FocusLost` then `FocusGained`, from the window's `notify::focus-widget`.

A canvas takes no focus until `$canvas->setFocusable()`; then a press on it takes focus, and Tab moves on. `setFocusable(true, takesTab: true)` keeps Tab and Shift-Tab on it (an editor pane).

A key that nothing took (the focused widget, the window's shortcuts, the default and cancel buttons) reaches a bubble-phase key controller on the window and posts `WindowKeyPressed` with the physical key, the layout's text (`''` for a function key) and the modifiers. Tab and Shift-Tab go on, so GTK moves focus. GTK gives no repeat flag: `repeat` is false.

```php
$window->setInitialFocus($window->view('m.path'));
$window->present();
// loop($mail): WindowKeyPressed('main', Key::F5, '', new Modifiers(), false) when F5 is pressed outside a text input
```

## Dialogs, clipboard, desktop

```php
$id = driver()->dialogs()->alert($main, new Alert('Save changes?', 'Two files', ['Save', 'Discard', 'Cancel']));
// loop($mail): AlertAnswered($id, 0 | 1 | 2) on dialog.alert.<id>; -1 when $main closed first
$id = driver()->dialogs()->openFiles($main, new OpenRequest('Compare', getcwd(), multiple: true));
$id = driver()->dialogs()->saveFile($main, new SaveRequest(name: 'report.txt'));
// loop($mail): FilesChosen($id, [absolute paths]) on dialog.files.<id>; [] when cancelled or $main closed first
driver()->clipboard()->setText('/path/to/file');
driver()->desktop()->open('/path/to/file'); // its default app; InvalidArgumentException when nothing is there
```

`GtkAlertDialog` and `GtkFileDialog`, modal to `$main` (the desktop portal's dialog on Linux, the native panel on macOS). Enter answers the default button, Esc and the dialog's close the cancel button. A parent holds one modal: a second dialog or a child throws `WindowException` while one is open, and the window manager's close does nothing. `$main->close()` answers the open dialog first.

## Links

`link(name, text, url)` is a GtkLinkButton over the URL with the text: GTK opens the URL when it is clicked, in the browser for http and https, and nothing posts. A disabled link is insensitive. Alignment places the text inside the button.

## Canvas pointer mail

A canvas that watches the pointer posts what the user does on it, in points from its top-left:

```php
$board = $window->column('m')->canvas('board')->fill()->watchPointer();
```

* `view.pressed.<window>.<path>` (`x`, `y`, `button`, `clicks`, `modifiers`) when a button goes down on it. That canvas then owns the press: another button posts nothing until it ends, and `clicks` counts presses of the same button within GTK's `gtk-double-click-time` and `gtk-double-click-distance`, so a triple click is 3.
* `view.dragged.<window>.<path>` for each move that changed the point while the button is held, outside the canvas and the window too (the coordinates go negative or past its size).
* `view.released.<window>.<path>` exactly once per press: at the button's release, or at the last point posted when a context menu opens for it, the grab breaks or the touch is cancelled. A press the code ends — `watchPointer(false)`, disabling the canvas or a group above it, removing it, closing the window — posts nothing, as no mail comes from code.
* `view.wheeled.<window>.<path>` (`dx`, `dy`, `precise`, `modifiers`) for the wheel over it, which the canvas keeps: a scroll view around it does not scroll. `precise` is true for trackpad points, false for notches (one notch is 1.0). While it is not watching, the wheel scrolls that view as before and nothing posts.

A finger on a touchscreen registered as a pointer (the Pi's) is the left button. `view.clicked` and the right-click rules post as they do otherwise, watching or not.

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


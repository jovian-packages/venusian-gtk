# jovian/venusian-gtk

GTK4 composition for Venusian Surface on Linux. Installing it publishes the
Linux session behind `linux.bridge` and the input engine behind `input.gtk`.

```
ext-gtk  →  jovian/gtk  →  venusian-gtk  →  Surface
1:1 binding  typed projection  composition   cross-platform
```

## Install

```bash
composer require jovian/venusian-gtk
```

Requires Linux, GTK 4.18+, PHP 8.4+ and `ext-gtk` 0.8.0. Gamepads also need
`microscrap/scrapyard-evdev` and the running user in the `input` group.

## Input

`input.gtk` reads the keyboard and mouse for native windows through GTK
event controllers, and gamepads straight from `/dev/input/event*` over evdev
(id = node name, e.g. `event7`). Signal handlers only buffer; each poll
applies the buffer, so a sketch reads settled state.

- Keys are layout-independent (evdev codes). Modifiers follow the held
  modifier keys; GTK's own state is from before the key.
- Focus loss releases every held key and mouse button (with release edges).
- `mouse()->wheel()`: wheel in lines, `dy > 0` = away. GTK does not report
  natural scrolling, so with it on the sign is reversed.
- Pads are rescanned about once a second (sysfs, no device opens). Unplug or
  Bluetooth off gives `input.gamepad.disconnected.<id>` mail.
- Failures (a window that will not attach, an unreadable node) are kept for
  `errors()` and logged; the engine keeps running.

```php
use Surface\HumanInput\MagicAliases\HumanInput;

$gtk = HumanInput::engine('gtk');          // or INPUT_ENGINE=gtk
$gtk->mouse()->window();                   // window under the pointer, or null
foreach ($gtk->gameControllers() as $id => $pad) {   // 'event7'
    $pad->rightTrigger();
}
```

See [`.okf/`](.okf/index.md) for the knowledge bundle.

## License

MIT.

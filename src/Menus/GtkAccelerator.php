<?php

namespace Jovian\Toolkits\GTK\Menus;

use Surface\Toolkits\Menus\Shortcut;
use Surface\Contracts\Toolkits\Menus\ShortcutKey;

/**
 * A Shortcut as GTK has it: an accelerator string for gtk_accelerator_parse().
 *
 * @internal
 */
final class GtkAccelerator
{
    /** GDK's name of each key that is not its own lower-case character. */
    private const array NAMES = [
        '-' => 'minus', '=' => 'equal', '[' => 'bracketleft', ']' => 'bracketright', '\\' => 'backslash',
        ';' => 'semicolon', "'" => 'apostrophe', '`' => 'grave', ',' => 'comma', '.' => 'period', '/' => 'slash',
        'Up' => 'Up', 'Down' => 'Down', 'Left' => 'Left', 'Right' => 'Right',
        'Home' => 'Home', 'End' => 'End', 'PageUp' => 'Page_Up', 'PageDown' => 'Page_Down',
        'Space' => 'space', 'Enter' => 'Return', 'Tab' => 'Tab', 'Escape' => 'Escape',
        'Backspace' => 'BackSpace', 'Delete' => 'Delete',
    ];

    /**
     * Primary is Command on macOS, which GDK names Meta, and Control elsewhere; Ctrl is Control.
     */
    public static function accelerator(Shortcut $shortcut, bool $mac): string
    {
        $command = $mac && $shortcut->primary;
        $control = $shortcut->ctrl || (! $mac && $shortcut->primary);

        return ($command ? '<Meta>' : '').($control ? '<Control>' : '').($shortcut->alt ? '<Alt>' : '').($shortcut->shift ? '<Shift>' : '')
            .self::key($shortcut->key);
    }

    private static function key(ShortcutKey $key): string
    {
        return self::NAMES[$key->value] ?? (strlen($key->value) === 1 ? strtolower($key->value) : $key->value);    // F1 to F12
    }
}

<?php

namespace Jovian\Toolkits\GTK\Windows;

use Closure;
use GtkEventControllerKey;
use GtkPropagationPhase;
use GtkWidget;

/**
 * The Mac's text navigation and deletion keys for GTK text widgets on macOS, where GTK 4 binds
 * its Linux ones (measured 2026-10-10: Cmd-Left does nothing in a GtkEntry; Cmd-A/C/V/X work).
 * A capture-phase key controller on the text input's or area's widget emits GtkText's or
 * GtkTextView's own keybinding signals, move-cursor and delete-from-cursor, and stops the key.
 * Capture runs ancestors first, so a window's controllers (HumanInput's) still see every key.
 */
final class GTKMacTextKeys
{
    public const int LEFT = 0xff51;

    public const int UP = 0xff52;

    public const int RIGHT = 0xff53;

    public const int DOWN = 0xff54;

    public const int BACKSPACE = 0xff08;

    public const int DELETE = 0xffff;

    /** GtkMovementStep */
    private const int WORDS = 2;

    private const int DISPLAY_LINE_ENDS = 4;

    private const int BUFFER_ENDS = 8;

    /** GtkDeleteType */
    private const int WORD_ENDS = 1;

    private const int PARAGRAPH_ENDS = 5;

    /**
     * The signal and arguments a key binds: Command (GDK's Meta, or Super) with the arrows moves to
     * line and document ends, Option (Alt) with them by words, Shift extending the selection;
     * Command-Backspace deletes to the line start, Option-Backspace and Option-Delete a word.
     * Caps Lock is ignored; any other modifier mix binds nothing.
     *
     * @return array{string, list<int|bool>}|null
     */
    public static function binding(int $keyval, int $state): ?array
    {
        $mods = $state & (GDK_SHIFT_MASK | GDK_CONTROL_MASK | GDK_ALT_MASK | GDK_META_MASK | GDK_SUPER_MASK);
        $shift = ($mods & GDK_SHIFT_MASK) !== 0;
        $mods &= ~GDK_SHIFT_MASK;
        $command = $mods !== 0 && ($mods & ~(GDK_META_MASK | GDK_SUPER_MASK)) === 0;
        $option = $mods === GDK_ALT_MASK;

        return match (true) {
            $command && $keyval === self::LEFT => ['move-cursor', [self::DISPLAY_LINE_ENDS, -1, $shift]],
            $command && $keyval === self::RIGHT => ['move-cursor', [self::DISPLAY_LINE_ENDS, 1, $shift]],
            $command && $keyval === self::UP => ['move-cursor', [self::BUFFER_ENDS, -1, $shift]],
            $command && $keyval === self::DOWN => ['move-cursor', [self::BUFFER_ENDS, 1, $shift]],
            $option && $keyval === self::LEFT => ['move-cursor', [self::WORDS, -1, $shift]],
            $option && $keyval === self::RIGHT => ['move-cursor', [self::WORDS, 1, $shift]],
            $command && ! $shift && $keyval === self::BACKSPACE => ['delete-from-cursor', [self::PARAGRAPH_ENDS, -1]],
            $option && ! $shift && $keyval === self::BACKSPACE => ['delete-from-cursor', [self::WORD_ENDS, -1]],
            $option && ! $shift && $keyval === self::DELETE => ['delete-from-cursor', [self::WORD_ENDS, 1]],
            default => null,
        };
    }

    /** Emit the key's binding on a GtkText or GtkTextView; whether the key was bound. */
    public static function press(GtkWidget $text, int $keyval, int $state): bool
    {
        $binding = self::binding($keyval, $state);
        if (is_null($binding)) {
            return false;
        }
        [$signal, $args] = $binding;
        g_signal_emit_by_name($text, $signal, ...$args);

        return true;
    }

    /**
     * A capture-phase controller on $widget that presses each key on the text widget $target
     * names (null: let the key through).
     *
     * @param Closure(): ?GtkWidget $target
     */
    public static function attach(GtkWidget $widget, Closure $target): GtkEventControllerKey
    {
        $keys = GtkEventControllerKey::new();
        g_signal_connect($keys, 'key-pressed', function (GtkEventControllerKey $controller, int $keyval, int $keycode, int $state) use ($target): bool {
            $text = $target();

            return ! is_null($text) && self::press($text, $keyval, $state);
        });
        $keys->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $widget->addController($keys);

        return $keys;
    }
}

<?php

declare(strict_types=1);

namespace Jovian\Toolkits\GTK\Primitives;

use GtkEventControllerFocus;
use GtkEventControllerKey;
use GtkIMMulticontext;
use GtkPropagationPhase;
use GtkWidget;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\PrimitiveEventOccurred;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use WeakReference;

/**
 * The keys of a focused canvas: a capture-phase key controller on the canvas's box (GTK runs the
 * window's accelerator and role-key controllers in the capture phase on the root first) posts
 * KeyPressed and EditCommanded from the platform's table, or hands the key to a GtkIMMulticontext
 * whose commit posts TextCommitted; key-released posts KeyReleased. Tab and ISO_Left_Tab are
 * declined when the canvas does not take Tab, so GTK moves focus. An unfocused canvas declines
 * every key. GDK 4 has no repeat flag: repeat is always false.
 */
final class GTKCanvasKeys
{
    private const int TAB = 0xFF09;
    private const int ISO_LEFT_TAB = 0xFE20;
    private const int LEFT = 0xFF51;
    private const int UP = 0xFF52;
    private const int RIGHT = 0xFF53;
    private const int DOWN = 0xFF54;
    private const int HOME = 0xFF50;
    private const int END = 0xFF57;
    private const int PAGE_UP = 0xFF55;
    private const int PAGE_DOWN = 0xFF56;
    private const int BACKSPACE = 0xFF08;
    private const int DELETE = 0xFFFF;
    private const int RETURN = 0xFF0D;
    private const int KP_ENTER = 0xFF8D;
    private const int ESCAPE = 0xFF1B;

    private readonly WeakReference $canvas;

    private readonly GtkEventControllerKey $controller;

    private readonly GtkIMMulticontext $im;

    private readonly GtkEventControllerFocus $focus;

    /** @var array<int, true> Hardware keycodes whose KeyPressed posted and whose KeyReleased has not. */
    private array $held = [];

    /** @var array<string, true> Modifier keys (Key values) down on this canvas. */
    private array $modifiers = [];

    public function __construct(GTKCanvas $canvas, GtkWidget $box)
    {
        $this->canvas = WeakReference::create($canvas);
        // The box holds the controllers' handlers, and the canvas holds the box and this: weak references break the cycles.
        $self = WeakReference::create($this);
        $this->controller = GtkEventControllerKey::new();
        $this->controller->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        g_signal_connect($this->controller, 'key-pressed', static fn (GtkEventControllerKey $controller, int $keyval, int $keycode, int $state): bool => $self->get()?->pressed($keyval, $keycode, $state) ?? false);
        g_signal_connect($this->controller, 'key-released', static fn (GtkEventControllerKey $controller, int $keyval, int $keycode, int $state): bool => $self->get()?->released($keycode, $state) ?? false);
        $box->addController($this->controller);

        $this->im = GtkIMMulticontext::new();
        $this->im->setClientWidget($box);
        g_signal_connect($this->im, 'commit', static function (GtkIMMulticontext $im, string $text) use ($self): void {
            $self->get()?->committed($text);
        });

        $this->focus = GtkEventControllerFocus::new();
        g_signal_connect($this->focus, 'enter', static function () use ($self): void {
            $self->get()?->im->focusIn();
        });
        g_signal_connect($this->focus, 'leave', static function () use ($self): void {
            $keys = $self->get();
            if (! is_null($keys)) {
                $keys->im->focusOut();
                $keys->im->reset();
            }
        });
        $box->addController($this->focus);
    }

    public function controller(): GtkEventControllerKey
    {
        return $this->controller;
    }

    public function imContext(): GtkIMMulticontext
    {
        return $this->im;
    }

    private function pressed(int $keyval, int $keycode, int $state): bool
    {
        $canvas = $this->canvas();
        if (is_null($canvas)) {
            return false;
        }
        if (($keyval === self::TAB || $keyval === self::ISO_LEFT_TAB) && ! $canvas->takesTab()) {
            return false;
        }
        $code = gdk_keyval_to_unicode($keyval);
        $character = $code >= 0x20 && $code !== 0x7F ? mb_chr($code, 'UTF-8') : '';
        $key = GTKInputEngine::keyOf($keycode);
        $modifiers = $this->modifiersAfter($key, true, $state);
        $this->held[$keycode] = true;
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyPressed => new KeyPressed(
            $window, $path, $uuid, $key, $character, $modifiers, false,
        ));
        $command = self::command($keyval, $state);
        if (! is_null($command)) {
            $this->post($canvas, static fn (string $window, string $path, string $uuid): EditCommanded => new EditCommanded($window, $path, $uuid, $command));

            return true;
        }
        // A synthetic emit has no current event: nothing for the input method.
        $event = $this->controller->getCurrentEvent();
        if (! is_null($event)) {
            $this->im->filterKeypress($event);
        }

        return true;
    }

    private function released(int $keycode, int $state): bool
    {
        $canvas = $this->canvas();
        // Only the release of a key whose press posted: one let through (Tab to move focus) is not the canvas's.
        if (is_null($canvas) || ! isset($this->held[$keycode])) {
            return false;
        }
        unset($this->held[$keycode]);
        $key = GTKInputEngine::keyOf($keycode);
        $modifiers = $this->modifiersAfter($key, false, $state);
        $this->post($canvas, static fn (string $window, string $path, string $uuid): KeyReleased => new KeyReleased(
            $window, $path, $uuid, $key, $modifiers,
        ));

        return true;
    }

    /** GDK's state is from before the key: a modifier key's own press sets its modifier, its release clears it unless the other side is down. */
    private function modifiersAfter(Key $key, bool $down, int $state): Modifiers
    {
        $modifiers = (array) GTKInputEngine::modifiers($state);
        foreach (GTKInputEngine::MODIFIER_KEYS as $name => $sides) {
            if (in_array($key, $sides, true)) {
                if ($down) {
                    $this->modifiers[$key->value] = true;
                } else {
                    unset($this->modifiers[$key->value]);
                }
                $modifiers[$name] = array_any($sides, fn (Key $side): bool => isset($this->modifiers[$side->value]));
            }
        }

        return new Modifiers(...$modifiers);
    }

    private function committed(string $text): void
    {
        $canvas = $this->canvas();
        if (! is_null($canvas)) {
            $this->post($canvas, static fn (string $window, string $path, string $uuid): TextCommitted => new TextCommitted($window, $path, $uuid, $text));
        }
    }

    /**
     * The platform's binding for the key, or null. Shift turns a move into its select twin. Linux:
     * Ctrl for words, document ends, word deletes and the edit chords. macOS: Command for line and
     * document ends and the edit chords, Option for words and word deletes, Ctrl+K and Ctrl+Y.
     */
    public static function command(int $keyval, int $state, bool $macos = PHP_OS_FAMILY === 'Darwin'): ?EditCommand
    {
        $mods = $state & (GDK_SHIFT_MASK | GDK_CONTROL_MASK | GDK_ALT_MASK | GDK_META_MASK | GDK_SUPER_MASK);
        $shift = ($mods & GDK_SHIFT_MASK) !== 0;
        $rest = $mods & ~GDK_SHIFT_MASK;
        $plain = $rest === 0;
        $ctrl = $rest === GDK_CONTROL_MASK;
        $option = $rest === GDK_ALT_MASK;
        $command = $rest !== 0 && ($rest & ~(GDK_META_MASK | GDK_SUPER_MASK)) === 0;
        $chord = $macos ? $command : $ctrl;
        $word = $macos ? $option : $ctrl;
        $lineEnd = $macos && $command;
        $documentEnd = $macos ? $command : $ctrl;

        $move = match (true) {
            $plain && $keyval === self::LEFT => [EditCommand::MoveLeft, EditCommand::SelectLeft],
            $plain && $keyval === self::RIGHT => [EditCommand::MoveRight, EditCommand::SelectRight],
            $plain && $keyval === self::UP => [EditCommand::MoveUp, EditCommand::SelectUp],
            $plain && $keyval === self::DOWN => [EditCommand::MoveDown, EditCommand::SelectDown],
            $word && $keyval === self::LEFT => [EditCommand::MoveWordLeft, EditCommand::SelectWordLeft],
            $word && $keyval === self::RIGHT => [EditCommand::MoveWordRight, EditCommand::SelectWordRight],
            ($plain && $keyval === self::HOME) || ($lineEnd && $keyval === self::LEFT) => [EditCommand::MoveToLineStart, EditCommand::SelectToLineStart],
            ($plain && $keyval === self::END) || ($lineEnd && $keyval === self::RIGHT) => [EditCommand::MoveToLineEnd, EditCommand::SelectToLineEnd],
            $documentEnd && $keyval === ($macos ? self::UP : self::HOME) => [EditCommand::MoveToDocumentStart, EditCommand::SelectToDocumentStart],
            $documentEnd && $keyval === ($macos ? self::DOWN : self::END) => [EditCommand::MoveToDocumentEnd, EditCommand::SelectToDocumentEnd],
            $plain && $keyval === self::PAGE_UP => [EditCommand::PageUp, EditCommand::SelectPageUp],
            $plain && $keyval === self::PAGE_DOWN => [EditCommand::PageDown, EditCommand::SelectPageDown],
            default => null,
        };
        if (! is_null($move)) {
            return $move[$shift ? 1 : 0];
        }
        if ($shift && ! ($chord && ($keyval === 0x7A || $keyval === 0x5A)) && $keyval !== self::ISO_LEFT_TAB) {
            // Shift with a non-move key: only Cmd/Ctrl+Shift+Z and Shift-Tab are bound.
            return null;
        }

        return match (true) {
            $plain && $keyval === self::BACKSPACE => EditCommand::DeleteBackward,
            $plain && $keyval === self::DELETE => EditCommand::DeleteForward,
            $word && $keyval === self::BACKSPACE => EditCommand::DeleteWordBackward,
            $word && $keyval === self::DELETE => EditCommand::DeleteWordForward,
            $macos && $ctrl && ($keyval === 0x6B || $keyval === 0x4B) => EditCommand::DeleteToLineEnd,
            $macos && $ctrl && ($keyval === 0x79 || $keyval === 0x59) => EditCommand::Yank,
            $chord && ($keyval === 0x61 || $keyval === 0x41) => EditCommand::SelectAll,
            $chord && ($keyval === 0x78 || $keyval === 0x58) => EditCommand::Cut,
            $chord && ($keyval === 0x63 || $keyval === 0x43) => EditCommand::Copy,
            $chord && ($keyval === 0x76 || $keyval === 0x56) => EditCommand::Paste,
            $chord && ($keyval === 0x7A || $keyval === 0x5A) => $shift ? EditCommand::Redo : EditCommand::Undo,
            $plain && ($keyval === self::RETURN || $keyval === self::KP_ENTER) => EditCommand::InsertNewline,
            $plain && $keyval === self::TAB => EditCommand::InsertTab,
            $keyval === self::ISO_LEFT_TAB && $rest === 0 => EditCommand::InsertBacktab,
            $plain && $keyval === self::ESCAPE => EditCommand::Cancel,
            default => null,
        };
    }

    /** The canvas while it is placed, focused and in an open window. */
    private function canvas(): ?GTKCanvas
    {
        $canvas = $this->canvas->get();
        if (! $canvas instanceof GTKCanvas || $canvas->isRemoved() || ! $canvas->hasFocus()) {
            return null;
        }
        $window = $canvas->window();

        return $window instanceof GTKWindow && $window->isOpen() ? $canvas : null;
    }

    /** @param \Closure(string, string, string): PrimitiveEventOccurred $mail */
    private function post(GTKCanvas $canvas, \Closure $mail): void
    {
        /** @var GTKWindow $window */
        $window = $canvas->window();
        $window->session()->post($mail($window->name(), $canvas->path(), $canvas->uuid()));
    }
}

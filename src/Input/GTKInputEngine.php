<?php

namespace Jovian\Toolkits\GTK\Input;

use Closure;
use GtkApplicationWindow;
use GtkEventController;
use GtkEventControllerFocus;
use GtkEventControllerKey;
use GtkEventControllerMotion;
use GtkEventControllerScroll;
use GtkGestureClick;
use GtkGestureSingle;
use GtkPropagationPhase;
use GdkScrollEvent;
use GdkScrollRelativeDirection;
use GdkScrollUnit;
use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\HumanInput\HumanInputException;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\InputTap;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;
use Surface\HumanInput\InputFrame;
use Surface\HumanInput\Keymaps\LinuxKeycodes;
use Surface\HumanInput\Keymaps\MacKeycodes;

/**
 * HumanInput's gtk engine: keyboard and pointer of the GTK driver's windows. GTK 4 has no
 * application-wide event hook, so each poll puts five capture-phase controllers on every window
 * the driver holds that has none yet; their signals copy their arguments into a SeenEvent and
 * show it to the session's taps, this engine among them, and poll() applies what was seen.
 * Keys are read by position (the hardware keycode), as the sdl3 engine reads scancodes; the
 * keyval only types text. Focus leaving for no window of the driver, or a window closing, releases
 * every key and button.
 */
final class GTKInputEngine implements InputEngineDriver, InputTap
{
    /** GDK_KEY_VoidSymbol: macOS GTK follows every key press with one of these at keycode 0, never released. */
    private const int VOID_SYMBOL = 0xFFFFFF;

    /** Each modifier's keys, both sides. */
    private const array MODIFIER_KEYS = [
        'shift' => [Key::LEFT_SHIFT, Key::RIGHT_SHIFT],
        'ctrl' => [Key::LEFT_CTRL, Key::RIGHT_CTRL],
        'alt' => [Key::LEFT_ALT, Key::RIGHT_ALT],
        'meta' => [Key::LEFT_META, Key::RIGHT_META],
    ];

    private bool $connected = false;

    private readonly Keyboard $keyboard;

    private readonly Mouse $mouse;

    private ?GTKSession $session = null;

    /** @var array<string, array{GtkApplicationWindow, list<GtkEventController>, int}> by window name: native, controllers, is-active handler */
    private array $wired = [];

    /** @var list<SeenEvent> shown since the last poll */
    private array $seen = [];

    /** @var array<string, array{float, float}> where the pointer last was, by window */
    private array $last = [];

    private ?string $over = null;

    /** @var array<string, true> modifier keys held, by Key value */
    private array $held_modifiers = [];

    /** @var Closure(GtkGestureSingle): int */
    private readonly Closure $button;

    /** @var Closure(): bool */
    private readonly Closure $active;

    /** @var Closure(GtkEventControllerScroll): bool */
    private readonly Closure $inverted;

    /**
     * @param (Closure(GtkGestureSingle): int)|null $button the button of a click; getCurrentButton() by default
     * @param (Closure(): bool)|null $active whether any of the driver's windows is active; asked of each window by default
     * @param (Closure(GtkEventControllerScroll): bool)|null $inverted whether the scroll being handled is inverted from the physical motion
     *        (natural scrolling); GdkScrollEvent::getRelativeDirection() by default, which GTK 4.20+ has
     */
    public function __construct(InputFrame $frame, private readonly GTKBridgeDriver $driver, ?Closure $button = null, ?Closure $active = null, ?Closure $inverted = null)
    {
        $this->keyboard = new Keyboard($frame);
        $this->mouse = new Mouse($frame);
        $this->button = $button ?? static fn (GtkGestureSingle $gesture): int => $gesture->getCurrentButton();
        $this->active = $active ?? fn (): bool => array_any($this->driver->all(), fn (GTKWindow $window): bool => $window->isActive());
        $this->inverted = $inverted ?? static function (GtkEventControllerScroll $scroll): bool {
            $event = $scroll->getCurrentEvent();

            return $event instanceof GdkScrollEvent && method_exists($event, 'getRelativeDirection')
                && $event->getRelativeDirection() === GdkScrollRelativeDirection::INVERTED;
        };
    }

    /** @throws HumanInputException The driver has no connected session to tap. */
    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $session = $this->driver->session();
        if (! $session instanceof GTKSession || ! $session->connected()) {
            throw new HumanInputException('gtk: no connected GTK session to read input from; HumanInput starts this engine when the bridge connects one.');
        }

        $session->tap($this);
        [$this->session, $this->connected] = [$session, true];

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $open = $this->driver->all();
        foreach ($this->wired as $name => [$native, $controllers, $handler]) {
            if (isset($open[$name]) && $open[$name]->native() === $native) {
                foreach ($controllers as $controller) {
                    $native->removeController($controller);
                }
                g_signal_handler_disconnect($native, $handler);
            }
        }
        $this->session?->untap($this);
        [$this->wired, $this->seen, $this->last, $this->over, $this->held_modifiers, $this->session, $this->connected] = [[], [], [], null, [], null, false];
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function see(object $native_event): void
    {
        if ($native_event instanceof SeenEvent) {
            $this->seen[] = $native_event;
        }
    }

    public function settle(): void
    {
        $this->keyboard->settle();
        $this->mouse->settle();
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $closed = $this->wire();
        [$seen, $this->seen] = [$this->seen, []];
        $focus_lost = false;
        foreach ($seen as $event) {
            match ($event->kind) {
                SeenKind::Key => $this->key($event),
                SeenKind::Motion, SeenKind::Enter => $this->pointer($event),
                SeenKind::Leave => $this->leave($event->window),
                SeenKind::Button => $this->click($event),
                SeenKind::Wheel => $this->mouse->addWheel(...self::wheel($event->x, $event->y, $event->pixels, $event->inverted)),
                SeenKind::FocusLost => $focus_lost = true,
            };
        }

        if (($focus_lost || $closed) && ! ($this->active)()) {
            $this->keyboard->releaseAll();
            $this->mouse->releaseAll();
            $this->held_modifiers = [];
        }
    }

    public function keyboard(): Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): Mouse
    {
        return $this->mouse;
    }

    /** @return list<GtkEventController> the controllers this engine put on $window, none once it closed */
    public function controllersOf(string $window): array
    {
        return $this->wired[$window][1] ?? [];
    }

    /** A hardware keycode as the key at that position: the macOS key code, or the evdev code + 8 (X11, Wayland). */
    public static function keyOf(int $keycode, bool $macos = PHP_OS_FAMILY === 'Darwin'): Key
    {
        return $macos ? MacKeycodes::key($keycode) : LinuxKeycodes::key($keycode - 8);
    }

    /**
     * The text a key press types: none for control characters and for shortcut chords (Ctrl,
     * Super, Meta; Alt too, except on macOS, where Option types characters).
     */
    public static function text(int $code_point, int $state, bool $macos = PHP_OS_FAMILY === 'Darwin'): string
    {
        $chord = GDK_CONTROL_MASK | GDK_SUPER_MASK | GDK_META_MASK | ($macos ? 0 : GDK_ALT_MASK);
        if (($state & $chord) !== 0 || $code_point <= 0x1F || $code_point === 0x7F) {
            return '';
        }

        return (string) mb_chr($code_point, 'UTF-8');
    }

    /** GDK modifier state as Surface modifiers: Super (Linux) and Meta (Command on macOS) are meta. */
    public static function modifiers(int $state): Modifiers
    {
        return new Modifiers(
            shift: ($state & GDK_SHIFT_MASK) !== 0,
            ctrl: ($state & GDK_CONTROL_MASK) !== 0,
            alt: ($state & GDK_ALT_MASK) !== 0,
            meta: ($state & (GDK_SUPER_MASK | GDK_META_MASK)) !== 0,
        );
    }

    public static function buttonOf(int $button): ?MouseButton
    {
        return match ($button) {
            1 => MouseButton::LEFT,
            2 => MouseButton::MIDDLE,
            3 => MouseButton::RIGHT,
            8 => MouseButton::X1,
            9 => MouseButton::X2,
            default => null,
        };
    }

    /**
     * A scroll as wheel lines, dy > 0 rolled away from the user and dx > 0 to the right: GTK's
     * vertical axis is down-positive, its horizontal one right-positive as SDL's. Pixel deltas
     * (GdkScrollUnit::SURFACE) are ten to a line. An inverted ("natural") scroll is turned back,
     * so it reads as the fingers moved, as the sdl3 engine reads a flipped wheel.
     *
     * @return array{float, float}
     */
    public static function wheel(float $dx, float $dy, bool $pixels, bool $inverted = false): array
    {
        $scale = ($pixels ? 10.0 : 1.0) * ($inverted ? -1.0 : 1.0);

        return [$dx / $scale, -$dy / $scale];
    }

    /** Wire windows the driver holds without controllers; forget closed ones. Whether one closed. */
    private function wire(): bool
    {
        $open = $this->driver->all();
        $closed = false;
        foreach (array_keys(array_diff_key($this->wired, $open)) as $name) {
            unset($this->wired[$name], $this->last[$name]);
            $this->over = $this->over === $name ? null : $this->over;
            $closed = true;
        }
        foreach ($open as $name => $window) {
            $native = $window->native();
            if (($this->wired[$name][0] ?? null) === $native) {
                continue;
            }
            $closed = $closed || isset($this->wired[$name]);
            $this->wired[$name] = $this->attach($name, $native);
        }

        return $closed;
    }

    /** @return array{GtkApplicationWindow, list<GtkEventController>, int} */
    private function attach(string $name, GtkApplicationWindow $native): array
    {
        $see = fn (SeenEvent $event) => $this->session?->see($event);

        $key = GtkEventControllerKey::new();
        g_signal_connect($key, 'key-pressed', function (GtkEventControllerKey $c, int $keyval, int $keycode, int $state) use ($see, $name): bool {
            $see(new SeenEvent(SeenKind::Key, $name, keyval: $keyval, keycode: $keycode, state: $state, down: true));

            return false;
        });
        g_signal_connect($key, 'key-released', function (GtkEventControllerKey $c, int $keyval, int $keycode, int $state) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Key, $name, keyval: $keyval, keycode: $keycode, state: $state));
        });

        $motion = GtkEventControllerMotion::new();
        g_signal_connect($motion, 'enter', function (GtkEventControllerMotion $c, float $x, float $y) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Enter, $name, x: $x, y: $y));
        });
        g_signal_connect($motion, 'motion', function (GtkEventControllerMotion $c, float $x, float $y) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Motion, $name, x: $x, y: $y));
        });
        g_signal_connect($motion, 'leave', function (GtkEventControllerMotion $c) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Leave, $name));
        });

        $scroll = GtkEventControllerScroll::new(GTK_EVENT_CONTROLLER_SCROLL_BOTH_AXES);
        g_signal_connect($scroll, 'scroll', function (GtkEventControllerScroll $c, float $dx, float $dy) use ($see, $name): bool {
            $see(new SeenEvent(SeenKind::Wheel, $name, x: $dx, y: $dy, pixels: $c->getUnit() === GdkScrollUnit::SURFACE, inverted: ($this->inverted)($c)));

            return false;
        });

        $click = GtkGestureClick::new();
        $click->setButton(0);
        g_signal_connect($click, 'pressed', function (GtkGestureClick $c, int $n, float $x, float $y) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Button, $name, x: $x, y: $y, button: ($this->button)($c), down: true));
        });
        g_signal_connect($click, 'released', function (GtkGestureClick $c, int $n, float $x, float $y) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::Button, $name, x: $x, y: $y, button: ($this->button)($c)));
        });

        $focus = GtkEventControllerFocus::new();
        g_signal_connect($focus, 'leave', function (GtkEventControllerFocus $c) use ($see, $name): void {
            $see(new SeenEvent(SeenKind::FocusLost, $name));
        });

        $controllers = [$key, $motion, $scroll, $click, $focus];
        foreach ($controllers as $controller) {
            $controller->setPropagationPhase(GtkPropagationPhase::CAPTURE);
            $native->addController($controller);
        }
        $handler = g_signal_connect($native, 'notify::is-active', function (GtkApplicationWindow $window) use ($see, $name): void {
            if (! $window->isActive()) {
                $see(new SeenEvent(SeenKind::FocusLost, $name));
            }
        });

        return [$native, $controllers, $handler];
    }

    private function key(SeenEvent $event): void
    {
        if ($event->keyval === self::VOID_SYMBOL) {
            return;
        }
        $key = self::keyOf($event->keycode);
        $this->keyboard->update($key, $event->down)->setModifiers($this->modifiersAfter($key, $event->down, $event->state));
        if ($event->down) {
            $this->keyboard->appendText(self::text(gdk_keyval_to_unicode($event->keyval), $event->state));
        }
    }

    /**
     * The modifiers after a key event: the event's state, which GTK gives as it was before the
     * event, with the event's own modifier key applied (either side of it still held keeps it).
     */
    private function modifiersAfter(Key $key, bool $down, int $state): Modifiers
    {
        $modifiers = (array) self::modifiers($state);
        foreach (self::MODIFIER_KEYS as $name => $keys) {
            if (in_array($key, $keys, true)) {
                if ($down) {
                    $this->held_modifiers[$key->value] = true;
                } else {
                    unset($this->held_modifiers[$key->value]);
                }
                $modifiers[$name] = array_any($keys, fn (Key $side): bool => isset($this->held_modifiers[$side->value]));
            }
        }

        return new Modifiers(...$modifiers);
    }

    private function pointer(SeenEvent $event): void
    {
        [$x0, $y0] = $this->last[$event->window] ?? [$event->x, $event->y];
        $this->mouse->setPosition($event->x, $event->y, $event->window);
        if ($event->kind === SeenKind::Motion) {
            $this->mouse->addMotion($event->x - $x0, $event->y - $y0);
        }
        $this->last[$event->window] = [$event->x, $event->y];
        $this->over = $event->window;
    }

    private function leave(string $window): void
    {
        if ($this->over !== $window) {
            return;
        }
        [$x, $y] = $this->last[$window] ?? [0.0, 0.0];
        $this->mouse->setPosition($x, $y, null);
        $this->over = null;
    }

    private function click(SeenEvent $event): void
    {
        $button = self::buttonOf($event->button);
        if (is_null($button)) {
            return;
        }
        $this->mouse->setPosition($event->x, $event->y, $event->window)->update($button, $event->down);
        $this->last[$event->window] = [$event->x, $event->y];
        $this->over = $event->window;
    }
}

<?php

namespace Jovian\Venusian\GTK\Input;

use Closure;
use Jovian\Bindings\Gtk\Enums\GdkModifierType;
use Jovian\Bindings\Gtk\Enums\GdkScrollUnit;
use Surface\Contracts\HumanInput\Circuits\GameController as ControllerCircuit;
use Surface\Contracts\HumanInput\InputEngine;
use Surface\Contracts\HumanInput\InputEngineDriver;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Surface\HumanInput\Devices\GameController;
use Surface\HumanInput\Devices\GamePad;
use Surface\HumanInput\Devices\Keyboard;
use Surface\HumanInput\Devices\Mouse;
use Surface\HumanInput\ICInput;
use Throwable;

/**
 * The gtk input engine (`input.gtk`). Keys and the pointer come from four
 * GTK event controllers per window, attached lazily in poll(). GTK fires
 * their signals while `os` pumps, before this engine ticks, so each callback
 * only buffers a step; poll() settles the devices, then applies the buffer,
 * so edges land on this tick. Gamepads come from Linux evdev, one ICInput per
 * node, rescanned every $rescan_ticks polls.
 *
 * Nothing here raises a PHP warning or lets a failure out of poll(): a
 * failing window attach, scan or node open is recorded for errors() (and
 * logged when the container binds `log`) and skipped.
 */
final class GTKInputEngine implements InputEngineDriver
{
    private bool $connected = false;

    private ?Keyboard $keyboard = null;

    private ?Mouse $mouse = null;

    /** @var list<Closure(): void> device steps buffered by callbacks, oldest first */
    private array $pending = [];

    /** @var array<string, WindowControllers> window name → its controllers */
    private array $attached = [];

    /** @var array<string, array{float, float}> window name → last pointer sample, for motion deltas */
    private array $last_position = [];

    /** @var array<string, ICInput> node path → pad */
    private array $pads = [];

    private int $polls = 0;

    /** Last `modifiers` signal state. GTK sends it with the state from before a modifier key's own event. */
    private Modifiers $signalled;

    /** @var array<string, true> modifier kinds (shift, ctrl, alt, meta) whose keys this window focus has seen; their keys decide them */
    private array $keyed = [];

    /** @var list<string> messages recorded since the last errors() */
    private array $errors = [];

    /** @var array<string, int> window name → the handle whose attach failed; retried on the rescan cadence */
    private array $attach_failed = [];

    /** Whether this connection already recorded the unreadable-nodes notice. */
    private bool $access_noted = false;

    /** @var Closure(): array<string, bool> input node path → readable */
    private readonly Closure $input_nodes;

    /** @param null|Closure(): array<string, bool> $input_nodes input node path → readable; defaults to /dev/input/event* */
    public function __construct(
        private readonly ControllerFactory $controllers,
        private readonly GtkWindowSpace $windows,
        private readonly PadScanner $scanner,
        private readonly int $rescan_ticks = 60,
        ?Closure $input_nodes = null,
    ) {
        $this->input_nodes = $input_nodes ?? static function (): array {
            $nodes = [];

            foreach (glob('/dev/input/event*') ?: [] as $path) {
                $nodes[$path] = is_readable($path);
            }

            return $nodes;
        };
    }

    public function engine(): InputEngine
    {
        return InputEngine::GTK;
    }

    public function connect(): static
    {
        if ($this->connected) {
            return $this;
        }

        $this->keyboard = new Keyboard();

        $this->signalled = new Modifiers();

        $this->keyed = [];
        $this->mouse = new Mouse();
        $this->polls = 0;
        $this->attach_failed = [];
        $this->access_noted = false;
        $this->connected = true;
        $this->scanPads();

        return $this;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        $live = $this->windows->windows();
        foreach ($this->attached as $name => $controllers) {
            if (($live[$name] ?? null) === $controllers->window_handle) {
                $this->controllers->detach($controllers);
            } else {
                $this->controllers->forget($controllers);
            }
        }

        foreach ($this->pads as $pad) {
            $this->closePad($pad);
        }

        $this->attached = [];
        $this->attach_failed = [];
        $this->last_position = [];
        $this->pending = [];
        $this->pads = [];
        $this->keyboard = null;
        $this->mouse = null;
        $this->connected = false;
    }

    public function connected(): bool
    {
        return $this->connected;
    }

    public function poll(): void
    {
        if (! $this->connected) {
            return;
        }

        $this->keyboard->settle();
        $this->mouse->settle();

        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $step) {
            $step();
        }

        if ($pending !== []) {
            $this->keyboard->setModifiers($this->resolveModifiers());
        }

        $rescan = ++$this->polls % max(1, $this->rescan_ticks) === 0;

        $this->syncWindows($rescan);

        if ($rescan) {
            $this->scanPads();
        }

        foreach ($this->pads as $pad) {
            $pad->poll();
        }
    }

    /**
     * Failures recorded since the last call, oldest first. The call drains them.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        $errors = $this->errors;
        $this->errors = [];

        return $errors;
    }

    public function keyboard(): ?Keyboard
    {
        return $this->keyboard;
    }

    public function mouse(): ?Mouse
    {
        return $this->mouse;
    }

    /** @return array<string, GamePad> connected pads without a stick */
    public function gamePads(): array
    {
        $keyed = [];
        foreach ($this->pads as $pad) {
            $device = $pad->device();
            if (! $device instanceof GameController && $pad->connected()) {
                $keyed[$device->id()] = $device;
            }
        }

        return $keyed;
    }

    /** @return array<string, GameController> connected pads with a stick */
    public function gameControllers(): array
    {
        $keyed = [];
        foreach ($this->pads as $pad) {
            $device = $pad->device();
            if ($device instanceof GameController && $pad->connected()) {
                $keyed[$device->id()] = $device;
            }
        }

        return $keyed;
    }

    /**
     * Attach to windows not seen yet; forget windows that are gone or whose
     * handle changed. A window whose attach failed is retried only when
     * $retry (the rescan cadence) and recorded once per failure streak.
     */
    private function syncWindows(bool $retry): void
    {
        $live = $this->windows->windows();

        foreach ($this->attached as $name => $controllers) {
            if (($live[$name] ?? null) !== $controllers->window_handle) {
                // The window is gone; its controllers died with it and send no leave or release.
                $this->controllers->forget($controllers);
                unset($this->attached[$name], $this->last_position[$name]);
                $this->releasePointer($name);
            }
        }

        foreach ($this->attach_failed as $name => $handle) {
            if (($live[$name] ?? null) !== $handle) {
                // Gone or a new handle: a new streak starts.
                unset($this->attach_failed[$name]);
            }
        }

        foreach ($live as $name => $handle) {
            if (array_key_exists($name, $this->attached)) {
                continue;
            }

            $failing = array_key_exists($name, $this->attach_failed);

            if ($failing && ! $retry) {
                continue;
            }

            try {
                $this->attached[$name] = $this->controllers->attach($name, $handle, $this->callbacks($name));
                unset($this->attach_failed[$name]);
            } catch (Throwable $e) {
                if (! $failing) {
                    $this->record("could not attach controllers to window '{$name}': {$e->getMessage()}");
                }

                $this->attach_failed[$name] = $handle;
            }
        }
    }

    /** A pointer on a window that vanished: no window, and no button held. Position is kept. */
    private function releasePointer(string $window): void
    {
        if ($this->mouse->window() !== $window) {
            return;
        }

        $this->mouse->setPosition($this->mouse->x(), $this->mouse->y(), null);

        foreach (MouseButton::cases() as $button) {
            $this->mouse->update($button, false);
        }
    }

    /** Focus left: every held key and mouse button is released, with release edges, and the modifiers cleared. */
    private function releaseAll(): void
    {
        foreach ($this->keyboard->downKeys() as $key) {
            $this->keyboard->update($key, false);
        }
        $this->signalled = new Modifiers();
        $this->keyed = [];
        $this->keyboard->setModifiers(new Modifiers());

        foreach (MouseButton::cases() as $button) {
            $this->mouse->update($button, false);
        }
    }

    /**
     * One window's signal handlers. Each resolves what only the handler can
     * know (the current button) and buffers the device step for poll().
     *
     * @return array<string, Closure>
     */
    private function callbacks(string $window): array
    {
        return [
            'key_pressed' => function (int $keyval, int $keycode, int $state): bool {
                $this->bufferKey($keycode, true, $this->text($keyval, $state));

                return false;
            },
            'key_released' => function (int $keyval, int $keycode, int $state): void {
                $this->bufferKey($keycode, false, '');
            },
            'modifiers' => function (int $state): bool {
                $modifiers = self::modifiers($state);
                $this->pending[] = function () use ($modifiers): void {
                    $this->signalled = $modifiers;
                };

                return false;
            },
            'motion' => function (float $x, float $y) use ($window): void {
                $this->pending[] = fn () => $this->applyPointer($window, $x, $y, true);
            },
            'enter' => function (float $x, float $y) use ($window): void {
                $this->pending[] = fn () => $this->applyPointer($window, $x, $y, false);
            },
            'leave' => function () use ($window): void {
                $this->pending[] = fn () => $this->applyLeave($window);
            },
            'pressed' => function (int $n_press, float $x, float $y) use ($window): void {
                $this->bufferButton($window, true, $x, $y);
            },
            'released' => function (int $n_press, float $x, float $y) use ($window): void {
                $this->bufferButton($window, false, $x, $y);
            },
            'scroll' => function (float $dx, float $dy, int $unit): bool {
                // GTK is down-positive; Surface is up-positive. SURFACE deltas are pixels; ~10 per line.
                $scale = $unit === GdkScrollUnit::SURFACE->value ? 10.0 : 1.0;
                $this->pending[] = fn () => $this->mouse->addWheel(-$dx / $scale, -$dy / $scale);

                return false;
            },
            'active' => function (bool $active): void {
                if (! $active) {
                    $this->pending[] = fn () => $this->releaseAll();
                }
            },
        ];
    }

    private function bufferKey(int $keycode, bool $down, string $text): void
    {
        // GTK's hardware keycode is the evdev code + 8 on X11 and Wayland.
        $key = EvdevKeyMap::key($keycode - 8);

        $this->pending[] = function () use ($key, $down, $text): void {
            $this->keyboard->update($key, $down);

            $kind = self::MODIFIER_KEYS[$key->value] ?? null;
            if (! is_null($kind)) {
                $this->keyed[$kind] = true;
            }

            if ($text !== '') {
                $this->keyboard->appendText($text);
            }
        };
    }

    private function bufferButton(string $window, bool $down, float $x, float $y): void
    {
        $controllers = $this->attached[$window] ?? null;

        if (is_null($controllers)) {
            return;
        }

        $button = match ($this->controllers->currentButton($controllers)) {
            1 => MouseButton::LEFT,
            2 => MouseButton::MIDDLE,
            3 => MouseButton::RIGHT,
            8 => MouseButton::X1,
            9 => MouseButton::X2,
            default => null,
        };

        if (is_null($button)) {
            return;
        }

        $this->pending[] = fn () => $this->mouse->setPosition($x, $y, $window)->update($button, $down);
    }

    /** A pointer sample. Motion adds the delta against the window's previous sample; the first sample adds none. */
    private function applyPointer(string $window, float $x, float $y, bool $moved): void
    {
        $last = $this->last_position[$window] ?? null;

        if ($moved && ! is_null($last)) {
            $this->mouse->addMotion($x - $last[0], $y - $last[1]);
        }

        $this->last_position[$window] = [$x, $y];
        $this->mouse->setPosition($x, $y, $window);
    }

    private function applyLeave(string $window): void
    {
        unset($this->last_position[$window]);

        if ($this->mouse->window() === $window) {
            $this->mouse->setPosition($this->mouse->x(), $this->mouse->y(), null);
        }
    }

    /** The typed character, withheld for control characters and CONTROL / ALT / SUPER chords. */
    private function text(int $keyval, int $state): string
    {
        $chord = GdkModifierType::CONTROL_MASK->value | GdkModifierType::ALT_MASK->value | GdkModifierType::SUPER_MASK->value;

        if (($state & $chord) !== 0) {
            return '';
        }

        $code_point = $this->controllers->unicode($keyval);

        if ($code_point <= 0x1F || $code_point === 0x7F) {
            return '';
        }

        return (string) mb_chr($code_point, 'UTF-8');
    }

    /** Open pads on newly listed nodes; drop and close pads that report disconnected. */
    private function scanPads(): void
    {
        foreach ($this->pads as $path => $pad) {
            if (! $pad->connected()) {
                $this->closePad($pad);
                unset($this->pads[$path]);
            }
        }

        try {
            $paths = $this->scanner->gamepads();
        } catch (Throwable $e) {
            $this->record("gamepad scan failed: {$e->getMessage()}");

            return;
        }

        if ($paths === []) {
            $this->noteUnreadableNodes();
        }

        foreach ($paths as $path) {
            if (array_key_exists($path, $this->pads)) {
                continue;
            }

            try {
                $circuit = $this->scanner->open($path);
            } catch (Throwable $e) {
                $this->record("could not open gamepad '{$path}': {$e->getMessage()}");

                continue;
            }

            if (! is_null($circuit)) {
                $this->pads[$path] = new ICInput(basename($path), $circuit);
            }
        }
    }

    /** An empty scan with input nodes present and none readable is a permissions problem, not an empty seat. Once per connect. */
    private function noteUnreadableNodes(): void
    {
        if ($this->access_noted) {
            return;
        }

        try {
            $nodes = ($this->input_nodes)();
        } catch (Throwable) {
            return;
        }

        if ($nodes !== [] && ! in_array(true, $nodes, true)) {
            $this->access_noted = true;
            $this->record('no readable /dev/input/event*: add the user to the input group');
        }
    }

    /** Keep the message for errors(); hand it to the container's log when one is bound. Never throws. */
    private function record(string $message): void
    {
        $message = "input.gtk: {$message}";
        $this->errors[] = $message;

        if (! function_exists('app')) {
            return;
        }

        try {
            if (app()->bound('log')) {
                app('log')->warning($message);
            }
        } catch (Throwable) {
            // Logging is best effort; errors() still has the message.
        }
    }

    private function closePad(ICInput $pad): void
    {
        $circuit = $pad->circuit();

        if ($circuit instanceof ControllerCircuit) {
            $this->scanner->close($circuit);
        }
    }

    /**
     * Held modifier keys decide their kind once this focus has seen one; the
     * signal covers the rest (a modifier held down before the window took focus).
     * Neither GTK source alone is right on hardware: key events and the
     * `modifiers` signal both carry the state from before the key.
     */
    private function resolveModifiers(): Modifiers
    {
        $held = [];

        foreach ($this->keyboard->downKeys() as $key) {
            $kind = self::MODIFIER_KEYS[$key->value] ?? null;
            if (! is_null($kind)) {
                $held[$kind] = true;
            }
        }

        $pick = fn (string $kind, bool $signalled): bool => isset($this->keyed[$kind]) ? isset($held[$kind]) : $signalled;

        return new Modifiers(
            shift: $pick('shift', $this->signalled->shift),
            ctrl: $pick('ctrl', $this->signalled->ctrl),
            alt: $pick('alt', $this->signalled->alt),
            meta: $pick('meta', $this->signalled->meta),
        );
    }

    /** Sided modifier keys by Key value → Modifiers field. */
    private const array MODIFIER_KEYS = [
        'left_shift' => 'shift', 'right_shift' => 'shift',
        'left_ctrl' => 'ctrl', 'right_ctrl' => 'ctrl',
        'left_alt' => 'alt', 'right_alt' => 'alt',
        'left_meta' => 'meta', 'right_meta' => 'meta',
    ];

    private static function modifiers(int $state): Modifiers
    {
        return new Modifiers(
            shift: ($state & GdkModifierType::SHIFT_MASK->value) !== 0,
            ctrl: ($state & GdkModifierType::CONTROL_MASK->value) !== 0,
            alt: ($state & GdkModifierType::ALT_MASK->value) !== 0,
            // META_MASK rides along with ALT on common X11/Wayland keymaps; SUPER is the meta key.
            meta: ($state & GdkModifierType::SUPER_MASK->value) !== 0,
        );
    }
}

<?php

namespace Venusian\GTK\Tests\Support;

use Closure;
use RuntimeException;
use Jovian\Venusian\GTK\Input\ControllerFactory;
use Jovian\Venusian\GTK\Input\WindowControllers;

/** Records attaches and detaches, and hands the test each window's callbacks to fire. No GTK. */
final class FakeControllerFactory implements ControllerFactory
{
    /** @var list<array{string, int}> window name, window handle — every attach, in order */
    public array $attached = [];

    /** @var list<string> */
    public array $detached = [];

    /** @var list<string> */
    public array $forgotten = [];

    /** @var array<string, array<string, Closure>> window name → the engine's callbacks */
    public array $on = [];

    /** What GtkGestureSingle::getCurrentButton answers. */
    public int $button = 1;

    /** @var list<string> window names whose attach throws */
    public array $refuse = [];

    private int $next_handle = 1000;

    public function attach(string $window, int $window_handle, array $on): WindowControllers
    {
        $this->attached[] = [$window, $window_handle];

        if (in_array($window, $this->refuse, true)) {
            throw new RuntimeException("no controllers for {$window}");
        }

        $this->on[$window] = $on;

        $handles = [];
        foreach (['key', 'motion', 'click', 'scroll'] as $kind) {
            $handles[$kind] = $this->next_handle++;
        }

        return new WindowControllers($window, $window_handle, $handles, ['notify::is-active' => $this->next_handle++]);
    }

    public function detach(WindowControllers $controllers): void
    {
        $this->detached[] = $controllers->window;
        unset($this->on[$controllers->window]);
    }

    public function forget(WindowControllers $controllers): void
    {
        $this->forgotten[] = $controllers->window;
        unset($this->on[$controllers->window]);
    }

    public function currentButton(WindowControllers $controllers): int
    {
        return $this->button;
    }

    /** Latin-1 keyvals equal their code point; everything else has none. */
    public function unicode(int $keyval): int
    {
        return $keyval < 0x100 ? $keyval : 0;
    }

    /** Fire one of a window's callbacks the way GTK would during the pump. */
    public function fire(string $window, string $signal, int|float|bool ...$args): mixed
    {
        return ($this->on[$window][$signal])(...$args);
    }
}

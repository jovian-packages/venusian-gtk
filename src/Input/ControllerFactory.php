<?php

namespace Jovian\Venusian\GTK\Input;

use Closure;

/** Builds and removes the four controllers and the focus hook on one window. The engine owns their callbacks. */
interface ControllerFactory
{
    /**
     * @param array{
     *     key_pressed: Closure(int, int, int): bool,
     *     key_released: Closure(int, int, int): void,
     *     modifiers: Closure(int): bool,
     *     motion: Closure(float, float): void,
     *     enter: Closure(float, float): void,
     *     leave: Closure(): void,
     *     pressed: Closure(int, float, float): void,
     *     released: Closure(int, float, float): void,
     *     scroll: Closure(float $dx, float $dy, int $unit): bool,
     *     active: Closure(bool $active): void,
     * } $on scroll's $unit is GdkScrollUnit, read inside the handler; active fires on the window's notify::is-active
     */
    public function attach(string $window, int $window_handle, array $on): WindowControllers;

    /** Disconnect the window's signal handlers and remove each controller from the still-live window, then drop them. */
    public function detach(WindowControllers $controllers): void;

    /** Drop the controllers of a window that is gone. No call touches the window. */
    public function forget(WindowControllers $controllers): void;

    /** GtkGestureSingle::getCurrentButton on the click gesture; meaningful inside a click handler. */
    public function currentButton(WindowControllers $controllers): int;

    /** GdkKeyval::toUnicode — 0 when the keyval has no code point. */
    public function unicode(int $keyval): int;
}

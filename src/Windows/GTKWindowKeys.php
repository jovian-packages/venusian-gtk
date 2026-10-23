<?php

namespace Jovian\Toolkits\GTK\Windows;

use GtkApplicationWindow;
use GtkEventControllerKey;
use GtkPropagationPhase;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use WeakReference;

/**
 * A toolkit window's keys that nothing took: one GtkEventControllerKey on the window in the
 * bubble phase, which GTK reaches only after the focused widget, its ancestors and the window's
 * shortcuts declined the key. Tab and Shift-Tab (ISO_Left_Tab) are let through, so GTK moves
 * focus. A modifier pressed alone (Shift_L to Hyper_R, Caps_Lock, ISO_Level3_Shift) goes on and
 * posts nothing, as AppKit sends no key for one. Every other key posts WindowKeyPressed and is
 * handled. GTK gives no repeat flag.
 */
final class GTKWindowKeys
{
    private const KEY_TAB = 0xFF09;

    private const KEY_ISO_LEFT_TAB = 0xFE20;

    private const KEY_SHIFT_L = 0xFFE1;

    private const KEY_HYPER_R = 0xFFEE;

    private const KEY_ISO_LEVEL3_SHIFT = 0xFE03;

    private GtkEventControllerKey $controller;

    /** @var WeakReference<GTKWindow> the window holds this */
    private readonly WeakReference $window;

    private bool $attached = true;

    public function __construct(GTKWindow $window, GtkApplicationWindow $native)
    {
        $this->window = WeakReference::create($window);
        $self = WeakReference::create($this);
        $this->controller = GtkEventControllerKey::new();
        $this->controller->setPropagationPhase(GtkPropagationPhase::BUBBLE);
        g_signal_connect($this->controller, 'key-pressed', static fn (GtkEventControllerKey $controller, int $keyval, int $keycode, int $state): bool => $self->get()?->pressed($keyval, $keycode, $state) ?? false);
        $native->addController($this->controller);
    }

    /** The controller, for tests: emitting key-pressed on it is the handler's real path. */
    public function controller(): GtkEventControllerKey
    {
        return $this->controller;
    }

    /** The window closed: no key posts after. */
    public function detach(): void
    {
        $this->attached = false;
    }

    private function pressed(int $keyval, int $keycode, int $state): bool
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof GTKWindow || ! $window->isOpen()
            || $keyval === self::KEY_TAB || $keyval === self::KEY_ISO_LEFT_TAB
            || ($keyval >= self::KEY_SHIFT_L && $keyval <= self::KEY_HYPER_R) || $keyval === self::KEY_ISO_LEVEL3_SHIFT) {
            return false;
        }
        $code = gdk_keyval_to_unicode($keyval);
        $character = $code >= 0x20 && $code !== 0x7F ? mb_chr($code, 'UTF-8') : '';
        $window->session()->post(new WindowKeyPressed($window->name(), GTKInputEngine::keyOf($keycode), $character, GTKInputEngine::modifiers($state), false));

        return true;
    }
}

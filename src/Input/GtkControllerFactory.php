<?php

namespace Jovian\Venusian\GTK\Input;

use Jovian\Bindings\Gtk\Enums\GdkScrollUnit;
use Jovian\Bindings\Gtk\Enums\GtkEventControllerScrollFlags;
use Jovian\Bindings\Gtk\Enums\GtkPropagationPhase;
use Jovian\Bindings\Gtk\Gdk\GdkKeyval;
use Jovian\Bindings\Gtk\Gtk\GtkEventController;
use Jovian\Bindings\Gtk\Gtk\GtkEventControllerKey;
use Jovian\Bindings\Gtk\Gtk\GtkEventControllerMotion;
use Jovian\Bindings\Gtk\Gtk\GtkEventControllerScroll;
use Jovian\Bindings\Gtk\Gtk\GtkGestureClick;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWindow;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Jovian\Bindings\Gtk\Runtime\Registry;
use Jovian\Venusian\GTK\Exceptions\GTKInputException;
use Throwable;
use WeakReference;

/**
 * The real controller set: a key controller in the CAPTURE phase (focused
 * child widgets cannot swallow game keys), a motion controller, a click
 * gesture on every button, and a two-axis scroll controller. The key
 * controller's `modifiers` signal carries the post-change modifier state. The
 * window's `notify::is-active` reports focus; the scroll unit is read inside
 * the scroll handler (GTK answers it only there). The DTOs are held here so their registry handles outlive attach(); dropping them is what
 * releases the handles. A failing attach rolls back: controllers already added
 * are removed and the window handler disconnected before the throw goes on.
 * Every step is a native call (DTO constructors call ext classes directly),
 * so the rollback has no ext-free unit test.
 */
final class GtkControllerFactory implements ControllerFactory
{
    /** @var array<int, GtkEventController> registry handle → controller */
    private array $held = [];

    public function attach(string $window, int $window_handle, array $on): WindowControllers
    {
        $host = Registry::box($window_handle);

        if (! $host instanceof GtkWidget) {
            throw GTKInputException::notAWidget($window, $window_handle);
        }

        $key = GtkEventControllerKey::new();
        $key->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $key->onKeyPressed($on['key_pressed']);
        $key->onKeyReleased($on['key_released']);
        // jovian/gtk's onModifiers is untyped and forwards the emitter; drop it here.
        $modifiers = $on['modifiers'];
        Bridge::connect($key->handle, 'modifiers', static fn (int $emitter, int $state): bool => $modifiers($state));

        $motion = GtkEventControllerMotion::new();
        $motion->onMotion($on['motion']);
        $motion->onEnter($on['enter']);
        $motion->onLeave($on['leave']);

        $click = GtkGestureClick::new();
        $click->setButton(0);
        $click->onPressed($on['pressed']);
        $click->onReleased($on['released']);

        $scroll = GtkEventControllerScroll::new(GtkEventControllerScrollFlags::BOTH_AXES->value);
        $on_scroll = $on['scroll'];
        // Weak: the handler lives in the ext and must not keep the DTO (and its handle) alive after forget().
        $scroll_ref = WeakReference::create($scroll);
        $scroll->onScroll(static function (float $dx, float $dy) use ($on_scroll, $scroll_ref): bool {
            $unit = $scroll_ref->get()?->getUnit() ?? GdkScrollUnit::WHEEL->value;

            return $on_scroll($dx, $dy, $unit);
        });

        $signals = [];
        $added = [];

        try {
            if ($host instanceof GtkWindow) {
                $on_active = $on['active'];
                $signals['notify::is-active'] = Bridge::connect($window_handle, 'notify::is-active', static function (int $emitter, int $pspec) use ($on_active): void {
                    $window = Registry::box($emitter);

                    if ($window instanceof GtkWindow) {
                        $on_active($window->isActive());
                    }
                });
            }

            foreach (['key' => $key, 'motion' => $motion, 'click' => $click, 'scroll' => $scroll] as $kind => $controller) {
                $host->addController($controller);
                $added[$kind] = $controller;
            }
        } catch (Throwable $e) {
            foreach ($signals as $handler_id) {
                Bridge::disconnect($window_handle, $handler_id);
            }

            foreach ($added as $controller) {
                $host->removeController($controller);
            }

            throw $e;
        }

        $handles = [];
        foreach ($added as $kind => $controller) {
            $this->held[$controller->handle] = $controller;
            $handles[$kind] = $controller->handle;
        }

        return new WindowControllers($window, $window_handle, $handles, $signals);
    }

    public function detach(WindowControllers $controllers): void
    {
        $host = Registry::box($controllers->window_handle);

        if ($host instanceof GtkWidget) {
            foreach ($controllers->signals as $handler_id) {
                Bridge::disconnect($controllers->window_handle, $handler_id);
            }

            foreach ($controllers->handles as $handle) {
                if (array_key_exists($handle, $this->held)) {
                    $host->removeController($this->held[$handle]);
                }
            }
        }

        $this->forget($controllers);
    }

    public function forget(WindowControllers $controllers): void
    {
        foreach ($controllers->handles as $handle) {
            unset($this->held[$handle]);
        }
    }

    public function currentButton(WindowControllers $controllers): int
    {
        $click = $this->held[$controllers->handles['click'] ?? 0] ?? null;

        return $click instanceof GtkGestureClick ? $click->getCurrentButton() : 0;
    }

    public function unicode(int $keyval): int
    {
        return GdkKeyval::toUnicode($keyval);
    }
}

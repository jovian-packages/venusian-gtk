<?php

namespace Jovian\Toolkits\GTK\Windows;

use GdkButtonEvent;
use GdkEvent;
use GdkEventType;
use GdkTouchEvent;
use GtkApplicationWindow;
use GtkEventControllerLegacy;
use GtkPropagationPhase;
use GtkSettings;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Input\GTKInputEngine;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\HumanInput\MouseButton;
use Jovian\Toolkits\GTK\Primitives\GTKCanvas;
use Surface\Contracts\Windows\Mail\View\CanvasDragged;
use Surface\Contracts\Windows\Mail\View\CanvasEntered;
use Surface\Contracts\Windows\Mail\View\CanvasLeft;
use Surface\Contracts\Windows\Mail\View\CanvasMoved;
use Surface\Contracts\Windows\Mail\View\CanvasPressed;
use Surface\Contracts\Windows\Mail\View\CanvasReleased;
use Surface\Contracts\Windows\Primitives\TKCanvas;
use WeakReference;

/**
 * A toolkit window's canvas presses, drags and releases as mail, from raw events on a capture-phase
 * legacy controller that never claims, as GTKCanvasClicks watches them. Not gestures: GtkGestureSingle
 * resets on a second button (gtk 4.14.5 gtkgesturesingle.c:209-216), and a reset GtkGestureDrag emits
 * drag-end while the first button is still down and then tracks nothing.
 *
 * The first button down on a watching, enabled canvas owns the press: other buttons' presses and
 * releases post nothing until it ends, and the canvas keeps the drag and the release wherever the
 * pointer goes, inside the window or out of it. Exactly one CanvasReleased follows every
 * CanvasPressed: at the owning button's release, or at once at the last point posted when the press
 * ends without one (interrupt(), which a context menu opening and a broken grab or cancelled touch
 * call). A press the code ends — watchPointer(false), the canvas or a group above it disabled, the
 * canvas removed, the window closed — drops silently, as no mail comes from code.
 *
 * The wheel is not here: it belongs to each canvas's own scroll controller (GTKCanvas::wheeled()),
 * which keeps the event from an enclosing scroll view by handling it.
 */
final class GTKCanvasPointer
{
    private readonly GtkEventControllerLegacy $events;

    /** GTK's double-click time in seconds; 0.4 with no settings, as GTK's own default. */
    private readonly float $double_click_s;

    /** GTK's double-click distance in pixels; 5 with no settings, as GTK's own default. */
    private readonly int $double_click_distance;

    /** @var array{TKCanvas, GtkWidget, int, MouseButton, float, float, Modifiers}|null the press owned: canvas, its native, GDK button, button, last canvas point, modifiers last seen */
    private ?array $press = null;

    /** @var array{TKCanvas, int, float, float, float, int}|null the press counted last: canvas, GDK button, window point, when, count */
    private ?array $counted = null;

    /**
     * @var array{float, float}|null the owned press's window point until it first drags. X11 reports a
     *      press in fractions of a pixel and the motion GTK follows it with in whole pixels, so a first
     *      move less than a point away is the press itself.
     */
    private ?array $origin = null;

    /** @var WeakReference<TKCanvas>|null the watching canvas the pointer is over */
    private ?WeakReference $inside = null;

    public function __construct(
        private readonly GTKWindow $window,
        private readonly GtkApplicationWindow $native,
        private readonly GTKSession $session,
    ) {
        $settings = GtkSettings::getDefault();
        $this->double_click_s = (is_null($settings) ? 400 : (int) g_object_get_property($settings, 'gtk-double-click-time')) / 1000;
        $this->double_click_distance = is_null($settings) ? 5 : (int) g_object_get_property($settings, 'gtk-double-click-distance');

        // The controller's handler is held by the window native, which this pointer holds: a weak reference breaks the cycle.
        $self = WeakReference::create($this);
        $this->events = GtkEventControllerLegacy::new();
        g_signal_connect($this->events, 'event', static function (GtkEventControllerLegacy $controller, GdkEvent $event) use ($self): bool {
            $self->get()?->seen($event);

            return false;
        });
        $this->events->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $native->addController($this->events);
    }

    public function events(): GtkEventControllerLegacy
    {
        return $this->events;
    }

    /**
     * A button pressed at a window point, at $at (microtime; now unless given). With no press owned
     * it starts one on the watching, enabled canvas there; with one owned it posts nothing.
     */
    public function pressed(float $x, float $y, int $button, int $state, ?float $at = null): void
    {
        // holding() drops a press whose canvas has gone, so one the code ended never blocks the next.
        if (! is_null($this->holding())) {
            return;
        }
        $mouse = GTKInputEngine::buttonOf($button);
        if (is_null($mouse)) {
            return;
        }
        [$canvas, $native] = GTKCanvasClicks::canvasAt($this->window, $this->native, $x, $y);
        // A press on the canvas stops its coast.
        if ($canvas instanceof GTKCanvas) {
            $canvas->endCoast();
        }
        if (is_null($canvas) || ! $canvas->isWatchingPointer()) {
            return;
        }
        [$cx, $cy] = GTKCanvasClicks::canvasPoint($this->native, $native, $x, $y);
        $clicks = $this->counting($canvas, $button, $x, $y, $at ??= microtime(true));
        $modifiers = GTKInputEngine::modifiers($state);
        $this->counted = [$canvas, $button, $x, $y, $at, $clicks];
        $this->press = [$canvas, $native, $button, $mouse, $cx, $cy, $modifiers];
        $this->origin = [$x, $y];
        $this->session->post(new CanvasPressed($this->window->name(), $canvas->path(), $canvas->uuid(), $cx, $cy, $mouse, $clicks, $modifiers));
    }

    /** The pointer moved: a drag while the owning button is held, posted only when the point changed. */
    public function moved(float $x, float $y, int $state): void
    {
        $press = $this->holding();
        if (is_null($press)) {
            $this->hovered($x, $y, $state);

            return;
        }
        if (! is_null($this->origin)) {
            if (abs($x - $this->origin[0]) < 1.0 && abs($y - $this->origin[1]) < 1.0) {
                return;
            }
            $this->origin = null;
        }
        [$canvas, $native, $button, $mouse, $lx, $ly] = $press;
        [$cx, $cy] = GTKCanvasClicks::canvasPoint($this->native, $native, $x, $y);
        $modifiers = GTKInputEngine::modifiers($state);
        if ($cx === $lx && $cy === $ly) {
            return;
        }
        $this->press = [$canvas, $native, $button, $mouse, $cx, $cy, $modifiers];
        $this->session->post(new CanvasDragged($this->window->name(), $canvas->path(), $canvas->uuid(), $cx, $cy, $mouse, $modifiers));
    }

    /** A button released at a window point: the end of the press when it is the owning button's. */
    public function released(float $x, float $y, int $button, int $state): void
    {
        $press = $this->holding();
        if (is_null($press) || $press[2] !== $button) {
            return;
        }
        [$canvas, $native, , $mouse] = $press;
        $this->press = null;
        [$cx, $cy] = GTKCanvasClicks::canvasPoint($this->native, $native, $x, $y);
        $this->session->post(new CanvasReleased($this->window->name(), $canvas->path(), $canvas->uuid(), $cx, $cy, $mouse, GTKInputEngine::modifiers($state)));
        // No crossing is seen during a press: a release away from the canvas the pointer was over is its exit.
        $inside = $this->inside?->get();
        if (! is_null($inside) && GTKCanvasClicks::canvasAt($this->window, $this->native, $x, $y)[0] !== $inside) {
            $this->leave($inside);
        }
    }

    /**
     * The pointer moved with no press owned: CanvasMoved (latest-wins) over a watching canvas, and
     * CanvasLeft / CanvasEntered when the watching canvas under it changed.
     */
    public function hovered(float $x, float $y, int $state): void
    {
        [$canvas, $native] = GTKCanvasClicks::canvasAt($this->window, $this->native, $x, $y);
        $canvas = $canvas?->isWatchingPointer() ? $canvas : null;
        $inside = $this->inside?->get();
        if ($inside !== $canvas) {
            $this->leave($inside);
            if (! is_null($canvas)) {
                $this->inside = WeakReference::create($canvas);
                $this->session->post(new CanvasEntered($this->window->name(), $canvas->path(), $canvas->uuid()));
            }
        }
        if (is_null($canvas)) {
            return;
        }
        [$cx, $cy] = GTKCanvasClicks::canvasPoint($this->native, $native, $x, $y);
        $this->session->postLatest(
            "view.moved.{$this->window->name()}.{$canvas->path()}",
            new CanvasMoved($this->window->name(), $canvas->path(), $canvas->uuid(), $cx, $cy, GTKInputEngine::modifiers($state)),
        );
    }

    /** The pointer left the window: the canvas it was over posts CanvasLeft, unless a press is owned. */
    public function leftWindow(): void
    {
        if (is_null($this->holding())) {
            $this->leave($this->inside?->get());
        }
    }

    private function leave(?TKCanvas $canvas): void
    {
        if (is_null($canvas) || $this->inside?->get() !== $canvas) {
            return;
        }
        $this->inside = null;
        if (! $canvas->isRemoved()) {
            // The canvas's last CanvasMoved waits among the latest-wins mail: it goes out before the exit.
            $this->session->flushLatest();
            $this->session->post(new CanvasLeft($this->window->name(), $canvas->path(), $canvas->uuid()));
        }
    }

    /**
     * The press ended without a release: a context menu opened for it, its grab broke, or the touch
     * was cancelled. The release goes out at the last point posted, with the modifiers last seen
     * (GTK reports none with the menu); nothing when no press is owned.
     */
    public function interrupt(): void
    {
        $press = $this->holding();
        $this->press = null;
        if (is_null($press)) {
            return;
        }
        [$canvas, , , $mouse, $cx, $cy, $modifiers] = $press;
        $this->session->post(new CanvasReleased($this->window->name(), $canvas->path(), $canvas->uuid(), $cx, $cy, $mouse, $modifiers));
    }

    /**
     * The press owned, if its canvas still takes pointer mail. A canvas that was removed, stopped
     * watching, or was disabled itself or inside a group drops the press here, silently.
     *
     * @return array{TKCanvas, GtkWidget, int, MouseButton, float, float, Modifiers}|null
     */
    private function holding(): ?array
    {
        $press = $this->press;
        if (is_null($press)) {
            return null;
        }
        $canvas = $press[0];
        if ($canvas->isRemoved() || ! $canvas->isWatchingPointer()) {
            $this->press = null;

            return null;
        }
        for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                $this->press = null;

                return null;
            }
        }

        return $press;
    }

    /** This press's place in a run of clicks: the last one's count + 1 while it is the same button on the same canvas, in time and in place. */
    private function counting(TKCanvas $canvas, int $button, float $x, float $y, float $at): int
    {
        $counted = $this->counted;
        if (is_null($counted) || $counted[0] !== $canvas || $counted[1] !== $button) {
            return 1;
        }
        $near = hypot($x - $counted[2], $y - $counted[3]) <= $this->double_click_distance;

        return $near && $at - $counted[4] <= $this->double_click_s ? $counted[5] + 1 : 1;
    }

    /**
     * What an event of $type does to the press: 'pressed', 'moved', 'released', 'interrupt', or null.
     * $pointer is false for a touch the toolkit does not emulate the pointer with (a second finger),
     * whose begin, moves, lift and cancel are never the press's.
     */
    public static function route(GdkEventType $type, bool $pointer): ?string
    {
        if (! $pointer) {
            return null;
        }

        return match ($type) {
            GdkEventType::BUTTON_PRESS, GdkEventType::TOUCH_BEGIN => 'pressed',
            GdkEventType::MOTION_NOTIFY, GdkEventType::TOUCH_UPDATE, GdkEventType::ENTER_NOTIFY => 'moved',
            GdkEventType::LEAVE_NOTIFY => 'left',
            GdkEventType::BUTTON_RELEASE, GdkEventType::TOUCH_END => 'released',
            GdkEventType::GRAB_BROKEN, GdkEventType::TOUCH_CANCEL => 'interrupt',
            default => null,
        };
    }

    private function seen(GdkEvent $event): void
    {
        $route = self::route($event->getEventType(), ! $event instanceof GdkTouchEvent || $event->getEmulatingPointer());
        if ($route === 'interrupt') {
            $this->interrupt();

            return;
        }
        if ($route === 'left') {
            $this->leftWindow();

            return;
        }
        if (is_null($route) || ! $event->getPosition($sx, $sy)) {
            return;
        }
        $this->native->getSurfaceTransform($ox, $oy);
        [$x, $y] = [$sx - $ox, $sy - $oy];
        $state = $event->getModifierState();
        // A touch the toolkit emulates a pointer with is the left button.
        $button = $event instanceof GdkButtonEvent ? $event->getButton() : 1;
        match ($route) {
            'pressed' => $this->pressed($x, $y, $button, $state),
            'released' => $this->released($x, $y, $button, $state),
            'moved' => $this->moved($x, $y, $state),
        };
    }
}

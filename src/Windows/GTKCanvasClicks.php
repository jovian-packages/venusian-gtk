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
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Surface\Contracts\Windows\Mail\View\CanvasClicked;
use Surface\Contracts\Windows\Primitives\TKCanvas;

/**
 * A toolkit window's canvas clicks as mail, from raw events on a capture-phase legacy controller that
 * never claims: a primary press on a canvas (not with Control on macOS, a right click there) and its
 * release over the same canvas, the pointer not moved past gtk-dnd-drag-threshold in between. On
 * Linux a press held past gtk-long-press-time is a right click instead, so it is no click. The mail
 * carries where the press landed, from the canvas's border-box top-left.
 */
final class GTKCanvasClicks
{
    private readonly GtkEventControllerLegacy $events;

    private bool $hold_cancels = PHP_OS_FAMILY === 'Linux';

    private readonly float $hold_s;

    private readonly int $drag_threshold;

    /** @var array{TKCanvas, float, float, float, float, float}|null the press: its canvas, its window point, its canvas point, when */
    private ?array $press = null;

    public function __construct(
        private readonly GTKWindow $window,
        private readonly GtkApplicationWindow $native,
        private readonly GTKSession $session,
    ) {
        $settings = GtkSettings::getDefault();
        $this->hold_s = (is_null($settings) ? 500 : (int) g_object_get_property($settings, 'gtk-long-press-time')) / 1000;
        $this->drag_threshold = is_null($settings) ? 8 : (int) g_object_get_property($settings, 'gtk-dnd-drag-threshold');

        $this->events = GtkEventControllerLegacy::new();
        g_signal_connect($this->events, 'event', function (GtkEventControllerLegacy $controller, GdkEvent $event): bool {
            $this->seen($event);

            return false;
        });
        $this->events->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $native->addController($this->events);
    }

    public function events(): GtkEventControllerLegacy
    {
        return $this->events;
    }

    /** Whether a press held past the long-press time is no click (it is a right click): on Linux by default. */
    public function holdCancels(bool $on): void
    {
        $this->hold_cancels = $on;
    }

    /** Whether a press of $button under modifier $state can start a click. */
    public static function primary(int $button, int $state, bool $macos = PHP_OS_FAMILY === 'Darwin'): bool
    {
        return $button === 1 && ! ($macos && ($state & GDK_CONTROL_MASK) !== 0);
    }

    /** A primary press at a window point, made at $at (microtime; now unless given): the start of a click when it lands on an enabled canvas. */
    public function pressed(float $x, float $y, ?float $at = null): void
    {
        $this->press = null;
        [$canvas, $native] = self::canvasAt($this->window, $this->native, $x, $y);
        if (is_null($canvas)) {
            return;
        }
        [$cx, $cy] = self::canvasPoint($this->native, $native, $x, $y);
        $this->press = [$canvas, $x, $y, $cx, $cy, $at ?? microtime(true)];
    }

    /** The pointer moved: past the drag threshold the press is a drag, not a click. */
    public function moved(float $x, float $y): void
    {
        if (! is_null($this->press) && hypot($x - $this->press[1], $y - $this->press[2]) > $this->drag_threshold) {
            $this->press = null;
        }
    }

    /** The primary button released at a window point: a click when it is over the pressed canvas. */
    public function released(float $x, float $y): void
    {
        $press = $this->press;
        $this->press = null;
        if (is_null($press) || ($this->hold_cancels && microtime(true) - $press[5] >= $this->hold_s)) {
            return;
        }
        [$canvas] = self::canvasAt($this->window, $this->native, $x, $y);
        if ($canvas === $press[0]) {
            $this->session->post(new CanvasClicked($this->window->name(), $canvas->path(), $canvas->uuid(), $press[3], $press[4]));
        }
    }

    /** The press ended without a release (its grab broke). */
    public function cancelled(): void
    {
        $this->press = null;
    }

    private function seen(GdkEvent $event): void
    {
        $type = $event->getEventType();
        if ($type === GdkEventType::GRAB_BROKEN || $type === GdkEventType::TOUCH_CANCEL) {
            $this->cancelled();

            return;
        }
        if (! $event->getPosition($sx, $sy)) {
            return;
        }
        $this->native->getSurfaceTransform($ox, $oy);
        [$x, $y] = [$sx - $ox, $sy - $oy];
        $primary = ($event instanceof GdkButtonEvent && self::primary($event->getButton(), $event->getModifierState()))
            || ($event instanceof GdkTouchEvent && $event->getEmulatingPointer());
        match (true) {
            ($type === GdkEventType::BUTTON_PRESS || $type === GdkEventType::TOUCH_BEGIN) && $primary => $this->pressed($x, $y),
            ($type === GdkEventType::BUTTON_RELEASE || $type === GdkEventType::TOUCH_END) && $primary => $this->released($x, $y),
            $type === GdkEventType::MOTION_NOTIFY || $type === GdkEventType::TOUCH_UPDATE => $this->moved($x, $y),
            default => null,
        };
    }

    /**
     * The enabled canvas under a window point, and its native: the first widget up from GTK's pick
     * that a canvas owns. Null when it, or one of its groups, is disabled, or no canvas is there.
     * Shared with GTKCanvasPointer, which routes a press the same way.
     *
     * @return array{TKCanvas|null, GtkWidget|null}
     */
    public static function canvasAt(GTKWindow $window, GtkApplicationWindow $native, float $x, float $y): array
    {
        $owners = [];
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof TKCanvas && $primitive instanceof GTKView) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }
        for ($widget = $native->pick($x, $y, GTK_PICK_INSENSITIVE | GTK_PICK_NON_TARGETABLE); ! is_null($widget); $widget = $widget->getParent()) {
            $canvas = $owners[spl_object_id($widget)] ?? null;
            if (is_null($canvas)) {
                continue;
            }
            for ($at = $canvas; ! is_null($at); $at = $at->parent()) {
                if (! $at->isEnabled()) {
                    return [null, null];
                }
            }

            return [$canvas, $widget];
        }

        return [null, null];
    }

    /**
     * A window point in $canvas's own coordinates, from its border-box top-left: GTK's widget
     * coordinates start inside the border and padding, and Surface measures from the border box.
     * Points outside the canvas come back outside it, negative or past its size.
     *
     * @return array{float, float}
     */
    public static function canvasPoint(GtkApplicationWindow $native, GtkWidget $canvas, float $x, float $y): array
    {
        [$px, $py] = $native->computePoint($canvas, new \GraphenePoint($x, $y), $point) ? [$point->x, $point->y] : [$x, $y];
        [$bx, $by] = $canvas->computeBounds($canvas, $bounds) ? [$bounds->origin->x, $bounds->origin->y] : [0.0, 0.0];

        return [$px - $bx, $py - $by];
    }
}

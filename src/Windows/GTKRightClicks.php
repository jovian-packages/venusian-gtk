<?php

namespace Jovian\Toolkits\GTK\Windows;

use GdkButtonEvent;
use GdkEvent;
use GdkEventType;
use GdkTouchEvent;
use GtkApplicationWindow;
use GtkGestureClick;
use GtkEventControllerLegacy;
use GtkEventSequenceState;
use GtkPropagationPhase;
use GtkSettings;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\WindowRightClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Menus\ContextMenu;

/**
 * A toolkit window's right clicks as mail. A capture-phase click gesture for every button sees the
 * press before the window's widgets and never claims it, so their own clicks still run; a press is a
 * right click when it is the secondary button, or the primary with Control on macOS (GTK's macOS
 * backend reports Ctrl-click as button 1 with Control). On Linux a primary press held still for GTK's
 * long-press time is one too: a touchscreen registered as a pointer (the Pi's) has no second button,
 * and nothing below GTK turns a held finger into one. The hold is timed from raw events on a
 * capture-phase legacy controller, which a widget's own gesture claiming the press cannot cancel.
 * After the mail, the context menu of the primitive or its nearest group that has one opens at the
 * click; the click gesture then claims the press, so a widget's own menu (a text input's) stays shut.
 */
final class GTKRightClicks
{
    private readonly GtkGestureClick $click;

    private readonly GtkEventControllerLegacy $holds;

    private bool $hold_on_press = PHP_OS_FAMILY === 'Linux';

    private readonly int $hold_ms;

    private readonly int $drag_threshold;

    /** The GLib timeout of the press being held, and where it landed in the window. */
    private ?int $hold_source = null;

    /** @var array{float, float} */
    private array $hold_at = [0.0, 0.0];

    /** The context menu opened last; it takes itself off the window when it closes. */
    private ?GTKContextMenu $menu = null;

    public function __construct(
        private readonly GTKWindow $window,
        private readonly GtkApplicationWindow $native,
        private readonly GtkWidget $content,
        private readonly GTKSession $session,
    ) {
        $this->click = GtkGestureClick::new();
        $this->click->setButton(0);
        g_signal_connect($this->click, 'pressed', function (GtkGestureClick $gesture, int $n, float $x, float $y): void {
            $event = $gesture->getCurrentEvent();
            if (self::secondary($gesture->getCurrentButton(), $event instanceof GdkEvent ? $event->getModifierState() : 0) && $this->at($x, $y)) {
                $gesture->setState(GtkEventSequenceState::CLAIMED);
            }
        });

        $settings = GtkSettings::getDefault();
        $this->hold_ms = is_null($settings) ? 500 : (int) g_object_get_property($settings, 'gtk-long-press-time');
        $this->drag_threshold = is_null($settings) ? 8 : (int) g_object_get_property($settings, 'gtk-dnd-drag-threshold');
        $this->holds = GtkEventControllerLegacy::new();
        g_signal_connect($this->holds, 'event', function (GtkEventControllerLegacy $controller, GdkEvent $event): bool {
            $this->held($event);

            return false;
        });

        foreach ([$this->click, $this->holds] as $gesture) {
            $gesture->setPropagationPhase(GtkPropagationPhase::CAPTURE);
            $native->addController($gesture);
        }
    }

    public function click(): GtkGestureClick
    {
        return $this->click;
    }

    public function holds(): GtkEventControllerLegacy
    {
        return $this->holds;
    }

    /** Whether a held primary press is a right click: on Linux by default. */
    public function holdOnPress(bool $on): void
    {
        $this->hold_on_press = $on;
        $this->released();
    }

    /** A primary press at a window point: a right click if held there for the long-press time. */
    public function pressed(float $x, float $y): void
    {
        $this->released();
        if (! $this->hold_on_press) {
            return;
        }
        $this->hold_at = [$x, $y];
        $this->hold_source = g_timeout_add($this->hold_ms, function (): bool {
            $this->hold_source = null;
            $this->at(...$this->hold_at);

            return false;
        });
    }

    /** The held press moved: past the drag threshold it is a drag, not a long press. */
    public function moved(float $x, float $y): void
    {
        if (! is_null($this->hold_source) && hypot($x - $this->hold_at[0], $y - $this->hold_at[1]) > $this->drag_threshold) {
            $this->released();
        }
    }

    /** The press ended (released, or its grab broke) before the long-press time. */
    public function released(): void
    {
        if (! is_null($this->hold_source)) {
            g_source_remove($this->hold_source);
            $this->hold_source = null;
        }
    }

    private function held(GdkEvent $event): void
    {
        $type = $event->getEventType();
        $primary = ($event instanceof GdkButtonEvent && $event->getButton() === 1)
            || ($event instanceof GdkTouchEvent && $event->getEmulatingPointer());
        if (in_array($type, [GdkEventType::BUTTON_RELEASE, GdkEventType::TOUCH_END, GdkEventType::TOUCH_CANCEL, GdkEventType::GRAB_BROKEN], true)) {
            $this->released();

            return;
        }
        if (! $event->getPosition($sx, $sy)) {
            return;
        }
        $this->native->getSurfaceTransform($ox, $oy);
        [$x, $y] = [$sx - $ox, $sy - $oy];
        if (($type === GdkEventType::BUTTON_PRESS || $type === GdkEventType::TOUCH_BEGIN) && $primary) {
            $this->pressed($x, $y);
        } elseif ($type === GdkEventType::MOTION_NOTIFY || $type === GdkEventType::TOUCH_UPDATE) {
            $this->moved($x, $y);
        }
    }

    /** Whether a press of $button under modifier $state is a right click. */
    public static function secondary(int $button, int $state, bool $macos = PHP_OS_FAMILY === 'Darwin'): bool
    {
        return $button === 3 || ($macos && $button === 1 && ($state & GDK_CONTROL_MASK) !== 0);
    }

    /** The context menu opened last, open or closed since. */
    public function openMenu(): ?GTKContextMenu
    {
        return $this->menu;
    }

    /**
     * A right click at ($x, $y) in the window: mail for the innermost primitive there, in its own
     * coordinates; else for the window's bare content; nothing outside the content (the Linux menu
     * bar) or when the primitive or one of its groups is disabled. Then the context menu of the
     * primitive or its nearest group opens there. Whether a menu opened.
     */
    public function at(float $x, float $y): bool
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof GTKView) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }

        $widget = $this->native->pick($x, $y, GTK_PICK_INSENSITIVE | GTK_PICK_NON_TARGETABLE);
        for (; ! is_null($widget); $widget = $widget->getParent()) {
            $primitive = $owners[spl_object_id($widget)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                return $this->primitive($primitive, $widget, $x, $y);
            }
            if ($widget === $this->content) {
                [$cx, $cy] = $this->point($this->content, $x, $y);
                $this->session->post(new WindowRightClicked($this->window->name(), $cx, $cy));

                return false;
            }
        }

        return false;
    }

    /**
     * A point in the window's coordinates in $widget's; as given when they share no ancestor.
     *
     * @return array{float, float}
     */
    private function point(GtkWidget $widget, float $x, float $y): array
    {
        return $this->native->computePoint($widget, new \GraphenePoint($x, $y), $point) ? [$point->x, $point->y] : [$x, $y];
    }

    private function primitive(TKPrimitive $primitive, GtkWidget $native, float $x, float $y): bool
    {
        $menu = null;
        $owner = null;
        for ($at = $primitive; ! is_null($at); $at = $at->parent()) {
            if (! $at->isEnabled()) {
                return false;
            }
            if (is_null($menu) && ! is_null($at->contextMenu())) {
                [$menu, $owner] = [$at->contextMenu(), $at];
            }
        }
        // GTK's widget coordinates start inside the border and padding; Surface measures a primitive by
        // its border box, whose corner is the widget's bounds' origin in its own coordinates.
        [$px, $py] = $this->point($native, $x, $y);
        [$bx, $by] = $native->computeBounds($native, $bounds) ? [$bounds->origin->x, $bounds->origin->y] : [0.0, 0.0];
        $this->session->post(new RightClicked($this->window->name(), $primitive->path(), $primitive->uuid(), $px - $bx, $py - $by));
        if (! $menu instanceof ContextMenu) {
            return false;
        }

        $this->menu?->popover()->popdown();
        [$cx, $cy] = $this->point($this->content, $x, $y);
        // The owner keeps the user's toggle and radio choices: the next open shows them.
        $this->menu = new GTKContextMenu($menu, $this->window->name(), $this->session, $owner);
        $this->menu->popupAt($this->content, $cx, $cy);
        // The menu takes the pointer: a press the canvas owns ends here, with its release.
        $this->window->canvasPointer()->interrupt();

        return true;
    }
}

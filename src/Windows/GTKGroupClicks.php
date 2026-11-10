<?php

namespace Jovian\Toolkits\GTK\Windows;

use GdkEvent;
use GtkApplicationWindow;
use GtkGestureClick;
use GtkPropagationPhase;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Surface\Contracts\Windows\Mail\View\GroupClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A toolkit window's clicks on clickable groups as mail. A capture-phase click gesture for the
 * primary button sees the press before the window's widgets and never claims it; a press and its
 * release on the same group (TKPrimitiveGroup::clickTarget) post GroupClicked. A widget that claims
 * the press (a button) cancels this gesture's sequence, so its release never reaches here; it clicks
 * no group anyway. Ctrl with the primary button is a right click on macOS (GTKRightClicks), not this.
 */
final class GTKGroupClicks
{
    private readonly GtkGestureClick $click;

    /** The uuid of the group the primary press landed on, until its release. */
    private ?string $pressed = null;

    public function __construct(
        private readonly GTKWindow $window,
        private readonly GtkApplicationWindow $native,
        private readonly GTKSession $session,
    ) {
        $this->click = GtkGestureClick::new();
        $this->click->setButton(1);
        g_signal_connect($this->click, 'pressed', function (GtkGestureClick $gesture, int $n, float $x, float $y): void {
            $event = $gesture->getCurrentEvent();
            $state = $event instanceof GdkEvent ? $event->getModifierState() : 0;
            GTKRightClicks::secondary(1, $state) ? $this->pressed = null : $this->pressAt($x, $y);
        });
        g_signal_connect($this->click, 'released', function (GtkGestureClick $gesture, int $n, float $x, float $y): void {
            $this->releaseAt($x, $y);
        });
        $this->click->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $native->addController($this->click);
    }

    public function click(): GtkGestureClick
    {
        return $this->click;
    }

    /** A primary press at ($x, $y) in the window. */
    public function pressAt(float $x, float $y): void
    {
        $this->pressed = $this->targetAt($x, $y)?->uuid();
    }

    /** Its release at ($x, $y) in the window: GroupClicked when it is on the pressed group. */
    public function releaseAt(float $x, float $y): void
    {
        $pressed = $this->pressed;
        $this->pressed = null;
        $group = is_null($pressed) ? null : $this->targetAt($x, $y);
        if (is_null($group) || $group->uuid() !== $pressed || ! $group instanceof GTKView) {
            return;
        }
        // Surface measures a primitive by its border box, whose corner is the widget's bounds' origin.
        $native = $group->native();
        [$px, $py] = $this->native->computePoint($native, new \GraphenePoint($x, $y), $point) ? [$point->x, $point->y] : [$x, $y];
        [$bx, $by] = $native->computeBounds($native, $bounds) ? [$bounds->origin->x, $bounds->origin->y] : [0.0, 0.0];
        $this->session->post(new GroupClicked($this->window->name(), $group->path(), $pressed, $px - $bx, $py - $by));
    }

    private function targetAt(float $x, float $y): ?TKPrimitiveGroup
    {
        $owners = [];
        foreach ($this->window->registry()->all() as $primitive) {
            if ($primitive instanceof GTKView) {
                $owners[spl_object_id($primitive->native())] = $primitive;
            }
        }
        for ($widget = $this->native->pick($x, $y, GTK_PICK_INSENSITIVE | GTK_PICK_NON_TARGETABLE); ! is_null($widget); $widget = $widget->getParent()) {
            $primitive = $owners[spl_object_id($widget)] ?? null;
            if ($primitive instanceof TKPrimitive) {
                return TKPrimitiveGroup::clickTarget($primitive);
            }
        }

        return null;
    }
}

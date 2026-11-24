<?php

namespace Jovian\Toolkits\GTK\UI;

use GdkEvent;
use GtkPaned;
use GtkOverlay;
use GdkEventType;
use GtkWidget;
use GraphenePoint;
use WeakReference;
use GdkButtonEvent;
use GtkOrientation;
use GtkPropagationPhase;
use GtkEventControllerLegacy;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Toolkits\UI\TKSplitView;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkPaned. GTK reports a moved divider whoever moved it, so the user's drag is what moves
 * it while a press that began on the handle is held: each move is told as it happens. There
 * is no resize signal: a timeout puts the divider back at its fraction when the length changed.
 *
 * The paned is inside an overlay, which only hands it all its room: the paned claims a press on
 * its handle before a controller on itself sees it (observed), so the listener is on the overlay.
 */
class GtkSplitView extends TKSplitView
{
    use GtkNative {
        releaseNative as private releaseWidget;
    }

    private const int WATCH_MS = 50;

    /** How far either side of the divider a press takes hold of it: GTK's 6 past the handle, and the handle. */
    private const float GRAB = 12.0;

    private ?int $watching = null;

    private ?GtkPaned $paned = null;

    private int $length = 0;

    /** A press that began on the handle is held. */
    private bool $holding = false;

    protected function bootstrapNative(): void
    {
        $paned = GtkPaned::new($this->isHorizontal() ? GtkOrientation::HORIZONTAL : GtkOrientation::VERTICAL);
        $paned->setResizeStartChild(true);
        $paned->setResizeEndChild(true);
        $paned->setShrinkStartChild(false);
        $paned->setShrinkEndChild(false);
        $this->paned = $paned;
        $around = GtkOverlay::new();
        $around->setChild($paned);
        $this->adoptNative($around);

        $self = WeakReference::create($this);
        $pointer = GtkEventControllerLegacy::new();
        $pointer->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $this->connect($pointer, 'event', static function (GtkEventControllerLegacy $controller, GdkEvent $event) use ($self): bool {
            $self->get()?->pointer($event);

            return false;
        });
        $around->addController($pointer);
        $this->connect($paned, 'notify::position', static function () use ($self): void {
            $self->get()?->moved();
        });

        $this->watching = g_timeout_add(self::WATCH_MS, static function () use ($self): bool {
            $split = $self->get();
            if (is_null($split) || $split->isRemoved()) {
                return G_SOURCE_REMOVE;
            }
            $split->followLength();

            return G_SOURCE_CONTINUE;
        });
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->setPanes();
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $this->setPanes();
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $this->setPanes();
    }

    protected function applyPosition(float $position): void
    {
        $length = $this->lengthNow();
        if ($length > 0) {
            $this->panedNative()->setPosition((int) round($position * $length));
        }
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->watching)) {
            g_source_remove($this->watching);
            $this->watching = null;
        }
        $this->releaseWidget();
        $this->paned = null;
    }

    private function setPanes(): void
    {
        $paned = $this->panedNative();
        $panes = array_map(static fn (TKPrimitive $pane) => $pane->native(), $this->children());
        [$start, $end] = [$panes[0] ?? null, $panes[1] ?? null];
        if ($paned->getStartChild() === $start && $paned->getEndChild() === $end) {
            return;
        }

        $paned->setStartChild(null);
        $paned->setEndChild(null);
        $paned->setStartChild($start);
        $paned->setEndChild($end);
    }

    private function followLength(): void
    {
        $length = $this->lengthNow();
        if ($length > 0 && $length !== $this->length && $this->hasDivider()) {
            $this->length = $length;
            $this->write(fn () => $this->applyPosition($this->position()));
        }
    }

    private function pointer(GdkEvent $event): void
    {
        if ($this->isRemoved() || ! $event instanceof GdkButtonEvent || $event->getButton() !== 1) {
            return;
        }

        match ($event->getEventType()) {
            GdkEventType::BUTTON_PRESS => $this->holding = $this->isOnHandle($event),
            GdkEventType::BUTTON_RELEASE => $this->holding = false,
            default => null,
        };
    }

    private function moved(): void
    {
        if (! $this->holding || $this->isRemoved() || $this->isWriting()) {
            return;
        }

        $length = $this->lengthNow();
        if ($length > 0) {
            $this->dragged($this->panedNative()->getPosition() / $length);
        }
    }

    /**
     * The event's point is the surface's; the divider's is the paned's.
     */
    private function isOnHandle(GdkEvent $event): bool
    {
        $paned = $this->panedNative();
        $root = $paned->getNative();
        if (! $root instanceof GtkWidget || ! $event->getPosition($x, $y)) {
            return false;
        }

        $root->getSurfaceTransform($dx, $dy);
        if (! $root->computePoint($paned, new GraphenePoint($x - $dx, $y - $dy), $point)) {
            return false;
        }

        return abs(($this->isHorizontal() ? $point->x : $point->y) - $paned->getPosition()) <= self::GRAB;
    }

    private function lengthNow(): int
    {
        return $this->isHorizontal() ? $this->panedNative()->getWidth() : $this->panedNative()->getHeight();
    }

    private function panedNative(): GtkPaned
    {
        return $this->paned;
    }
}

<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GdkButtonEvent;
use GdkEvent;
use GdkEventType;
use GdkTouchEvent;
use GraphenePoint;
use GtkBox;
use GtkEventControllerLegacy;
use GtkOrientation;
use GtkPaned;
use GtkPropagationPhase;
use GtkWidget;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\SplitMoved;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSplitView;

/**
 * A split over a GtkBox holding one slot per pane, nested: slot k is a GtkPaned whose start child
 * is pane k and whose end child is a tail GtkBox holding slot k+1. A pane's widget is placed once
 * and never moved: an added pane's slot goes into the last tail, and a removed pane leaves its
 * slot hollow (no start child), passing the length on. So focus, realized views and the other
 * dividers survive an add or a removal.
 *
 * A GtkPaned shows its handle only while both children are visible, so the tails and slots are
 * shown only while some pane at or after them is: the divider after visible pane i is then the
 * handle of pane i's slot, and the last visible pane takes the rest of the length.
 *
 * Positions are the dividers' offsets (each slot's start plus its paned's position) over the
 * box's length. Positions set before the split has a length, or while it is unmapped (a hidden
 * tab page), wait until it is mapped with a length; the first layout otherwise shares the length
 * equally. A pane added later splits the last pane's share in half.
 *
 * GtkPaned drags its handle with a gesture of its own, which claims the pointer, so a legacy
 * controller on the box in the capture phase sees a mouse or touch press on a divider's handle
 * (by geometry: the panes overlap the handle) and its release: SplitMoved posts at the release if the positions moved and no code
 * moved them meanwhile. A window resize and a position set on the native post nothing.
 */
class GTKSplitView extends TKSplitView implements GTKContainer
{
    use GTKPrimitive;

    /** How often the layout wait and the divider placement check the layout, in ms. */
    public const int LAYOUT_CHECK_MS = 20;

    /** The most placing passes one setPositions() takes (half a second). */
    public const int PLACE_TRIES = 25;

    /** How far past its handle's rectangle GtkPaned takes a press for a drag, in px (its HANDLE_EXTRA_SIZE). */
    public const int HANDLE_GRAB = 6;

    /**
     * One per pane ever added, in order; a removed pane leaves its slot with no pane.
     * @var list<array{paned: GtkPaned, tail: GtkBox, pane: ?TKPrimitive}>
     */
    protected array $slots = [];

    /**
     * Fractions waiting for the split to be mapped with a length.
     * @var list<float>|null
     */
    protected ?array $pending_positions = null;

    /** The split has had its first layout with a length. */
    protected bool $laid_out = false;

    protected ?int $layout_source = null;

    /**
     * Fractions the dividers are being placed at. A GtkPaned whose children both resize scales
     * its position when its own allocation changes, so an inner paned is placed again after the
     * outer ones' moves are laid out, a pass per LAYOUT_CHECK_MS, until every divider is laid out
     * within a pixel of its target.
     * @var list<float>|null
     */
    protected ?array $target_positions = null;

    protected ?int $place_source = null;

    protected int $place_tries = 0;

    /**
     * The positions at a press on a handle, until its release.
     * @var list<float>|null
     */
    protected ?array $drag_from = null;

    /** Code moved the dividers while a handle was pressed: the release is not the user's move. */
    protected bool $moved_by_code = false;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param bool $horizontal
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, bool $horizontal)
    {
        parent::__construct($name, $window, $parent, $placement, $horizontal);
        $box = GtkBox::new($this->orientation(), 0);
        $this->adoptNative($box);

        $this->connect($box, 'map', function (): void {
            $this->awaitLayout();
        });
        $this->connect($box, 'unmap', function (): void {
            $this->stopSource($this->layout_source);
            $this->layout_source = null;
        });

        $legacy = GtkEventControllerLegacy::new();
        $legacy->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $this->connect($legacy, 'event', fn (GtkEventControllerLegacy $controller, GdkEvent $event): bool => $this->pointer($event));
        $box->addController($legacy);
    }

    public function native(): GtkBox
    {
        /** @var GtkBox */
        return $this->native;
    }

    /**
     * Whether the split is still laying out: waiting for its first length, or placing dividers.
     * @return bool
     */
    public function isSettling(): bool
    {
        return ! is_null($this->layout_source) || ! is_null($this->place_source);
    }

    public function removeNative(TKPrimitive $child): void
    {
        foreach ($this->slots as $k => $slot) {
            if ($slot['pane'] === $child) {
                $slot['paned']->setStartChild(null);
                $this->slots[$k]['pane'] = null;
            }
        }
        $this->codeMoved();
        $this->syncSlots();
    }

    protected function insertNative(TKPrimitive $child): void
    {
        $before = $this->laid_out ? $this->nativePositions() : null;

        $paned = GtkPaned::new($this->orientation());
        $paned->setResizeStartChild(true);
        $paned->setResizeEndChild(true);
        $paned->setShrinkStartChild(false);
        $paned->setShrinkEndChild(false);
        $tail = GtkBox::new($this->orientation(), 0);
        $tail->setHexpand(true);
        $tail->setVexpand(true);
        $paned->setHexpand(true);
        $paned->setVexpand(true);
        $paned->setStartChild(self::nativeOf($child));
        $paned->setEndChild($tail);

        $last = end($this->slots);
        $last === false ? $this->native()->append($paned) : $last['tail']->append($paned);
        $this->slots[] = ['paned' => $paned, 'tail' => $tail, 'pane' => $child];

        $widget = self::nativeOf($child);
        $this->connect($widget, 'notify::visible', function (): void {
            $this->codeMoved();
            $this->syncSlots();
        });
        $this->codeMoved();
        $this->syncSlots();

        // A pane added after the first layout splits the last pane's share in half.
        if (! is_null($before) && $child->isVisible() && $this->dividers() === count($before) + 1) {
            $this->applyPositions([...$before, ((end($before) ?: 0.0) + 1.0) / 2]);
        }
    }

    protected function nativePositions(): array
    {
        $count = $this->dividers();
        if ($count < 1) {
            return [];
        }
        if (! is_null($this->pending_positions) && count($this->pending_positions) === $count) {
            return $this->pending_positions;
        }
        if (! $this->laid_out) {
            return self::equalShares($count);
        }

        $length = $this->length();
        $positions = [];
        foreach ($this->dividerSlots() as $slot) {
            $positions[] = $length > 0.0 ? $this->dividerAt($slot['paned']) / $length : 0.0;
        }

        return $positions;
    }

    protected function applyPositions(array $positions): void
    {
        $this->codeMoved();
        if (! $this->laid_out || ! $this->native->getMapped() || $this->length() <= 0.0) {
            $this->pending_positions = $positions;
            $this->awaitLayout();

            return;
        }

        $this->pending_positions = null;
        $this->target_positions = $positions;
        $this->place_tries = 0;
        $this->placeDividers();
        if (is_null($this->place_source)) {
            $this->place_source = g_timeout_add(self::LAYOUT_CHECK_MS, function (): bool {
                if ($this->removed || is_null($this->target_positions) || $this->placed() || ++$this->place_tries >= self::PLACE_TRIES) {
                    $this->place_source = null;
                    $this->target_positions = null;

                    return false;
                }
                $this->placeDividers();

                return true;
            });
        }
    }

    protected function releaseNative(): void
    {
        $this->stopSource($this->layout_source);
        $this->stopSource($this->place_source);
        $this->layout_source = null;
        $this->place_source = null;
    }

    /**
     * Show each tail and slot only while some pane at or after it is visible, from the last
     * slot back: a visible GtkPaned with a hidden start child, or a hidden end child, shows no
     * handle and gives its other child the length.
     * @return void
     */
    protected function syncSlots(): void
    {
        $after = false;
        for ($k = count($this->slots) - 1; $k >= 0; $k--) {
            $slot = $this->slots[$k];
            $slot['tail']->setVisible($after);
            $after = $after || (! is_null($slot['pane']) && $slot['pane']->isVisible());
            $slot['paned']->setVisible($after);
        }
    }

    /**
     * The slots whose handle is a divider: a visible pane with a visible pane after it.
     * @return list<array{paned: GtkPaned, tail: GtkBox, pane: ?TKPrimitive}>
     */
    protected function dividerSlots(): array
    {
        $slots = [];
        foreach ($this->slots as $slot) {
            if (! is_null($slot['pane']) && $slot['pane']->isVisible() && $slot['tail']->getVisible()) {
                $slots[] = $slot;
            }
        }

        return $slots;
    }

    /**
     * Where a slot's divider sits along the split, in the box's coordinates: the slot's start
     * plus its paned's position (the start child's share, as laid out).
     *
     * @param GtkPaned $paned
     * @return float
     */
    protected function dividerAt(GtkPaned $paned): float
    {
        return $this->span($paned)[0] + $paned->getPosition();
    }

    /**
     * One pass over the target positions: each divider's paned takes the target less its own
     * allocated start along the split.
     * @return void
     */
    protected function placeDividers(): void
    {
        $length = $this->length();
        foreach ($this->dividerSlots() as $i => $slot) {
            if (! isset($this->target_positions[$i])) {
                break;
            }
            $position = max(0, (int) round($this->target_positions[$i] * $length - $this->span($slot['paned'])[0]));
            if ($slot['paned']->getPosition() !== $position) {
                $slot['paned']->setPosition($position);
            }
        }
    }

    /**
     * Whether every divider is laid out within a pixel of its target.
     * @return bool
     */
    protected function placed(): bool
    {
        $length = $this->length();
        foreach ($this->dividerSlots() as $i => $slot) {
            if (! isset($this->target_positions[$i]) || abs($this->dividerAt($slot['paned']) - $this->target_positions[$i] * $length) > 1.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * While the split is mapped, check every LAYOUT_CHECK_MS until it has a length, then lay the
     * dividers out: the pending positions, or equal shares at the first layout. Stops on unmap.
     * @return void
     */
    protected function awaitLayout(): void
    {
        if (! is_null($this->layout_source) || ! $this->native->getMapped() || ($this->laid_out && is_null($this->pending_positions))) {
            return;
        }

        $this->layout_source = g_timeout_add(self::LAYOUT_CHECK_MS, function (): bool {
            if ($this->removed || ! $this->native->getMapped()) {
                $this->layout_source = null;

                return false;
            }
            if ($this->length() <= 0.0) {
                return true;
            }

            $this->layout_source = null;
            $first = ! $this->laid_out;
            $this->laid_out = true;
            $dividers = $this->dividers();
            $pending = $this->pending_positions;
            $this->pending_positions = null;
            if ($dividers > 0 && ! is_null($pending) && count($pending) === $dividers) {
                $this->applyPositions($pending);
            } elseif ($dividers > 0 && $first) {
                $this->applyPositions(self::equalShares($dividers));
            }

            return false;
        });
    }

    /**
     * A left press or a touch on one of the slots' handles notes the positions and stops any
     * placement under way; its release posts SplitMoved if they moved by more than half a pixel
     * and no code moved them meanwhile. The event always goes on to the widgets.
     *
     * @param GdkEvent $event
     * @return bool
     */
    protected function pointer(GdkEvent $event): bool
    {
        if ($this->removed) {
            return false;
        }

        $type = $event->getEventType();
        $mouse = $event instanceof GdkButtonEvent && $event->getButton() === 1;
        $touch = $event instanceof GdkTouchEvent;
        if (($type === GdkEventType::BUTTON_PRESS && $mouse) || ($type === GdkEventType::TOUCH_BEGIN && $touch)) {
            if (! $this->onHandle($event)) {
                return false;
            }
            $this->stopSource($this->place_source);
            $this->place_source = null;
            $this->target_positions = null;
            $this->drag_from = $this->nativePositions();
            $this->moved_by_code = false;
        } elseif ((($type === GdkEventType::BUTTON_RELEASE && $mouse) || ($type === GdkEventType::TOUCH_END && $touch)) && ! is_null($this->drag_from)) {
            $from = $this->drag_from;
            $this->drag_from = null;
            if ($this->moved_by_code) {
                return false;
            }
            $to = $this->nativePositions();
            $length = $this->length();
            $moved = count($to) !== count($from);
            foreach ($to as $i => $position) {
                $moved = $moved || abs($position - $from[$i]) * $length > 0.5;
            }
            if ($moved) {
                $this->session()->post(new SplitMoved($this->window->name(), $this->path(), $this->uuid, $to));
            }
        } elseif ($type === GdkEventType::TOUCH_CANCEL) {
            $this->drag_from = null;
        }

        return false;
    }

    /**
     * Whether the event lands on a divider's handle: its point, from the window's surface into
     * the box's coordinates, tested by handleAt().
     *
     * @param GdkEvent $event
     * @return bool
     */
    protected function onHandle(GdkEvent $event): bool
    {
        $root = $this->native->getNative();
        if (! $root instanceof GtkWidget || ! $event->getPosition($x, $y)) {
            return false;
        }

        $root->getSurfaceTransform($dx, $dy);
        if (! $root->computePoint($this->native, new GraphenePoint($x - $dx, $y - $dy), $point)) {
            return false;
        }

        return $this->handleAt($point->x, $point->y);
    }

    /**
     * Whether ($x, $y), in the box's coordinates, is on a divider's handle as GtkPaned grabs it:
     * the handle's laid-out rectangle widened by HANDLE_GRAB on each side along the split. The
     * panes overlap the handle, so picking the widget under the point finds a pane, not it.
     *
     * @param float $x
     * @param float $y
     * @return bool
     */
    protected function handleAt(float $x, float $y): bool
    {
        [$along, $across] = $this->horizontal ? [$x, $y] : [$y, $x];
        foreach ($this->dividerSlots() as $slot) {
            $paned = $slot['paned'];
            for ($child = $paned->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
                if ($child === $paned->getStartChild() || $child === $paned->getEndChild() || ! $child->computeBounds($this->native, $bounds)) {
                    continue;
                }
                [$start, $length, $cross, $span] = $this->horizontal
                    ? [$bounds->origin->x, $bounds->size->width, $bounds->origin->y, $bounds->size->height]
                    : [$bounds->origin->y, $bounds->size->height, $bounds->origin->x, $bounds->size->width];
                if ($along >= $start - self::HANDLE_GRAB && $along <= $start + $length + self::HANDLE_GRAB && $across >= $cross && $across <= $cross + $span) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Code is moving the dividers: a handle pressed now is not the user's move at its release.
     * @return void
     */
    protected function codeMoved(): void
    {
        if (! is_null($this->drag_from)) {
            $this->moved_by_code = true;
        }
    }

    protected function length(): float
    {
        return (float) ($this->horizontal ? $this->native->getWidth() : $this->native->getHeight());
    }

    /**
     * Where a widget starts and ends along the split, in the box's coordinates.
     *
     * @param GtkWidget $widget
     * @return array{float, float}
     */
    protected function span(GtkWidget $widget): array
    {
        if (! $widget->computeBounds($this->native, $bounds)) {
            return [0.0, 0.0];
        }

        return $this->horizontal
            ? [$bounds->origin->x, $bounds->origin->x + $bounds->size->width]
            : [$bounds->origin->y, $bounds->origin->y + $bounds->size->height];
    }

    protected function orientation(): GtkOrientation
    {
        return $this->horizontal ? GtkOrientation::HORIZONTAL : GtkOrientation::VERTICAL;
    }

    protected function stopSource(?int $source): void
    {
        if (! is_null($source)) {
            g_source_remove($source);
        }
    }

    /**
     * @param int $dividers
     * @return list<float>
     */
    protected static function equalShares(int $dividers): array
    {
        return array_map(fn (int $i): float => ($i + 1) / ($dividers + 1), range(0, $dividers - 1));
    }
}

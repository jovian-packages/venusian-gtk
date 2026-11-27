<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use Closure;
use GdkDisplay;
use GrapheneRect;
use GtkAlign;
use GtkOverflow;
use GtkCssProvider;
use GtkGestureClick;
use GtkPropagationPhase;
use GtkEventSequenceState;
use GtkWidget;
use RuntimeException;
use WeakReference;
use Jovian\Toolkits\GTK\GtkWindow;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKFixed;
use Surface\Toolkits\UI\TKOverlay;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Cursor;
use Surface\Contracts\Toolkits\UI\Corners;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\Contracts\Toolkits\UI\TKTransition;

/**
 * What every GTK primitive does the same way with its widget.
 *
 * Styling: GTK 4 styles widgets through CSS only, and per-widget style providers are gone, so
 * each primitive's widget carries a CSS class named after its uuid and one display-wide
 * provider holds that class's rule. The rule is rebuilt from the declarations kept here
 * whenever one changes (background, corner radius, padding, font, text colour). Margin and
 * border are the widget's own node only, never an inner node styleSelector() adds.
 *
 * Transitions: a CSS transition GTK runs itself. PHP only adds and removes two classes and
 * sets one-shot timeouts: GTK has no "transition ended" signal.
 */
trait GtkNative
{
    protected ?GtkWidget $native = null;

    /** @var array<string, string> CSS declarations by property, for styleSelector() */
    private array $declarations = [];

    /** @var array<string, string> CSS declarations by property, for the widget's own node */
    private array $widget_declarations = [];

    private ?GtkCssProvider $style_provider = null;

    /** The widget's overflow before the first setClip(). */
    private ?GtkOverflow $built_overflow = null;

    private ?GtkCssProvider $transition_provider = null;

    /** @var array<int, true> pending transition timeouts, by source tag */
    private array $transition_timeouts = [];

    /** A one-shot map handler waiting to start an appear transition. */
    private ?int $map_handler = null;

    /** Listens for primary clicks while a group is clickable. */
    private ?GtkGestureClick $group_clicks = null;

    /** @var list<array{object, int}> signal handlers this primitive connected */
    private array $handlers = [];

    /**
     * The widget, for the container this primitive is in.
     *
     * @throws RuntimeException Once removed.
     */
    public function native(): GtkWidget
    {
        return $this->native ?? throw new RuntimeException("'{$this->path()}' is removed.");
    }

    /**
     * Take $native as this primitive's widget: it gets the primitive's style class.
     */
    protected function adoptNative(GtkWidget $native): void
    {
        $this->native = $native;
        $native->addCssClass($this->styleClass());
    }

    /**
     * Connect a handler for as long as the primitive lives. The handler should hold the
     * primitive weakly: a strong capture would be a cycle through the GObject.
     */
    protected function connect(object $instance, string $signal, Closure $handler): void
    {
        $this->handlers[] = [$instance, g_signal_connect($instance, $signal, $handler)];
    }

    protected function releaseNative(): void
    {
        $this->stopTransition();
        $this->applyClickable(false);

        foreach ($this->handlers as [$instance, $id]) {
            if (g_signal_handler_is_connected($instance, $id)) {
                g_signal_handler_disconnect($instance, $id);
            }
        }
        $this->handlers = [];

        if (! is_null($this->style_provider)) {
            gtk_style_context_remove_provider_for_display(GdkDisplay::getDefault(), $this->style_provider);
            $this->style_provider = null;
        }

        // The container took the widget out before this; GTK frees it with its last reference.
        $this->native = null;
    }

    protected function applyVisible(bool $visible, ?TKTransition $transition, float $seconds): void
    {
        $this->stopTransition();

        if (is_null($transition) || $seconds <= 0.0) {
            $this->native->setVisible($visible);

            return;
        }

        $this->playTransition($visible, $transition, $seconds);
    }

    /**
     * The widget's own flag. GTK greys everything inside an insensitive container itself.
     */
    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    protected function applyOpacity(float $opacity): void
    {
        $this->native->setOpacity($opacity);
    }

    protected function nativeFocus(): bool
    {
        return $this->native->grabFocus();
    }

    /**
     * Whether this widget is its window's focus widget. GTK's own hasFocus() is false whenever
     * the window is inactive, though the window keeps this widget as its focus.
     */
    public function hasFocus(): bool
    {
        return ! is_null($this->native) && $this->native->getRoot()?->getFocus() === $this->native;
    }

    /**
     * The border box, padding included; getWidth() and getHeight() give the content box inside it.
     *
     * @return array{int, int}
     */
    public function size(): array
    {
        $bounds = null;
        if (is_null($this->native) || ! $this->native->computeBounds($this->native, $bounds) || ! $bounds instanceof GrapheneRect) {
            return [0, 0];
        }

        return [(int) round($bounds->size->width), (int) round($bounds->size->height)];
    }

    protected function applyBackground(?Color $color): void
    {
        $this->declare('background-color', $color?->toCss());
        $this->declare('background-image', is_null($color) ? null : 'none');
    }

    /**
     * One radius on every corner: the same declaration applyCorners() writes.
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->applyCorners(Corners::of($radius));
    }

    /**
     * One four-value border-radius, top-left, top-right, bottom-right, bottom-left (never the
     * shorthand mixed with longhands). It rounds the background and border; what is inside is
     * cut to it only while the primitive clips.
     */
    protected function applyCorners(Corners $corners): void
    {
        $this->declare('border-radius', $corners->isZero()
            ? null
            : implode(' ', array_map(static fn (float $r): string => "{$r}px", $corners->toArray())));
    }

    /**
     * GTK's overflow: hidden cuts the children at the widget's rounded edge; false gives the
     * widget back the overflow it was built with (a scrolled window or a notebook clips of
     * its own).
     */
    protected function applyClip(bool $clip): void
    {
        $this->built_overflow ??= $this->native->getOverflow();
        $this->native->setOverflow($clip ? GtkOverflow::HIDDEN : $this->built_overflow);
    }

    /**
     * CSS margin, outside the border and the background; physical sides, as Insets are. Every
     * GTK container lays a child out by its margin box.
     */
    protected function applyMargin(Insets $margin): void
    {
        $this->declareOnWidget('margin', $margin->isZero() ? null : self::cssSides($margin));
    }

    /**
     * GTK's cursor by its CSS name; null unsets it, so the widget shows its parent's (or its
     * own inner node's, as an entry's text beam).
     */
    protected function applyCursor(?Cursor $cursor): void
    {
        $this->native->setCursorFromName(match ($cursor) {
            null => null,
            Cursor::ARROW => 'default',
            Cursor::POINTER => 'pointer',
            Cursor::TEXT => 'text',
            Cursor::CROSSHAIR => 'crosshair',
            Cursor::MOVE => 'move',
            Cursor::NOT_ALLOWED => 'not-allowed',
            Cursor::GRAB => 'grab',
            Cursor::GRABBING => 'grabbing',
            Cursor::RESIZE_HORIZONTAL => 'ew-resize',
            Cursor::RESIZE_VERTICAL => 'ns-resize',
            Cursor::RESIZE_NWSE => 'nwse-resize',
            Cursor::RESIZE_NESW => 'nesw-resize',
        });
    }

    /**
     * GTK 4 has no resize signal for a widget: the window compares the size after each pump,
     * for the views that are registered with it.
     */
    protected function applyWatchSize(bool $on): void
    {
        $window = $this->window();
        if (! $window instanceof GtkWindow) {
            return;
        }

        $on ? $window->watchSize($this) : $window->unwatchSize($this);
    }

    /**
     * @internal GtkWindow, after each pump
     */
    public function settled(): void
    {
        $this->sizeChanged();
    }

    protected function applyTooltip(?string $tooltip): void
    {
        $this->native->setTooltipText($tooltip);
    }

    /**
     * A solid CSS border on the widget's own node. A null colour is the theme's @borders, which
     * every theme GTK ships defines; width 0 drops the declaration and the theme's own border
     * (a button's frame) is back.
     */
    protected function applyBorder(int $width, ?Color $color): void
    {
        $this->declareOnWidget('border', $width === 0 ? null : "{$width}px solid ".($color?->toCss() ?? '@borders'));
    }

    /**
     * In an overlay, GTK places a child by its align alone (expand means nothing there): a
     * child that fills a way is FILL that way.
     */
    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->native->setHexpand($horizontal);
        $this->native->setVexpand($vertical);

        if ($this->parent() instanceof TKOverlay) {
            $this->applyAlign(...$this->aligned());
        }
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        if ($this->parent() instanceof TKOverlay) {
            [$fillsAcross, $fillsDown] = $this->fills();
            $horizontal = $fillsAcross ? Align::FILL : $horizontal;
            $vertical = $fillsDown ? Align::FILL : $vertical;
        }

        $this->native->setHalign(self::gtkAlign($horizontal));
        $this->native->setValign(self::gtkAlign($vertical));
    }

    /**
     * Inside a fixed the frame sizes the widget too; the larger of the two wins.
     */
    protected function applyMinSize(int $width, int $height): void
    {
        if ($this->parent() instanceof TKFixed) {
            $frame = $this->parent()->frameOf($this);
            $width = max($width, $frame->width);
            $height = max($height, $frame->height);
        }

        $this->native->setSizeRequest($width, $height);
    }

    /**
     * A group's clicks: a GtkGestureClick on primary presses in the bubble phase, so a control
     * inside (button, entry, check box) claims its own press or release first, which cancels
     * this one. This one claims the release it takes, so a clickable group around it stays
     * quiet. The release posts, at the point from the widget's top-left (GTK's coordinates
     * start inside its border and padding).
     */
    protected function applyClickable(bool $on): void
    {
        if (! $on) {
            if (! is_null($this->group_clicks) && ! is_null($this->native)) {
                $this->native->removeController($this->group_clicks);
            }
            $this->group_clicks = null;

            return;
        }

        $clicks = GtkGestureClick::new();
        $clicks->setButton(1);
        $clicks->setPropagationPhase(GtkPropagationPhase::BUBBLE);
        $self = WeakReference::create($this);
        g_signal_connect($clicks, 'released', static function (GtkGestureClick $clicks, int $presses, float $x, float $y) use ($self): void {
            $group = $self->get();
            if (is_null($group) || is_null($group->native)) {
                return;
            }
            $clicks->setState(GtkEventSequenceState::CLAIMED);
            $bounds = null;
            $group->native->computeBounds($group->native, $bounds);
            $group->groupClicked($x - ($bounds?->origin->x ?? 0.0), $y - ($bounds?->origin->y ?? 0.0));
        });
        $this->native->addController($clicks);
        $this->group_clicks = $clicks;
    }

    /**
     * Set one CSS declaration of this primitive's rule; null removes it.
     */
    protected function declare(string $property, ?string $value): void
    {
        if (is_null($value)) {
            unset($this->declarations[$property]);
        } else {
            $this->declarations[$property] = $value;
        }

        $this->restyle();
    }

    /**
     * As declare(), on the widget's own node only.
     */
    protected function declareOnWidget(string $property, ?string $value): void
    {
        if (is_null($value)) {
            unset($this->widget_declarations[$property]);
        } else {
            $this->widget_declarations[$property] = $value;
        }

        $this->restyle();
    }

    /**
     * CSS's four-side shorthand, top, right, bottom, left.
     */
    protected static function cssSides(Insets $insets): string
    {
        return "{$insets->top}px {$insets->right}px {$insets->bottom}px {$insets->left}px";
    }

    protected function styleClass(): string
    {
        return 'tk-'.$this->uuid();
    }

    /**
     * What its rule styles: the widget. A primitive whose inner node paints adds that.
     */
    protected function styleSelector(): string
    {
        return ".{$this->styleClass()}";
    }

    /**
     * Replace this primitive's provider with one holding its rule as it is now.
     */
    private function restyle(): void
    {
        $display = GdkDisplay::getDefault();

        if (! is_null($this->style_provider)) {
            gtk_style_context_remove_provider_for_display($display, $this->style_provider);
            $this->style_provider = null;
        }

        if ($this->declarations === [] && $this->widget_declarations === []) {
            return;
        }

        $css = '';
        foreach ([[$this->styleSelector(), $this->declarations], [".{$this->styleClass()}", $this->widget_declarations]] as [$selector, $declarations]) {
            if ($declarations === []) {
                continue;
            }
            $css .= "{$selector} { ";
            foreach ($declarations as $property => $value) {
                $css .= "{$property}: {$value}; ";
            }
            $css .= '} ';
        }

        $this->style_provider = GtkCssProvider::new();
        $this->style_provider->loadFromString($css);
        gtk_style_context_add_provider_for_display($display, $this->style_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    }

    /**
     * tk-away is the state the widget comes from and goes to; tk-moving carries the CSS
     * transition.
     *
     * A fade or slide: tk-away is transparent and offset by the transition's points, eased
     * out both ways. A move: tk-away is offset by the widget's own width or height toward its
     * edge, opacity untouched, on topside's curve (curve(): easeOutQuint appearing, easeInQuint
     * disappearing) as a CSS cubic-bezier(). The distance is read from the allocation: a
     * widget going away has one; one appearing is laid out only once shown, so it starts a
     * window's size away and takes its own size once allocated.
     *
     * Appearing: the widget is shown at tk-away, and tk-away is dropped once GTK has drawn it
     * there, so GTK animates from it (dropped at once, GTK draws the end state with no
     * animation; observed). Disappearing: both classes go on, and a timeout hides the widget
     * when the transition has run.
     */
    private function playTransition(bool $visible, TKTransition $transition, float $seconds): void
    {
        $duration = max(1, (int) round($seconds * 1000));

        if (! $visible) {
            $this->loadTransition($transition, $seconds, false, $this->size());
            $this->native->addCssClass('tk-moving');
            $this->native->addCssClass('tk-away');
            $this->after($duration, static function (self $primitive): void {
                $primitive->native->setVisible(false);
                $primitive->stopTransition();
            });

            return;
        }

        $this->loadTransition($transition, $seconds, true, $this->window()->size());
        $this->native->addCssClass('tk-away');
        $this->native->setVisible(true);

        $start = static function (self $primitive) use ($duration): void {
            $primitive->native->addCssClass('tk-moving');
            $primitive->native->removeCssClass('tk-away');
            $primitive->after($duration, static fn (self $primitive) => $primitive->stopTransition());
        };

        // A move starts from its own size, known once GTK has laid it out; then another frame
        // for GTK to draw it there.
        $begin = $transition->moves()
            ? static function (self $primitive) use ($transition, $seconds, $start): void {
                $primitive->loadTransition($transition, $seconds, true, $primitive->size());
                $primitive->after(50, $start);
            }
            : $start;

        // GTK animates a style change only from a style it has drawn: the widget waits for its
        // map, then a frame or so (50 ms, the delay that was observed to animate).
        if ($this->native->getMapped()) {
            $this->after(50, $begin);

            return;
        }

        $self = WeakReference::create($this);
        $this->map_handler = g_signal_connect($this->native, 'map', static function () use ($self, $begin): void {
            $primitive = $self->get();
            if (is_null($primitive) || is_null($primitive->native)) {
                return;
            }
            g_signal_handler_disconnect($primitive->native, $primitive->map_handler);
            $primitive->map_handler = null;
            $primitive->after(50, $begin);
        });
    }

    /**
     * The transition's two rules, in a provider of their own that replaces any before it.
     *
     * @param array{int, int} $size what a move travels: width, height
     */
    private function loadTransition(TKTransition $transition, float $seconds, bool $appearing, array $size): void
    {
        $class = '.'.$this->styleClass();
        $edge = $transition->edge();

        if (is_null($edge)) {
            [$dx, $dy] = $transition->offset();
            $css = "{$class}.tk-moving { transition: opacity {$seconds}s ease-out, transform {$seconds}s ease-out; } "
                ."{$class}.tk-away { opacity: 0; transform: translate({$dx}px, {$dy}px); }";
        } else {
            $dx = $edge[0] * $size[0];
            $dy = $edge[1] * $size[1];
            // PHP 8 writes a float with a dot whatever the locale.
            $ease = 'cubic-bezier('.implode(', ', array_map(static fn (float $v): string => (string) $v, $transition->curve($appearing))).')';
            $css = "{$class}.tk-moving { transition: transform {$seconds}s {$ease}; } "
                ."{$class}.tk-away { transform: translate({$dx}px, {$dy}px); }";
        }

        $display = GdkDisplay::getDefault();
        if (! is_null($this->transition_provider)) {
            gtk_style_context_remove_provider_for_display($display, $this->transition_provider);
        }
        $this->transition_provider = GtkCssProvider::new();
        $this->transition_provider->loadFromString($css);
        gtk_style_context_add_provider_for_display($display, $this->transition_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    }

    /**
     * Run $then with this primitive after $milliseconds, unless the transition is stopped first.
     *
     * @param Closure(self): void $then
     */
    private function after(int $milliseconds, Closure $then): void
    {
        $self = WeakReference::create($this);
        $tag = 0;
        $tag = g_timeout_add($milliseconds, static function () use ($self, &$tag, $then): bool {
            $primitive = $self->get();
            if (! is_null($primitive) && isset($primitive->transition_timeouts[$tag])) {
                unset($primitive->transition_timeouts[$tag]);
                if (! is_null($primitive->native)) {
                    $then($primitive);
                }
            }

            return G_SOURCE_REMOVE;
        });
        $this->transition_timeouts[$tag] = true;
    }

    /**
     * Back to rest: pending timeouts and a waiting map handler are dropped, the classes and
     * the transition's provider go.
     */
    private function stopTransition(): void
    {
        foreach (array_keys($this->transition_timeouts) as $tag) {
            g_source_remove($tag);
        }
        $this->transition_timeouts = [];

        if (is_null($this->native)) {
            return;
        }

        if (! is_null($this->map_handler)) {
            g_signal_handler_disconnect($this->native, $this->map_handler);
            $this->map_handler = null;
        }

        $this->native->removeCssClass('tk-moving');
        $this->native->removeCssClass('tk-away');

        if (! is_null($this->transition_provider)) {
            gtk_style_context_remove_provider_for_display(GdkDisplay::getDefault(), $this->transition_provider);
            $this->transition_provider = null;
        }
    }

    private static function gtkAlign(Align $align): GtkAlign
    {
        return match ($align) {
            Align::START => GtkAlign::START,
            Align::CENTER => GtkAlign::CENTER,
            Align::END => GtkAlign::END,
            Align::FILL => GtkAlign::FILL,
        };
    }
}

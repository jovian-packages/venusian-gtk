<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use Closure;
use GdkDisplay;
use GrapheneRect;
use GtkAlign;
use GtkCssProvider;
use GtkWidget;
use RuntimeException;
use WeakReference;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKFixed;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\TKTransition;

/**
 * What every GTK primitive does the same way with its widget.
 *
 * Styling: GTK 4 styles widgets through CSS only, and per-widget style providers are gone, so
 * each primitive's widget carries a CSS class named after its uuid and one display-wide
 * provider holds that class's rule. The rule is rebuilt from the declarations kept here
 * whenever one changes (background, corner radius, padding, font, text colour).
 *
 * Transitions: a CSS transition GTK runs itself. PHP only adds and removes two classes and
 * sets one-shot timeouts: GTK has no "transition ended" signal.
 */
trait GtkNative
{
    protected ?GtkWidget $native = null;

    /** @var array<string, string> CSS declarations by property */
    private array $declarations = [];

    private ?GtkCssProvider $style_provider = null;

    private ?GtkCssProvider $transition_provider = null;

    /** @var array<int, true> pending transition timeouts, by source tag */
    private array $transition_timeouts = [];

    /** A one-shot map handler waiting to start an appear transition. */
    private ?int $map_handler = null;

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
     * Rounds the background; what is inside is not clipped.
     */
    protected function applyCornerRadius(float $radius): void
    {
        $this->declare('border-radius', $radius > 0.0 ? "{$radius}px" : null);
    }

    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->native->setHexpand($horizontal);
        $this->native->setVexpand($vertical);
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
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

        if ($this->declarations === []) {
            return;
        }

        $body = '';
        foreach ($this->declarations as $property => $value) {
            $body .= "{$property}: {$value}; ";
        }

        $this->style_provider = GtkCssProvider::new();
        $this->style_provider->loadFromString("{$this->styleSelector()} { {$body}}");
        gtk_style_context_add_provider_for_display($display, $this->style_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    }

    /**
     * tk-away is the transparent, offset state; tk-moving carries the CSS transition.
     *
     * Appearing: the widget is shown at tk-away, and tk-away is dropped once GTK has drawn it
     * there, so GTK animates from it (dropped at once, GTK draws the end state with no
     * animation; observed). Disappearing: both classes go on, and a timeout hides the widget
     * when the transition has run.
     */
    private function playTransition(bool $visible, TKTransition $transition, float $seconds): void
    {
        [$dx, $dy] = $transition->offset();
        $class = '.'.$this->styleClass();

        $this->transition_provider = GtkCssProvider::new();
        $this->transition_provider->loadFromString(
            "{$class}.tk-moving { transition: opacity {$seconds}s ease-out, transform {$seconds}s ease-out; } "
            ."{$class}.tk-away { opacity: 0; transform: translate({$dx}px, {$dy}px); }"
        );
        gtk_style_context_add_provider_for_display(GdkDisplay::getDefault(), $this->transition_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);

        $duration = max(1, (int) round($seconds * 1000));

        if (! $visible) {
            $this->native->addCssClass('tk-moving');
            $this->native->addCssClass('tk-away');
            $this->after($duration, static function (self $primitive): void {
                $primitive->native->setVisible(false);
                $primitive->stopTransition();
            });

            return;
        }

        $this->native->addCssClass('tk-away');
        $this->native->setVisible(true);

        $begin = static function (self $primitive) use ($duration): void {
            $primitive->native->addCssClass('tk-moving');
            $primitive->native->removeCssClass('tk-away');
            $primitive->after($duration, static fn (self $primitive) => $primitive->stopTransition());
        };

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

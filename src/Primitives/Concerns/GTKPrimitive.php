<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use Closure;
use GdkDisplay;
use GObject;
use GtkAlign;
use GtkCssProvider;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKContainer;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\GTKFixed;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\Primitives\TKPrimitive;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

/**
 * The hooks every GTK primitive shares. The concrete builds its widget after the abstract's
 * constructor and hands it to adoptNative(). Styling is one GtkCssProvider per primitive,
 * scoped to the class tk-<uuid> on its widget and replaced whole on every style change.
 * Every signal handler goes through connect(), so removal disconnects them all.
 */
trait GTKPrimitive
{
    protected GtkWidget $native;

    protected ?GtkCssProvider $style_provider = null;

    /**
     * The stylesheet the provider holds, as loaded.
     */
    protected ?string $stylesheet = null;

    /**
     * @var list<array{GObject, int}> instance and handler id for every connected signal
     */
    protected array $handlers = [];

    /**
     * True while a code-driven change is written to the widget: the signal GTK emits for it
     * is the engine echoing our own write, so handlers record nothing and post nothing.
     */
    protected bool $applying = false;

    public function native(): GtkWidget
    {
        return $this->native;
    }

    /**
     * The CSS class that scopes this primitive's style provider.
     * @return string
     */
    public function styleClass(): string
    {
        return 'tk-'.$this->uuid;
    }

    public function syncSizeRequest(): void
    {
        [$width, $height] = $this->min_size ?? [-1, -1];
        if ($this->parent instanceof GTKFixed) {
            $frame = $this->parent->frameOf($this);
            $width = max($width, $frame->width);
            $height = max($height, $frame->height);
        }
        $this->native->setSizeRequest($width, $height);
    }

    /**
     * Take the freshly built widget and tag it with the style class.
     *
     * @param GtkWidget $native
     * @return void
     */
    protected function adoptNative(GtkWidget $native): void
    {
        $this->native = $native;
        $native->addCssClass($this->styleClass());
    }

    /**
     * @return GTKWindow
     * @throws WindowException When the primitive was minted for another driver's window.
     */
    protected function host(): GTKWindow
    {
        return $this->window instanceof GTKWindow
            ? $this->window
            : throw new WindowException("'{$this->path()}' belongs to a ".get_debug_type($this->window).', not a GTK window.');
    }

    protected function session(): GTKSession
    {
        return $this->host()->session();
    }

    /**
     * Connect a handler that lives as long as the primitive.
     *
     * @param GObject $instance
     * @param string $signal
     * @param Closure $handler
     * @return void
     */
    protected function connect(GObject $instance, string $signal, Closure $handler): void
    {
        $this->handlers[] = [$instance, g_signal_connect($instance, $signal, $handler)];
    }

    /**
     * Write to the widget without the echo signal posting mail.
     *
     * @param Closure(): void $write
     * @return void
     */
    protected function quietly(Closure $write): void
    {
        $this->applying = true;
        try {
            $write();
        } finally {
            $this->applying = false;
        }
    }

    /**
     * Post interaction mail from a native signal handler. Nothing while a code-driven write is
     * echoing, and nothing from a widget that is not effectively sensitive (disabled itself or
     * inside a disabled container): GTK blocks user input there, and a programmatic activation
     * of a disabled control is not the user's either.
     *
     * @param object $mail
     * @return void
     */
    protected function post(object $mail): void
    {
        if (! $this->applying && $this->native->isSensitive()) {
            $this->session()->post($mail);
        }
    }

    protected function applyVisible(bool $visible): void
    {
        $this->native->setVisible($visible);
    }

    /**
     * GTK sensitivity cascades: an insensitive container greys every widget inside it.
     *
     * @param bool $enabled
     * @return void
     */
    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    protected function applyBackground(?Color $color): void
    {
        $this->restyle();
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

    protected function applyMinSize(int $width, int $height): void
    {
        $this->syncSizeRequest();
    }

    protected function applyWatchSize(bool $on): void
    {
        $on ? $this->session()->watch($this) : $this->session()->unwatch($this);
    }

    /**
     * The widget's border box, which includes its CSS padding and border; get_width() and
     * get_height() would report the content box inside them.
     * @return array{int, int}
     */
    protected function nativeSize(): array
    {
        [, , $width, $height] = $this->native->computeBounds($this->native) ?? [0.0, 0.0, 0.0, 0.0];

        return [(int) round($width), (int) round($height)];
    }

    /**
     * Disconnect every handler, let the concrete release what it holds, take the widget out of
     * its container (or the window), and drop the style provider.
     * @return void
     */
    protected function destroyNative(): void
    {
        foreach ($this->handlers as [$instance, $id]) {
            if (g_signal_handler_is_connected($instance, $id)) {
                g_signal_handler_disconnect($instance, $id);
            }
        }
        $this->handlers = [];
        $this->releaseNative();

        if (is_null($this->parent)) {
            $this->host()->unmountContent($this->native);
        } elseif ($this->parent instanceof GTKContainer) {
            $this->parent->removeNative($this);
        }

        $this->dropStyle();
    }

    /**
     * What a concrete holds beyond its signal handlers (timers, media). Called once from destroyNative().
     * @return void
     */
    protected function releaseNative(): void {}

    /**
     * Child nodes, relative to this primitive's widget (child combinators, so nothing nested
     * further in is reached), that the theme paints with its own background or colour and
     * that must take this primitive's instead: the entry's text, the text view inside a text
     * area, the column view inside a table. Empty for every other kind.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return [];
    }

    /**
     * Rebuild this primitive's stylesheet from its current state and swap the provider.
     * Background, text colour and font where the kind has them; padding for containers.
     * Background and colour also go to the kind's styleTargets().
     * @return void
     */
    protected function restyle(): void
    {
        $this->dropStyle();

        $paint = [];
        $background = $this->background;
        if (! is_null($background)) {
            $paint[] = "background-color: {$background->toCss()};";
            $paint[] = 'background-image: none;';
        }

        $text_color = property_exists($this, 'text_color') ? $this->text_color : null;
        if ($text_color instanceof Color) {
            $paint[] = "color: {$text_color->toCss()};";
        }

        $declarations = $paint;

        $font = property_exists($this, 'font') ? $this->font : null;
        if ($font instanceof FontSpec) {
            $declarations[] = sprintf('font-size: %spx;', rtrim(rtrim(number_format($font->size, 2, '.', ''), '0'), '.'));
            $declarations[] = "font-weight: {$font->weight->toCssWeight()};";
            if (! is_null($font->family)) {
                $declarations[] = 'font-family: '.self::cssString($font->family).';';
            }
        }

        $padding = property_exists($this, 'padding') ? $this->padding : 0;
        if (is_int($padding) && $padding > 0) {
            $declarations[] = "padding: {$padding}px;";
        }

        if ($declarations === []) {
            return;
        }

        $selector = '.'.$this->styleClass();
        $css = $selector.' { '.implode(' ', $declarations).' }';
        if ($paint !== []) {
            foreach ($this->styleTargets() as $target) {
                $css .= " {$selector} {$target} { ".implode(' ', $paint).' }';
            }
        }

        $this->stylesheet = $css;
        $this->style_provider = GtkCssProvider::new();
        $this->style_provider->loadFromString($css);
        gtk_style_context_add_provider_for_display(GdkDisplay::getDefault(), $this->style_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    }

    /**
     * The stylesheet this primitive's provider holds, or null when it has none.
     * @return string|null
     */
    public function stylesheet(): ?string
    {
        return $this->stylesheet;
    }

    protected function dropStyle(): void
    {
        if (! is_null($this->style_provider)) {
            gtk_style_context_remove_provider_for_display(GdkDisplay::getDefault(), $this->style_provider);
            $this->style_provider = null;
            $this->stylesheet = null;
        }
    }

    /**
     * A CSS string literal: quotes, backslashes and control characters as hex escapes, so no
     * value can end the string or the declaration early.
     *
     * @param string $value
     * @return string
     */
    protected static function cssString(string $value): string
    {
        return '"'.preg_replace_callback('/["\\\\\x00-\x1f\x7f]/', fn (array $match): string => sprintf('\\%x ', ord($match[0])), $value).'"';
    }

    /**
     * @param TKPrimitive $child
     * @return GtkWidget
     * @throws WindowException When $child is not a GTK primitive.
     */
    protected static function nativeOf(TKPrimitive $child): GtkWidget
    {
        return $child instanceof GTKView
            ? $child->native()
            : throw new WindowException("'{$child->path()}' is a ".get_debug_type($child).', not a GTK primitive.');
    }

    protected static function gtkAlign(Align $align): GtkAlign
    {
        return match ($align) {
            Align::START => GtkAlign::START,
            Align::CENTER => GtkAlign::CENTER,
            Align::END => GtkAlign::END,
            Align::FILL => GtkAlign::FILL,
        };
    }
}

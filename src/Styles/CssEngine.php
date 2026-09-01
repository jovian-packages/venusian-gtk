<?php

namespace Jovian\Venusian\GTK\Styles;

use Jovian\Bindings\Gtk\Gtk\GtkCssProvider;
use Jovian\Bindings\Gtk\Gtk\GtkStyleContext;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Exceptions\GTKWindowException;

/**
 * One CSS provider per window, attached to the window's display, holding a
 * rule block per styled view. GTK's own styling model IS CSS, so every
 * Surface style — colour, font, background — funnels through here rather
 * than per-widget setters that mostly do not exist.
 *
 * Each styled widget gets a class `v-<window>-<view>` (non-alphanumerics
 * dashed); a declaration change rebuilds the stylesheet and reloads the
 * provider, which restyles live.
 */
class CssEngine
{
    protected ?GtkCssProvider $provider = null;

    /** @var array<string, array<string, string>> class => property => value */
    protected array $rules = [];

    public function __construct(
        protected string $window_name,
    ) {}

    /**
     * The CSS class for a view, adding it to the widget on first styling.
     */
    public function classFor(string $view_name): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', "{$this->window_name}-{$view_name}") ?? '');

        return "v-{$slug}";
    }

    /**
     * Set one declaration on a view's rule block and restyle.
     */
    public function declare(GtkWidget $widget, string $view_name, string $property, string $value): void
    {
        $class = $this->classFor($view_name);

        if (! isset($this->rules[$class])) {
            $widget->addCssClass($class);
        }
        $this->rules[$class][$property] = $value;

        $this->reload($widget);
    }

    /**
     * Drop a view's whole rule block (its widget is going away).
     */
    public function forget(string $view_name): void
    {
        unset($this->rules[$this->classFor($view_name)]);
    }

    protected function reload(GtkWidget $widget): void
    {
        if (is_null($this->provider)) {
            $display = $widget->getDisplay();
            if (is_null($display)) {
                throw GTKWindowException::noDisplayForStyles($this->window_name);
            }

            $this->provider = GtkCssProvider::new();
            // 600 = GTK_STYLE_PROVIDER_PRIORITY_APPLICATION (a #define, not an enum).
            GtkStyleContext::addProviderForDisplay($display->handle, $this->provider->handle, 600);
        }

        $this->provider->loadFromString($this->stylesheet());
    }

    protected function stylesheet(): string
    {
        $blocks = [];
        foreach ($this->rules as $class => $declarations) {
            $lines = [];
            foreach ($declarations as $property => $value) {
                $lines[] = "  {$property}: {$value};";
            }
            $blocks[] = ".{$class} {\n" . implode("\n", $lines) . "\n}";
        }

        return implode("\n", $blocks);
    }
}

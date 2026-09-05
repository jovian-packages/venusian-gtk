<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkEntry;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkPasswordEntry;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\TextInput;
use Surface\NativeWindows\Windowable;

/**
 * A Surface text input over a GtkEntry — or a GtkPasswordEntry for a
 * secret one, which has no placeholder API, so the placeholder is ignored
 * there. Both carry GtkEditable, so edits read the text straight back.
 *
 * GTK fires `changed` for programmatic writes too; the applying flag keeps
 * Surface's own setValue() from echoing back as mail.
 */
class GTKTextInput extends TextInput
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        string $value,
        ?string $placeholder,
        bool $secret,
        public readonly GtkEntry|GtkPasswordEntry $native,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $value, $placeholder, $secret);

        $native->onChanged(function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireChanged($this->native->getText() ?? '');
            }
        });
        $native->onActivate(fn (mixed ...$args) => $this->fireSubmitted());
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyValue(string $value): void
    {
        $this->applying = true;
        $this->native->setText($value);
        $this->applying = false;
    }

    protected function applyPlaceholder(string $placeholder): void
    {
        // A password entry has no placeholder — GTK's honest answer is none.
        if ($this->native instanceof GtkEntry) {
            $this->native->setPlaceholderText($placeholder);
        }
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    protected function applyTextColor(Color $color): void
    {
        $this->css('color', $color->toCss());
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->css('font-size', "{$font->size}px");
        $this->css('font-weight', (string) $font->weight->toCssWeight());
        if (! is_null($font->family)) {
            $this->css('font-family', $font->family);
        }
    }

    protected function applyBackground(Color $color): void
    {
        $this->css('background-color', $color->toCss());
    }

    /** Removal also drops this view's rule block from the stylesheet. */
    protected function destroyNative(): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->forget($this->name);
        $this->fixed()->remove($this->widget());
    }

    /** One declaration into this window's stylesheet, keyed to this view. */
    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->native, $this->name, $property, $value);
    }
}

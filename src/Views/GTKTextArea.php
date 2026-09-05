<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkScrolledWindow;
use Jovian\Bindings\Gtk\Gtk\GtkTextBuffer;
use Jovian\Bindings\Gtk\Gtk\GtkTextView;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\Contracts\NativeWindows\Views\FontSpec;
use Surface\NativeWindows\Views\TextArea;
use Surface\NativeWindows\Windowable;

/**
 * A Surface text area over a GtkTextView inside a GtkScrolledWindow. The
 * scrolled window is the framed node; the text view is the content.
 *
 * Edits read the buffer back through GtkTextBuffer::getText — iters cross
 * the ext as character offsets, 0..-1 meaning start-to-end — so what
 * fireChanged() carries is what GTK holds. The applying flag keeps
 * setValue() from echoing (GTK fires `changed` for programmatic writes).
 */
class GTKTextArea extends TextArea
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    public function __construct(
        string $name,
        Windowable $window,
        string $value,
        public readonly GtkScrolledWindow $scrolled,
        public readonly GtkTextView $text,
        public readonly GtkTextBuffer $buffer,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $value);

        $buffer->onChanged(function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireChanged($this->buffer->getText(0, -1, true) ?? '');
            }
        });
    }

    protected function widget(): GtkWidget
    {
        return $this->scrolled;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyValue(string $value): void
    {
        $this->applying = true;
        $this->buffer->setText($value, -1);
        $this->applying = false;
    }

    protected function applyEditable(bool $editable): void
    {
        $this->text->setEditable($editable);
    }

    /** A text area has no natural size worth trusting; hug keeps the frame. */
    protected function measure(): array
    {
        return [$this->width, $this->height];
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

    /** Styling lands on the text view — that is where the glyphs live. */
    protected function css(string $property, string $value): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->text, $this->name, $property, $value);
    }
}

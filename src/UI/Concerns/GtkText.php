<?php

namespace Jovian\Toolkits\GTK\UI\Concerns;

use Surface\NutsAndBolts\Color;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\TextAlignment;

/**
 * Fonts, colour and alignment, for every GTK primitive that shows text.
 */
trait GtkText
{
    protected static function xalign(TextAlignment $alignment): float
    {
        return match ($alignment) {
            TextAlignment::LEFT => 0.0,
            TextAlignment::CENTER => 0.5,
            TextAlignment::RIGHT => 1.0,
        };
    }

    /**
     * CSS px are GTK's logical units, the points FontSpec sizes are in.
     */
    protected function applyFont(FontSpec $font): void
    {
        $family = match (true) {
            ! is_null($font->family) => '"'.addcslashes($font->family, '"\\').'"',
            $font->monospace => 'monospace',
            default => null,
        };

        $this->declare('font-size', "{$font->size}px");
        $this->declare('font-weight', (string) $font->weight->toCssWeight());
        $this->declare('font-style', $font->italic ? 'italic' : null);
        $this->declare('font-family', $family);
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->declare('color', $color?->toCss());
    }
}

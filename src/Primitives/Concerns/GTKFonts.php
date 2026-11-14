<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use PangoContext;
use PangoFontDescription;
use Surface\Contracts\Toolkits\Styling\FontMetrics;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Surface\Contracts\Toolkits\Styling\FontWeight;

/**
 * FontSpec as the Pango description GTK's CSS gives a widget (family, weight, italic, px size),
 * and what Pango measures for it: shared by the text area and the driver.
 */
final class GTKFonts
{
    /**
     * A FontSpec with no family asks for Sans: pango_context_get_metrics segfaults on a description with no family.
     * shortcut: Sans is fontconfig's default sans, not gtk-font-name; read the widget's own context when that matters.
     */
    public static function description(FontSpec $font): PangoFontDescription
    {
        $family = $font->family ?? ($font->monospace ? 'Monospace' : 'Sans');
        $options = array_filter([
            $font->italic ? 'Italic' : '',
            match ($font->weight) {
                FontWeight::LIGHT => 'Light',
                FontWeight::REGULAR => '',
                FontWeight::MEDIUM => 'Medium',
                FontWeight::SEMIBOLD => 'Semi-Bold',
                FontWeight::BOLD => 'Bold',
                FontWeight::BLACK => 'Black',
            },
            rtrim(rtrim(number_format($font->size, 2, '.', ''), '0'), '.').'px',
        ]);

        // The comma ends the family list, so a family named like a style word stays a family.
        return PangoFontDescription::fromString("{$family}, ".implode(' ', $options));
    }

    /** Pango units over PANGO_SCALE: device pixels, which GTK's CSS px (and so FontSpec's size) are. */
    public static function metrics(PangoContext $context, FontSpec $font): FontMetrics
    {
        $description = self::description($font);
        $metrics = $context->getMetrics($description, null);
        $ascent = $metrics->getAscent() / PANGO_SCALE;
        $descent = $metrics->getDescent() / PANGO_SCALE;

        return new FontMetrics(
            $ascent,
            $descent,
            max($metrics->getHeight() / PANGO_SCALE, $ascent + $descent),
            $metrics->getApproximateCharWidth() / PANGO_SCALE,
            $context->loadFont($description)?->getFace()?->getFamily()->isMonospace() ?? false,
        );
    }
}

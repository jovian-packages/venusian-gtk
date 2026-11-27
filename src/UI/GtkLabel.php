<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkJustification;
use PangoEllipsizeMode;
use GtkLabel as NativeLabel;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkLabel. Font and text colour are CSS declarations of the primitive's rule. Runs are
 * Pango markup (bold, italic, a span's colour) over that font and colour; plain text turns
 * markup off again, so its '<' and '&' show as written.
 *
 * Truncating is Pango's ellipsis at the end (setEllipsize(END)), which also gives the label a
 * minimum width of the ellipsis alone, so a long text never widens what holds it. A wrapping
 * label that ellipsizes breaks into lines and cuts only the last, so the label does not wrap
 * while it truncates; ending it gives the wrap back.
 */
class GtkLabel extends TKLabel
{
    use GtkNative;
    use GtkText;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeLabel::new($this->text()));

        // A GtkLabel centres by default; topside's alignment (left until set) is applied now.
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->labelNative()->setUseMarkup(false);
        $this->labelNative()->setText($text);
    }

    /**
     * Each run's text escaped for Pango, then wrapped as it is styled. A colour is the span's
     * foreground, with fgalpha when it is not opaque.
     */
    protected function applyRuns(array $runs): void
    {
        $markup = '';
        foreach ($runs as $run) {
            $piece = htmlspecialchars($run->text, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
            if ($run->bold) {
                $piece = "<b>{$piece}</b>";
            }
            if ($run->italic) {
                $piece = "<i>{$piece}</i>";
            }
            if (! is_null($run->color)) {
                $color = $run->color;
                $hex = sprintf('#%02x%02x%02x', round($color->red * 255), round($color->green * 255), round($color->blue * 255));
                $alpha = $color->alpha < 1.0 ? sprintf(' fgalpha="%d"', max(1, (int) round($color->alpha * 65535))) : '';
                $piece = "<span foreground=\"{$hex}\"{$alpha}>{$piece}</span>";
            }
            $markup .= $piece;
        }

        $this->labelNative()->setMarkup($markup);
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->applyLines();
    }

    protected function applyTruncate(bool $truncate): void
    {
        $this->applyLines();
    }

    private function applyLines(): void
    {
        $this->labelNative()->setEllipsize($this->truncates() ? PangoEllipsizeMode::END : PangoEllipsizeMode::NONE);
        $this->labelNative()->setWrap($this->wraps() && ! $this->truncates());
    }

    /**
     * xalign places the text in the label's width; justify places its lines against each other.
     */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        [$xalign, $justify] = match ($alignment) {
            TextAlignment::LEFT => [0.0, GtkJustification::LEFT],
            TextAlignment::CENTER => [0.5, GtkJustification::CENTER],
            TextAlignment::RIGHT => [1.0, GtkJustification::RIGHT],
        };

        $this->labelNative()->setXalign($xalign);
        $this->labelNative()->setJustify($justify);
    }

    private function labelNative(): NativeLabel
    {
        /** @var NativeLabel */
        return $this->native();
    }
}

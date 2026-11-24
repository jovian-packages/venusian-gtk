<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkJustification;
use GtkLabel as NativeLabel;
use Surface\Toolkits\UI\TKLabel;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkLabel. Font and text colour are CSS declarations of the primitive's rule.
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
        $this->labelNative()->setText($text);
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->labelNative()->setWrap($wrap);
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

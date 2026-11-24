<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkLinkButton;
use GtkLabel as NativeLabel;
use Surface\Toolkits\UI\TKLink;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkLinkButton, which opens its URI itself. Without a button's padding and minimum size,
 * so it lines up with the labels beside it.
 */
class GtkLink extends TKLink
{
    use GtkNative;
    use GtkText;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(GtkLinkButton::newWithLabel($this->url(), $this->text()));
        $this->declare('padding', '0');
        $this->declare('min-height', '0');
        $this->declare('min-width', '0');
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->linkNative()->setLabel($text);
        // A button may take a new label child: the new one takes the alignment.
        $this->applyAlignment($this->alignment);
    }

    protected function applyUrl(string $url): void
    {
        $this->linkNative()->setUri($url);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $label = $this->linkNative()->getFirstChild();
        if ($label instanceof NativeLabel) {
            $label->setXalign(self::xalign($alignment));
        }
    }

    private function linkNative(): GtkLinkButton
    {
        /** @var GtkLinkButton */
        return $this->native();
    }
}

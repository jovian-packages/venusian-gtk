<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKLink;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A link over GtkLinkButton: GTK opens the URL when it is clicked (the button's own
 * activate-link). Alignment places the text inside the button, through its label's xalign; it
 * shows when the link is wider than its text.
 */
class GTKLink extends TKLink implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $text
     * @param string $url
     * @throws WindowException When the name or URL is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text, string $url)
    {
        parent::__construct($name, $window, $parent, $placement, $text, $url);
        $this->adoptNative(\GtkLinkButton::newWithLabel($url, $text));
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->widgetLink()->setLabel($text);
        // GtkButton may give its label child up for a new one: the new one takes the alignment.
        $this->applyAlignment($this->alignment);
    }

    protected function applyUrl(string $url): void
    {
        $this->widgetLink()->setUri($url);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $label = $this->widgetLink()->getFirstChild();
        if ($label instanceof \GtkLabel) {
            $label->setXalign(match ($alignment) {
                TextAlignment::LEFT => 0.0,
                TextAlignment::CENTER => 0.5,
                TextAlignment::RIGHT => 1.0,
            });
        }
    }

    protected function widgetLink(): \GtkLinkButton
    {
        /** @var \GtkLinkButton */
        return $this->native;
    }
}

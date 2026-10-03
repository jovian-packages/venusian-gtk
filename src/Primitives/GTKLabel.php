<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKLabel;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A label over GtkLabel. Alignment sets both where the text sits in the label (xalign) and how
 * wrapped lines line up (justify).
 */
class GTKLabel extends TKLabel implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $text
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $text)
    {
        parent::__construct($name, $window, $parent, $placement, $text);
        $this->adoptNative(\GtkLabel::new($text));
        $this->applyAlignment($this->alignment);
    }

    protected function applyText(string $text): void
    {
        $this->widgetLabel()->setText($text);
    }

    protected function applyWrap(bool $wrap): void
    {
        $this->widgetLabel()->setWrap($wrap);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        [$xalign, $justify] = match ($alignment) {
            TextAlignment::LEFT => [0.0, \GtkJustification::LEFT],
            TextAlignment::CENTER => [0.5, \GtkJustification::CENTER],
            TextAlignment::RIGHT => [1.0, \GtkJustification::RIGHT],
        };
        $this->widgetLabel()->setXalign($xalign);
        $this->widgetLabel()->setJustify($justify);
    }

    protected function widgetLabel(): \GtkLabel
    {
        /** @var \GtkLabel */
        return $this->native;
    }
}

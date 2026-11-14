<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\GTKWindow;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKCanvas;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A GtkPicture in a box, showing the pixels it was last given as a GdkMemoryTexture stretched
 * over its allocation (the alpha byte is not read, so opaque). It has no natural size: the
 * layout sizes it.
 *
 * It shows pixels and nothing more: it takes no keys or pointer events, and lends no GPU surface.
 */
class GTKCanvas extends TKCanvas implements GTKView
{
    use GTKPrimitive;

    protected \GtkPicture $picture;

    /** Held while it is shown: the picture reads it when it draws. */
    protected ?\GdkTexture $texture = null;

    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        parent::__construct($name, $window, $parent, $placement);
        $box = \GtkBox::new(\GtkOrientation::VERTICAL, 0);
        $this->picture = \GtkPicture::new();
        $this->picture->setCanShrink(true);
        $this->picture->setContentFit(\GtkContentFit::FILL);
        $this->picture->setHexpand(true);
        $this->picture->setVexpand(true);
        $box->append($this->picture);
        $this->adoptNative($box);
    }

    /** Device pixels per application pixel on the widget's surface, fractional scales included; the integer factor before the surface exists. */
    protected function nativeScale(): float
    {
        return $this->native->getNative()?->getSurface()?->getScale() ?? (float) $this->native->getScaleFactor();
    }

    /** @throws ToolkitRuntimeException When GDK makes no texture from the pixels. */
    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        $this->texture = \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, \GBytes::new($rgba8, strlen($rgba8)), $width * 4)
            ?? throw new ToolkitRuntimeException("Canvas '{$this->path()}' cannot show {$width}x{$height} pixels: GDK made no texture from them.");
        $this->picture->setPaintable($this->texture);
    }
}

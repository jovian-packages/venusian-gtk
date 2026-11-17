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
 * A GtkPicture in an overlay, showing the pixels it was last given as a GdkMemoryTexture stretched
 * over its allocation (the alpha byte is not read, so opaque). It has no natural size: the
 * layout sizes it.
 *
 * It takes no keys or pointer events. It lends its overlay to an engine that draws there itself
 * (a GPU engine adds its own picture over the canvas's); the canvas's picture stays under it.
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
        $overlay = \GtkOverlay::new();
        $this->picture = \GtkPicture::new();
        $this->picture->setCanShrink(true);
        $this->picture->setContentFit(\GtkContentFit::FILL);
        $this->picture->setHexpand(true);
        $this->picture->setVexpand(true);
        $overlay->setChild($this->picture);
        $this->adoptNative($overlay);
    }

    /** The overlay: an engine adds its own child over the canvas's picture and takes it away again. */
    public function nativeSurface(): \GtkOverlay
    {
        $this->live();

        return $this->native;
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

<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Framebuffers\Region;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKCanvas;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A canvas over GtkPicture: each present() makes a GdkMemoryTexture of the framebuffer's RGBA8
 * bytes in the R8G8B8X8 layout (the fourth byte ignored, so opaque) and shows it, stretched over
 * the widget (content fit FILL). An ext-fb framebuffer never becomes a string: the texture is
 * copied by address, and where GTK is 4.16 or newer the texture after the first is built with
 * an update region, so only the damage is read. The picture may shrink below what it shows, so
 * the layout sizes it, never the framebuffer. R8G8B8X8 came with GTK 4.14: an older GTK cannot
 * host a canvas.
 */
class GTKCanvas extends TKCanvas implements GTKView
{
    use GTKPrimitive;

    /** The texture shown last, for the next one to update. */
    private ?\GdkTexture $texture = null;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @throws WindowException When the name is not valid, or GTK is older than 4.14.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement)
    {
        if (\gtk_get_major_version() === 4 && \gtk_get_minor_version() < 14) {
            throw new WindowException('TKCanvas needs GTK 4.14 or newer, this is 4.'.\gtk_get_minor_version().'.');
        }

        parent::__construct($name, $window, $parent, $placement);
        $picture = \GtkPicture::new();
        $picture->setCanShrink(true);
        $picture->setContentFit(\GtkContentFit::FILL);
        $this->adoptNative($picture);
    }

    /** Device pixels per application pixel on the widget's surface. */
    protected function nativeScale(): float
    {
        return (float) $this->native->getScaleFactor();
    }

    protected function applyPixels(string $rgba8, int $width, int $height): void
    {
        /** @var \GtkPicture $picture */
        $picture = $this->native;
        $this->texture = \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, $rgba8, $width * 4);
        $picture->setPaintable($this->texture);
    }

    protected function applyAddress(int $address, int $width, int $height, int $stride, array $damage): void
    {
        $previous = $this->texture;
        $updates = ! is_null($previous) && $damage !== [] && \gtk_get_minor_version() >= 16
            && $previous->getWidth() === $width && $previous->getHeight() === $height;

        $this->texture = $updates
            ? \GdkMemoryTextureBuilder::new()
                ->setBytes($address, $stride * $height)
                ->setWidth($width)->setHeight($height)->setFormat(\GdkMemoryFormat::R8G8B8X8)->setStride($stride)
                ->setUpdateTexture($previous)
                ->setUpdateRegion(array_map(fn (Region $region): array => [$region->x, $region->y, $region->width, $region->height], $damage))
                ->build()
            : \GdkMemoryTexture::new($width, $height, \GdkMemoryFormat::R8G8B8X8, $address, $stride);

        /** @var \GtkPicture $picture */
        $picture = $this->native;
        $picture->setPaintable($this->texture);
    }
}

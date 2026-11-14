<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Primitives\ImageScaling;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKImage;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * An image over GtkPicture. A file is checked before anything changes: it must exist, be
 * readable, and give GTK something to show, or ToolkitRuntimeException and the image keeps what it
 * showed. What GTK makes of a file is GTK's: 4.18 loads nothing from a file that is not an
 * image, while 4.24 shows any file it does not recognise as an SVG document; a corrupt image
 * of a format it knows loads nothing on both. Scaling:
 * FIT = CONTAIN, FILL = COVER, CENTER = SCALE_DOWN (natural size, shrunk only to fit),
 * STRETCH = FILL.
 */
class GTKImage extends TKImage implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string|null $file
     * @throws ToolkitRuntimeException When the name is not valid, or the file is missing or not an image GTK loads.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        parent::__construct($name, $window, $parent, $placement, $file);
        if (! is_null($file)) {
            self::guardFile($file);
        }
        $this->adoptNative(is_null($file) ? \GtkPicture::new() : \GtkPicture::newForFilename($file));
        $this->applyScaling($this->scaling);
    }

    /**
     * @param string|null $file
     * @return $this
     * @throws ToolkitRuntimeException When the file is missing or not an image GTK loads; nothing changes.
     */
    public function setFile(?string $file): static
    {
        $this->live();
        if (! is_null($file)) {
            self::guardFile($file);
        }

        return parent::setFile($file);
    }

    protected function applyFile(?string $file): void
    {
        $this->widgetPicture()->setFilename($file);
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->widgetPicture()->setContentFit(match ($scaling) {
            ImageScaling::FIT => \GtkContentFit::CONTAIN,
            ImageScaling::FILL => \GtkContentFit::COVER,
            ImageScaling::CENTER => \GtkContentFit::SCALE_DOWN,
            ImageScaling::STRETCH => \GtkContentFit::FILL,
        });
    }

    protected function widgetPicture(): \GtkPicture
    {
        /** @var \GtkPicture */
        return $this->native;
    }

    /**
     * @param string $file
     * @return void
     * @throws ToolkitRuntimeException
     */
    protected static function guardFile(string $file): void
    {
        if (! is_file($file) || ! is_readable($file)) {
            throw new ToolkitRuntimeException("Image file '{$file}' does not exist or is not readable.");
        }
        if (is_null(\GtkPicture::newForFilename($file)->getPaintable())) {
            throw new ToolkitRuntimeException("Image file '{$file}' gives GTK nothing to show.");
        }
    }
}

<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GdkTexture;
use GtkPicture;
use GtkContentFit;
use GtkOrientation;
use Surface\Toolkits\UI\TKImage;
use Surface\Toolkits\ToolkitException;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\ImageScaling;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkPicture inside a box of its own. A column measures a picture's height for the whole
 * column's width, whatever its halign, so one at START grew to the column (observed): the box
 * hands it that width only while it is to fill.
 */
class GtkImage extends TKImage
{
    use GtkNative {
        applyFill as private expandBox;
        applyAlign as private alignBox;
    }

    private ?GtkPicture $picture = null;

    protected function bootstrapNative(): void
    {
        $file = $this->file();
        if (! is_null($file)) {
            self::guardPicture($file);
        }

        $this->picture = GtkPicture::new();
        $this->picture->setFilename($file);

        $box = GtkBox::new(GtkOrientation::HORIZONTAL, 0);
        $box->append($this->picture);
        // Said, so that the picture's expand does not become the box's.
        $box->setHexpand(false);
        $box->setVexpand(false);
        $this->adoptNative($box);

        $this->picture->setHexpand(true);
        $this->applyScaling($this->scaling());
    }

    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->expandBox($horizontal, $vertical);
        $this->picture->setHexpand($horizontal || $this->aligned()[0] === Align::FILL);
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->alignBox($horizontal, $vertical);
        $this->picture->setHexpand($this->fills()[0] || $horizontal === Align::FILL);
    }

    protected function applyFile(?string $file): void
    {
        if (! is_null($file)) {
            self::guardPicture($file);
        }
        // By name: ext-gtk hands an SVG's paintable back as a plain GObject, which setPaintable() refuses.
        $this->picture->setFilename($file);
    }

    protected function applyScaling(ImageScaling $scaling): void
    {
        $this->picture->setContentFit(match ($scaling) {
            ImageScaling::FIT => GtkContentFit::CONTAIN,
            ImageScaling::FILL => GtkContentFit::COVER,
            ImageScaling::CENTER => GtkContentFit::SCALE_DOWN,
            ImageScaling::STRETCH => GtkContentFit::FILL,
        });
    }

    /**
     * GTK answers an empty GtkSvg for a file it cannot read as a picture (observed), so what
     * is not a texture counts only when the file does hold SVG.
     *
     * @throws ToolkitException When GTK reads no picture in it.
     */
    private static function guardPicture(string $file): void
    {
        $paintable = GtkPicture::newForFilename($file)->getPaintable();
        $read = $paintable instanceof GdkTexture
            || (! is_null($paintable) && preg_match('/<svg[\s>]/i', (string) file_get_contents($file, length: 4096)) === 1);

        if (! $read) {
            throw new ToolkitException("'{$file}' is not an image GTK reads.");
        }
    }
}

<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GdkTexture;
use GtkConstraint;
use GtkLayoutManager;
use GtkConstraintGuide;
use GtkConstraintLayout;
use GtkConstraintStrength;
use GtkConstraintRelation;
use GtkConstraintAttribute;
use GtkPicture;
use GtkContentFit;
use GtkOverflow;
use GtkOrientation;
use Surface\Toolkits\UI\TKImage;
use Surface\Toolkits\ToolkitException;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Contracts\Toolkits\UI\Corners;
use Surface\Contracts\Toolkits\UI\ImageScaling;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkPicture inside a box of its own. A column measures a picture's height for the whole
 * column's width, whatever its halign, so one at START grew to the column (observed): the box
 * hands it that width only while it is to fill.
 *
 * Its corner radius rounds the picture too: the box cuts the picture at its rounded edge
 * (overflow hidden) while it has a radius or clips.
 *
 * A maximum size is GTK's constraint layout on the box: the picture fills the box, and its
 * size is a guide's whose natural size is the picture's own and whose maximum is the cap.
 * GTK answers a constrained height-for-width with the guide's maximum, so on an axis the
 * image does not fill the maximum is the smaller of the cap and the picture's own size, and
 * on an axis with no cap it is the picture's own size: a cap shrinks a picture, never
 * enlarges it. With no maximum the box has its own layout back.
 */
class GtkImage extends TKImage
{
    use GtkNative {
        applyFill as private expandBox;
        applyAlign as private alignBox;
        applyCorners as private roundBox;
    }

    private ?GtkPicture $picture = null;

    /** The box's own layout, kept while a maximum size replaces it. */
    private ?GtkLayoutManager $own_layout = null;

    /** The guide whose size is the picture's while a maximum size is set. */
    private ?GtkConstraintGuide $guide = null;

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
        $this->sizeGuide();
    }

    protected function applyMaxSize(int $width, int $height): void
    {
        if ($width === 0 && $height === 0) {
            if (! is_null($this->guide)) {
                $this->native->setLayoutManager($this->own_layout);
                [$this->own_layout, $this->guide] = [null, null];
            }

            return;
        }

        if (is_null($this->guide)) {
            $this->own_layout = $this->native->getLayoutManager();
            $layout = GtkConstraintLayout::new();
            $this->native->setLayoutManager($layout);

            $required = GtkConstraintStrength::REQUIRED->value;
            foreach ([GtkConstraintAttribute::LEFT, GtkConstraintAttribute::TOP, GtkConstraintAttribute::RIGHT, GtkConstraintAttribute::BOTTOM] as $edge) {
                $layout->addConstraint(GtkConstraint::new($this->picture, $edge, GtkConstraintRelation::EQ, null, $edge, 1.0, 0.0, $required));
            }

            $this->guide = GtkConstraintGuide::new();
            $layout->addGuide($this->guide);
            foreach ([GtkConstraintAttribute::WIDTH, GtkConstraintAttribute::HEIGHT] as $side) {
                $layout->addConstraint(GtkConstraint::new($this->picture, $side, GtkConstraintRelation::EQ, $this->guide, $side, 1.0, 0.0, $required));
            }
        }

        $this->sizeGuide();
    }

    /**
     * The guide's natural size is the picture's own; its maximum on each axis is the cap, or
     * no more than the picture's own size where the image does not fill that axis.
     */
    private function sizeGuide(): void
    {
        if (is_null($this->guide)) {
            return;
        }

        $natural_width = $this->picture->measure(GtkOrientation::HORIZONTAL, -1)['natural'];
        $natural_height = $this->picture->measure(GtkOrientation::VERTICAL, -1)['natural'];
        [$cap_width, $cap_height] = $this->maximumSize();
        [$fills_width, $fills_height] = $this->fills();

        $this->guide->setNatSize($natural_width, $natural_height);
        $this->guide->setMaxSize(
            self::ceiling($cap_width, $natural_width, $fills_width),
            self::ceiling($cap_height, $natural_height, $fills_height),
        );
    }

    /**
     * One axis of the guide's maximum: no cap (0) is the picture's own size; a cap is the cap
     * while filling that axis, else no more than the picture's own size.
     */
    private static function ceiling(int $cap, int $natural, bool $fills): int
    {
        if ($cap === 0) {
            return $natural;
        }

        return $fills ? $cap : min($cap, $natural);
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        $this->alignBox($horizontal, $vertical);
        $this->picture->setHexpand($this->fills()[0] || $horizontal === Align::FILL);
    }

    protected function applyCorners(Corners $corners): void
    {
        $this->roundBox($corners);
        $this->cutPicture();
    }

    protected function applyClip(bool $clip): void
    {
        $this->cutPicture();
    }

    private function cutPicture(): void
    {
        $this->native->setOverflow($this->clips() || ! $this->corners()->isZero() ? GtkOverflow::HIDDEN : GtkOverflow::VISIBLE);
    }

    protected function applyFile(?string $file): void
    {
        if (! is_null($file)) {
            self::guardPicture($file);
        }
        // By name: ext-gtk hands an SVG's paintable back as a plain GObject, which setPaintable() refuses.
        $this->picture->setFilename($file);
        $this->sizeGuide();
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

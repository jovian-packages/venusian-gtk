<?php

namespace Jovian\Toolkits\GTK\Primitives\Concerns;

use GBytes;
use GdkMemoryFormat;
use GdkMemoryTexture;
use GtkAlign;
use GtkBox;
use GtkContentFit;
use GtkLabel;
use GtkOrientation;
use GtkPicture;
use GtkPolicyType;
use GtkScrolledWindow;
use Surface\Contracts\Framebuffers\Framebuffer;
use Surface\Contracts\Windows\Primitives\ImagePosition;
use Surface\Contracts\Windows\WindowException;

/**
 * A Framebuffer as a button's image: its RGBA8 pixels as a GdkMemoryTexture in a GtkPicture.
 * A picture's natural size is the texture's pixel size, and a size request only raises a minimum,
 * so the picture sits in a frame that caps it: a GtkScrolledWindow with external scrolling (no
 * scroll bars, wheel events pass through) whose natural size is its size request, width / scale
 * × height / scale points (probe 2026-10-23: a bare picture with a 16×16 request takes 32×32).
 */
trait GTKButtonImage
{
    /**
     * The button's child for an image: the image above or before a label, or alone.
     *
     * @throws WindowException When GDK makes no texture of the pixels.
     */
    protected static function imageChild(Framebuffer $image, ImagePosition $position, float $scale, string $label): GtkBox
    {
        $box = GtkBox::new($position === ImagePosition::ABOVE ? GtkOrientation::VERTICAL : GtkOrientation::HORIZONTAL, 4);
        $box->append(self::imageFrame($image, $scale));
        if ($position !== ImagePosition::ONLY) {
            $box->append(GtkLabel::new($label));
        }

        return $box;
    }

    /** @throws WindowException When GDK makes no texture of the pixels. */
    protected static function imageFrame(Framebuffer $image, float $scale): GtkScrolledWindow
    {
        $width = $image->viewportWidth();
        $height = $image->viewportHeight();
        $rgba8 = $image->toRgba8();
        $texture = GdkMemoryTexture::new($width, $height, GdkMemoryFormat::R8G8B8A8, GBytes::new($rgba8, strlen($rgba8)), $width * 4)
            ?? throw new WindowException("A {$width}x{$height} button image could not be made.");
        $picture = GtkPicture::new();
        $picture->setPaintable($texture);
        $picture->setCanShrink(true);
        $picture->setContentFit(GtkContentFit::CONTAIN);

        $frame = GtkScrolledWindow::new();
        $frame->setPolicy(GtkPolicyType::EXTERNAL, GtkPolicyType::EXTERNAL);
        $frame->setSizeRequest((int) round($width / $scale), (int) round($height / $scale));
        $frame->setHalign(GtkAlign::CENTER);
        $frame->setValign(GtkAlign::CENTER);
        $frame->setChild($picture);

        return $frame;
    }
}

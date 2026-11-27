<?php

namespace Jovian\Toolkits\GTK\Video\MediaFile;

use GtkBox;
use GtkWidget;
use GtkOrientation;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;

/**
 * The 'gtk' driver's player where GTK has no media backend: an empty GtkBox that lays out,
 * fills and takes a size like any primitive, and draws nothing. With supported() false,
 * topside makes none of the calls below.
 */
class MediaFileVideoPlayer implements GtkVideoPlayer
{
    private GtkBox $box;

    public function __construct()
    {
        $this->box = GtkBox::new(GtkOrientation::VERTICAL, 0);
    }

    public function widget(): GtkWidget
    {
        return $this->box;
    }

    /** Never reached: with supported() false, topside answers a source with VideoFailed itself. */
    public function load(?string $url, bool $muted, bool $loop, bool $controls): void {}

    /** Never reached: see load(). */
    public function play(): void {}

    /** Never reached: see load(). */
    public function pause(): void {}

    /** Never reached: see load(). */
    public function seek(float $seconds): void {}

    /** Never reached: see load(). */
    public function setMuted(bool $muted): void {}

    /** Never reached: see load(). */
    public function setLoop(bool $loop): void {}

    /** Never reached: see load(). */
    public function setControls(bool $controls): void {}

    /** Never reached: topside reads no position without a player. */
    public function position(): float
    {
        return 0.0;
    }

    /** Never reached: topside reads no duration without a player. */
    public function duration(): ?float
    {
        return null;
    }

    public function release(): void {}
}

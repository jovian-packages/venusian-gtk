<?php

namespace Jovian\Toolkits\GTK\Video\MediaFile;

use Jovian\Toolkits\GTK\Video\GtkVideoDriver;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;
use Jovian\Toolkits\GTK\Video\VideoDriverUnsupported;

/**
 * The 'gtk' video driver: GTK's own player, GtkVideo over a GtkMediaFile, which GTK plays
 * through a media backend module it loads at run time (libgtk-4-media-gstreamer on Debian).
 *
 * macOS: Homebrew's gtk4 ships no media module, so GTK has nothing to play with, and the driver
 * throws when it is made. Linux: the GtkVideo over GtkMediaFile player is a stage of its own,
 * not built yet; until it is, the driver is made there and supported() is false, so topside
 * answers every source with VideoFailed (TKVideo::NO_PLAYER) itself.
 */
class MediaFileVideoDriver implements GtkVideoDriver
{
    /**
     * @throws VideoDriverUnsupported On macOS.
     */
    public function __construct()
    {
        if (device_os_family() === 'mac') {
            throw new VideoDriverUnsupported("The 'gtk' video driver plays through GTK's media backend, which GTK has no module for on macOS. Name the 'gstreamer' driver in gtk.video.driver.");
        }
    }

    public function player(GtkVideoListener $listener): GtkVideoPlayer
    {
        return new MediaFileVideoPlayer();
    }

    public function supported(): bool
    {
        return false;
    }
}

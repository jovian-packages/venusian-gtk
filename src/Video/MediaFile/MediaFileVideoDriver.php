<?php

namespace Jovian\Toolkits\GTK\Video\MediaFile;

use Jovian\Toolkits\GTK\Video\GtkVideoDriver;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;

/**
 * The 'gtk' video driver: GTK's own player, GtkVideo over a GtkMediaFile, which GTK plays
 * through a media backend module it loads at run time (libgtk-4-media-gstreamer on Debian).
 *
 * macOS: Homebrew's gtk4 ships no media module, so GTK has nothing to play with. supported()
 * is false, and topside answers every source with VideoFailed (TKVideo::NO_PLAYER) itself.
 * Linux: the GtkVideo over GtkMediaFile player is a stage of its own, not built yet; until it
 * is, the driver answers there as on macOS.
 */
class MediaFileVideoDriver implements GtkVideoDriver
{
    public function player(GtkVideoListener $listener): GtkVideoPlayer
    {
        return new MediaFileVideoPlayer();
    }

    public function supported(): bool
    {
        return false;
    }
}

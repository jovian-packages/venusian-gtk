<?php

namespace Jovian\Toolkits\GTK\Video\Gstreamer;

use GObject;
use RuntimeException;
use Jovian\Media\Gstreamer\GstreamerPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoDriver;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;

/**
 * The 'gstreamer' video driver: each player is jovian/venusian-gstreamer's GstreamerPlayer, a
 * playbin drawing through GStreamer's gtk4paintablesink into a GtkPicture. The package is a
 * suggestion of this one, checked when the driver is made with everything else it needs.
 */
class GstreamerVideoDriver implements GtkVideoDriver
{
    /**
     * @param int $positionInterval milliseconds between the bar's position updates while it plays
     *
     * @throws RuntimeException When ext-gstreamer, jovian/venusian-gstreamer, GStreamer itself,
     *     the gtk4paintablesink element or ext-gtk's GObject::fromPointer() is missing.
     */
    public function __construct(private readonly int $positionInterval)
    {
        if (! extension_loaded('gstreamer')) {
            throw new RuntimeException("The 'gstreamer' video driver needs ext-gstreamer. Install it with 'pie install php-io-extensions/gstreamer'");
        }

        if (! class_exists(GstreamerPlayer::class)) {
            throw new RuntimeException("The 'gstreamer' video driver needs jovian/venusian-gstreamer. Install it with 'composer require jovian/venusian-gstreamer'");
        }

        if (! method_exists(GObject::class, 'fromPointer')) {
            throw new RuntimeException("The 'gstreamer' video driver needs an ext-gtk with GObject::fromPointer(). Install it with 'pie install php-io-extensions/gtk'");
        }

        if (! gst_init_check($argv)) {
            throw new RuntimeException("The 'gstreamer' video driver cannot initialize GStreamer.");
        }

        $sink = gst_element_factory_make('gtk4paintablesink', null);
        if (is_null($sink)) {
            throw new RuntimeException("The 'gstreamer' video driver needs GStreamer's gtk4paintablesink. Install it with 'brew install gstreamer' on macOS or 'apt install gstreamer1.0-gtk4' on Debian");
        }
        gst_object_unref($sink);
    }

    public function player(GtkVideoListener $listener): GtkVideoPlayer
    {
        return new GstreamerVideoPlayer($listener, $this->positionInterval);
    }

    public function supported(): bool
    {
        return true;
    }
}

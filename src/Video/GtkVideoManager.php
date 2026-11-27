<?php

namespace Jovian\Toolkits\GTK\Video;

use Voyager\NutsAndBolts\Manager;
use Jovian\Toolkits\GTK\Video\MediaFile\MediaFileVideoDriver;
use Jovian\Toolkits\GTK\Video\Gstreamer\GstreamerVideoDriver;

/**
 * The players this package's videos are made with: one driver per name, made the first time
 * it is asked for. The default is gtk.video.driver. 'gtk' (GTK's own media) and 'gstreamer'
 * (jovian/venusian-gstreamer's playbin) are built in; another package or the app adds a
 * driver with extend().
 */
class GtkVideoManager extends Manager
{
    public function getDefaultDriver(): ?string
    {
        return $this->config->get('gtk.video.driver');
    }

    public function driver(?string $driver = null): GtkVideoDriver
    {
        return parent::driver($driver);
    }

    public function createGtkDriver(): MediaFileVideoDriver
    {
        return new MediaFileVideoDriver();
    }

    public function createGstreamerDriver(): GstreamerVideoDriver
    {
        return new GstreamerVideoDriver((int) $this->config->get('gtk.video.position_interval'));
    }
}

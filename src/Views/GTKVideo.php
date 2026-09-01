<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkMediaFile;
use Jovian\Bindings\Gtk\Gtk\GtkVideo as GtkVideoWidget;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Venusian\GTK\Windows\GTKWindowDelegate;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Video;
use Surface\NativeWindows\Windowable;

/**
 * A Surface video over a GtkVideo widget playing a GtkMediaFile. The
 * media file DTO is held for the life of the view: playback commands go
 * through its inherited GtkMediaStream surface, and dropping the box
 * would strand them.
 *
 * Playback needs a GTK media backend at runtime
 * (libgtk-4-media-gstreamer); without one the widget shows a
 * broken-media icon.
 */
class GTKVideo extends Video
{
    use TranslatesGtkFrames;

    protected ?GtkMediaFile $media = null;

    public function __construct(
        string $name,
        Windowable $window,
        ?string $path,
        public readonly GtkVideoWidget $widget,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $path);
    }

    protected function widget(): GtkWidget
    {
        return $this->widget;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyPath(string $path): void
    {
        $media = GtkMediaFile::newForFilename($path);
        $this->media = $media;
        $this->widget->setMediaStream($media);

        // A fresh stream forgets nothing Surface believes: re-assert mute.
        $media->setMuted($this->muted);
    }

    protected function applyPlaying(bool $playing): void
    {
        if (is_null($this->media)) {
            return;
        }

        if ($playing) {
            $this->media->play();
        } else {
            $this->media->pause();
        }
    }

    protected function applyMuted(bool $muted): void
    {
        $this->media?->setMuted($muted);
    }

    protected function applyBackground(Color $color): void
    {
        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->declare($this->widget, $this->name, 'background-color', $color->toCss());
    }

    /** Removal also drops this view's rule block from the stylesheet. */
    protected function destroyNative(): void
    {
        $this->media?->pause();

        /** @var GTKWindowDelegate $delegate */
        $delegate = $this->window;
        $delegate->styles->forget($this->name);
        $this->fixed()->remove($this->widget());
    }
}

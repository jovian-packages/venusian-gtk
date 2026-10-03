<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\VideoEnded;
use Surface\Contracts\Windows\Mail\View\VideoFailed;
use Surface\Contracts\Windows\Mail\View\VideoPaused;
use Surface\Contracts\Windows\Mail\View\VideoPlaying;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKVideo;

/**
 * A video over GtkVideo, one GtkMediaFile per file. GTK plays media through a backend module
 * (libgtk-4-media-gstreamer on Debian); without one there is nothing to play, so creation
 * throws. The stream's own reports are mail, whoever started them: notify::playing posts
 * VideoPlaying or VideoPaused, notify::ended (true) VideoEnded, notify::error VideoFailed.
 * GTK loops a looping stream itself, so it never ends. Removal pauses the stream.
 */
class GTKVideo extends TKVideo implements GTKView
{
    use GTKPrimitive;

    protected ?\GtkMediaFile $stream = null;

    /**
     * @var list<int> handler ids on the current stream
     */
    protected array $stream_handlers = [];

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string|null $file
     * @throws WindowException When the name is not valid, or GTK has no media backend.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, ?string $file)
    {
        if (! gtk_media_backend_available()) {
            throw new WindowException('TKVideo needs the GTK media backend (libgtk-4-media-gstreamer).');
        }
        parent::__construct($name, $window, $parent, $placement, $file);
        $this->adoptNative(\GtkVideo::new());
        $this->applyFile($file);
    }

    /**
     * The media stream of the current file, null with none.
     * @return \GtkMediaFile|null
     */
    public function stream(): ?\GtkMediaFile
    {
        return $this->stream;
    }

    protected function applyFile(?string $file): void
    {
        $this->dropStream();
        if (! is_null($file)) {
            $this->stream = \GtkMediaFile::newForFilename($file);
            $this->stream->setMuted($this->muted);
            $this->stream->setLoop($this->looping);
            $this->watchStream($this->stream);
        }
        $this->nativeStateChanged(false);
        $this->widgetVideo()->setMediaStream($this->stream);
    }

    protected function applyPlay(): void
    {
        $this->stream?->play();
    }

    protected function applyPause(): void
    {
        $this->stream?->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->stream?->seek((int) round($seconds * 1_000_000));
    }

    protected function applyMuted(bool $muted): void
    {
        $this->stream?->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->stream?->setLoop($loop);
    }

    protected function nativePosition(): float
    {
        return is_null($this->stream) ? 0.0 : $this->stream->getTimestamp() / 1_000_000;
    }

    protected function nativeDuration(): ?float
    {
        $duration = $this->stream?->getDuration() ?? 0;

        return $duration > 0 ? $duration / 1_000_000 : null;
    }

    protected function releaseNative(): void
    {
        $this->dropStream();
        $this->widgetVideo()->setMediaStream(null);
    }

    protected function watchStream(\GtkMediaFile $stream): void
    {
        $this->stream_handlers = [
            g_signal_connect($stream, 'notify::playing', function () use ($stream): void {
                $this->nativeStateChanged($stream->getPlaying());
                $this->report($stream->getPlaying()
                    ? new VideoPlaying($this->window->name(), $this->path(), $this->uuid)
                    : new VideoPaused($this->window->name(), $this->path(), $this->uuid));
            }),
            g_signal_connect($stream, 'notify::ended', function () use ($stream): void {
                if ($stream->getEnded()) {
                    $this->report(new VideoEnded($this->window->name(), $this->path(), $this->uuid));
                }
            }),
            g_signal_connect($stream, 'notify::error', function () use ($stream): void {
                $error = $stream->getError();
                if (! is_null($error)) {
                    $this->report(new VideoFailed($this->window->name(), $this->path(), $this->uuid, $error->getMessage()));
                }
            }),
        ];
    }

    /**
     * Disconnect and pause the current stream, if any.
     * @return void
     */
    protected function dropStream(): void
    {
        if (is_null($this->stream)) {
            return;
        }
        foreach ($this->stream_handlers as $id) {
            if (g_signal_handler_is_connected($this->stream, $id)) {
                g_signal_handler_disconnect($this->stream, $id);
            }
        }
        $this->stream_handlers = [];
        $this->stream->pause();
        $this->stream = null;
    }

    /**
     * The stream's reports are not user input: posted whatever the widget's sensitivity.
     *
     * @param object $mail
     * @return void
     */
    protected function report(object $mail): void
    {
        $this->session()->post($mail);
    }

    protected function widgetVideo(): \GtkVideo
    {
        /** @var \GtkVideo */
        return $this->native;
    }
}

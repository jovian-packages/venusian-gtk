<?php

namespace Jovian\Toolkits\GTK\Video\Gstreamer;

use GtkBox;
use GObject;
use GtkLabel;
use GtkScale;
use GtkImage;
use GtkButton;
use GtkWidget;
use GtkPicture;
use GtkOverlay;
use GIOCondition;
use GtkContentFit;
use GtkOrientation;
use WeakReference;
use Jovian\Media\Gstreamer\GstreamerPlayer;
use Jovian\Media\Gstreamer\GstreamerPlayerListener;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;

/**
 * One GstreamerPlayer for one video view: a GtkPicture showing the gtk4paintablesink's
 * paintable, above a control bar shown while the view has controls: play or pause, a position
 * scale, the time and the sound, each with GTK's own icon. GTK's GtkMediaControls drives a
 * GtkMediaStream, and a playbin is not one, so the bar is this player's, as the 'qt' driver's
 * is on Qt.
 *
 * The view takes the size the layout gives it, as every toolkit's video does: the picture is an
 * overlay child, which GtkOverlay does not measure, over an empty box that is the overlay's own
 * size. A GtkPicture measures as its paintable, and in a box it would grow the view to the
 * media's size at its width. The picture fits the media inside, whole.
 *
 * The frames go from GStreamer to GTK in native code: once the media prerolls, the sink's
 * paintable becomes the picture's (GObject::fromPointer() takes the sink from ext-gstreamer).
 * The bus fd is a GLib source on the main context the pump blocks in, so a waiting message
 * wakes it; the bar's position follows a timeout while it plays. What the player reports goes
 * to the view's listener, held weakly; the bar's sound button reports the muting.
 *
 * A playbin plays on whether or not anything shows it, so the player pauses when its widget is
 * unmapped: GTK unmaps every widget inside a container it hides (a drawer sliding away, a
 * window closing), and the media stops with what showed it. It does not start again when it is
 * shown again.
 */
final class GstreamerVideoPlayer implements GtkVideoPlayer, GstreamerPlayerListener
{
    private GstreamerPlayer $player;

    private GtkBox $host;

    private GtkPicture $picture;

    private GtkBox $bar;

    private GtkImage $toggle;

    private GtkScale $scrub;

    private GtkLabel $time;

    private GtkImage $sound;

    /** @var WeakReference<GtkVideoListener> */
    private WeakReference $listener;

    private int $busSource;

    private ?int $positionSource = null;

    private bool $controls = false;

    private bool $released = false;

    public function __construct(GtkVideoListener $listener, private readonly int $positionInterval)
    {
        $this->listener = WeakReference::create($listener);
        $this->player = new GstreamerPlayer($this, 'gtk4paintablesink');

        $this->picture = GtkPicture::new();
        $this->picture->setContentFit(GtkContentFit::CONTAIN);
        $area = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $area->setHexpand(true);
        $area->setVexpand(true);
        $screen = GtkOverlay::new();
        $screen->setChild($area);
        $screen->addOverlay($this->picture);
        $screen->setClipOverlay($this->picture, true);
        $this->host = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->host->append($screen);
        $this->buildBar();
        $this->host->append($this->bar);
        $this->bar->setVisible(false);

        $self = WeakReference::create($this);
        g_signal_connect($this->host, 'unmap', static function () use ($self): void {
            $player = $self->get();
            if (! is_null($player) && ! $player->released) {
                $player->pause();
            }
        });
        $this->busSource = g_unix_fd_add($this->player->busFd(), GIOCondition::IN, static function () use ($self): bool {
            $self->get()?->player->dispatch();

            return true;
        });
    }

    public function widget(): GtkWidget
    {
        return $this->host;
    }

    public function load(?string $url, bool $muted, bool $loop, bool $controls): void
    {
        $this->picture->setPaintable(null);
        $this->setMuted($muted);
        $this->setLoop($loop);
        $this->setControls($controls);
        $this->player->load($url);
        $this->scrub->setRange(0.0, 1.0);
        $this->showPosition();
    }

    public function play(): void
    {
        $this->player->play();
    }

    public function pause(): void
    {
        $this->player->pause();
    }

    public function seek(float $seconds): void
    {
        $this->player->seek($seconds);
    }

    public function setMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
        $this->sound->setFromIconName($muted ? 'audio-volume-muted-symbolic' : 'audio-volume-high-symbolic');
        $this->sound->getParent()?->setTooltipText($muted ? 'Unmute' : 'Mute');
    }

    public function setLoop(bool $loop): void
    {
        $this->player->setLoop($loop);
    }

    public function setControls(bool $controls): void
    {
        $this->controls = $controls;
        $this->bar->setVisible($controls);
        $this->followPosition();
    }

    public function position(): float
    {
        return $this->player->position();
    }

    public function duration(): ?float
    {
        return $this->player->duration();
    }

    /** The picture the media shows in. */
    public function picture(): GtkPicture
    {
        return $this->picture;
    }

    /** The control bar, shown while the view has controls. */
    public function controls(): GtkBox
    {
        return $this->bar;
    }

    /** The GstreamerPlayer this view plays through. */
    public function gstreamer(): GstreamerPlayer
    {
        return $this->player;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        g_source_remove($this->busSource);
        if (! is_null($this->positionSource)) {
            g_source_remove($this->positionSource);
            $this->positionSource = null;
        }
        $this->picture->setPaintable(null);
        $this->player->release();
    }

    public function gstreamerLoaded(): void
    {
        $sink = $this->player->videoSink();
        if (! is_null($sink)) {
            g_object_set_property($this->picture, 'paintable', g_object_get_property(GObject::fromPointer($sink->pointer()), 'paintable'));
        }
        $this->scrub->setRange(0.0, max(1.0, $this->player->duration() ?? 0.0));
        $this->showPosition();
    }

    public function gstreamerPlaying(): void
    {
        $this->showPlaying(true);
        $this->listener->get()?->videoPlaying();
    }

    public function gstreamerPaused(): void
    {
        $this->showPlaying(false);
        $this->listener->get()?->videoPaused();
    }

    public function gstreamerEnded(): void
    {
        $this->showPlaying(false);
        $this->listener->get()?->videoEnded();
    }

    public function gstreamerFailed(?string $reason): void
    {
        $this->showPlaying(false);
        $this->listener->get()?->videoFailed($reason);
    }

    /** The control bar: play or pause, the position, the time, the sound. */
    private function buildBar(): void
    {
        $this->bar = GtkBox::new(GtkOrientation::HORIZONTAL, 8);
        [$play, $this->toggle] = self::barButton('media-playback-start-symbolic', 'Play');
        $this->scrub = GtkScale::newWithRange(GtkOrientation::HORIZONTAL, 0.0, 1.0, 0.1);
        $this->scrub->setDrawValue(false);
        $this->scrub->setHexpand(true);
        $this->time = GtkLabel::new('0:00 / 0:00');
        [$mute, $this->sound] = self::barButton('audio-volume-high-symbolic', 'Mute');
        $this->bar->append($play);
        $this->bar->append($this->scrub);
        $this->bar->append($this->time);
        $this->bar->append($mute);

        $self = WeakReference::create($this);
        $live = static fn (): ?self => ($player = $self->get()) === null || $player->released ? null : $player;
        g_signal_connect($play, 'clicked', static function () use ($live): void {
            $player = $live();
            is_null($player) || ($player->player->isPlaying() ? $player->pause() : $player->play());
        });
        g_signal_connect($mute, 'clicked', static function () use ($live): void {
            $player = $live();
            if (! is_null($player)) {
                $muted = ! $player->player->isMuted();
                $player->setMuted($muted);
                $player->listener->get()?->videoMuted($muted);
            }
        });
        // change-value is the user's move only: setValue() from the position timeout makes none.
        g_signal_connect($this->scrub, 'change-value', static function (GtkScale $scale, int $scroll, float $value) use ($live): bool {
            $live()?->seek(max(0.0, $value));

            return false;
        });
    }

    /** @return array{GtkButton, GtkImage} a flat button holding one of GTK's icons */
    private static function barButton(string $icon, string $name): array
    {
        $image = GtkImage::newFromIconName($icon);
        $button = GtkButton::newWithLabel('');
        $button->setChild($image);
        $button->setHasFrame(false);
        $button->setTooltipText($name);

        return [$button, $image];
    }

    private function showPlaying(bool $playing): void
    {
        $this->toggle->setFromIconName($playing ? 'media-playback-pause-symbolic' : 'media-playback-start-symbolic');
        $this->toggle->getParent()?->setTooltipText($playing ? 'Pause' : 'Play');
        $this->followPosition();
        $this->showPosition();
    }

    /** The position timeout runs while the media plays with the bar shown, and only then. */
    private function followPosition(): void
    {
        $follow = $this->controls && ! $this->released && $this->player->isPlaying();
        if ($follow && is_null($this->positionSource)) {
            $self = WeakReference::create($this);
            $this->positionSource = g_timeout_add($this->positionInterval, static function () use ($self): bool {
                $self->get()?->showPosition();

                return true;
            });
        } elseif (! $follow && ! is_null($this->positionSource)) {
            g_source_remove($this->positionSource);
            $this->positionSource = null;
        }
    }

    private function showPosition(): void
    {
        $position = $this->player->position();
        $this->scrub->setValue($position);
        $this->time->setText(self::clock($position).' / '.self::clock($this->player->duration() ?? 0.0));
    }

    private static function clock(float $seconds): string
    {
        $whole = (int) floor($seconds);

        return sprintf('%d:%02d', intdiv($whole, 60), $whole % 60);
    }
}

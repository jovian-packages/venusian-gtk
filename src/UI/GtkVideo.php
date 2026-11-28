<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GtkConstraint;
use GtkOrientation;
use GtkWidget;
use GtkLayoutManager;
use GtkConstraintGuide;
use GtkConstraintLayout;
use GtkConstraintStrength;
use GtkConstraintRelation;
use GtkConstraintAttribute;
use Jovian\Toolkits\GTK\GtkWindow;
use Surface\Contracts\Toolkits\UI\Align;
use Surface\Toolkits\UI\TKVideo;
use Jovian\Toolkits\GTK\Video\GtkVideoDriver;
use Jovian\Toolkits\GTK\Video\GtkVideoPlayer;
use Jovian\Toolkits\GTK\Video\GtkVideoListener;
use Jovian\Toolkits\GTK\Video\VideoDriverUnsupported;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A video view. The player is made by the video manager's driver (gtk.video.driver): this view
 * places the player's widget and hands it every call. What the player reports comes back
 * through GtkVideoListener and is posted as Topside's video mail; a muting the player's own
 * transport makes is this view's muting too.
 *
 * The native is a box of its own holding the player's widget, so the view keeps its size to
 * itself: the box says its own expand (the player's widgets do not become the view's), and a
 * maximum size is GTK's constraint layout on the box, as on an image: the player fills the box,
 * its size is a guide's whose maximum is the cap, and whose natural size is the cap on an axis
 * that wants its room (it fills, or is FILL-aligned). GTK hands a FILL-aligned child the whole
 * room whatever it measures, so on a capped axis FILL is read as START, as AppKit places a
 * capped child: the view takes the room up to its maximum, from that edge.
 *
 * The view asks the manager for its driver once. A driver made on a system it has no player for
 * throws VideoDriverUnsupported (the 'gtk' driver on macOS): the view catches it and has no
 * player, so supported() is false, the box holds an empty box where the player's widget would
 * be, and topside answers every source with VideoFailed (TKVideo::NO_PLAYER) itself. Every other
 * throw (a driver name the manager does not know, a driver missing what it needs) goes on.
 * Otherwise supported() is the driver's answer.
 */
class GtkVideo extends TKVideo implements GtkVideoListener
{
    use GtkNative {
        releaseNative as private releaseWidget;
        applyAlign as private alignBox;
        applyFill as private expandBox;
    }

    /** GTK's largest int: no cap on an axis. */
    private const int NO_CAP = 2147483647;

    private ?GtkVideoPlayer $player = null;

    /** The driver; null when this system has no player for it. */
    private ?GtkVideoDriver $driver = null;

    /** The manager was asked for the driver. */
    private bool $asked = false;

    /** What the box holds: the player's widget, or an empty box with no player. */
    private ?GtkWidget $content = null;

    /** The box's own layout, kept while a maximum size replaces it. */
    private ?GtkLayoutManager $own_layout = null;

    /** The guide whose size is the player's while a maximum size is set. */
    private ?GtkConstraintGuide $guide = null;

    public function supported(): bool
    {
        return $this->driver()?->supported() ?? false;
    }

    protected function bootstrapNative(): void
    {
        $this->player = $this->driver()?->player($this);
        $widget = $this->content = $this->player?->widget() ?? GtkBox::new(GtkOrientation::VERTICAL, 0);
        $widget->setHexpand(true);
        $widget->setVexpand(true);

        $box = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $box->append($widget);
        // Said, so that the player's expand does not become the box's.
        $box->setHexpand(false);
        $box->setVexpand(false);
        $this->adoptNative($box);
    }

    protected function applyFill(bool $horizontal, bool $vertical): void
    {
        $this->expandBox($horizontal, $vertical);
        $this->sizeGuide();
    }

    protected function applyAlign(Align $horizontal, Align $vertical): void
    {
        [$cap_width, $cap_height] = $this->maximumSize();
        $this->alignBox(
            $cap_width > 0 && $horizontal === Align::FILL ? Align::START : $horizontal,
            $cap_height > 0 && $vertical === Align::FILL ? Align::START : $vertical,
        );
        $this->sizeGuide();
    }

    protected function applyMaxSize(int $width, int $height): void
    {
        if ($width === 0 && $height === 0) {
            if (! is_null($this->guide)) {
                $this->native->setLayoutManager($this->own_layout);
                [$this->own_layout, $this->guide] = [null, null];
            }
            $this->applyAlign(...$this->aligned());

            return;
        }

        if (is_null($this->guide)) {
            $widget = $this->content;
            $this->own_layout = $this->native->getLayoutManager();
            $layout = GtkConstraintLayout::new();
            $this->native->setLayoutManager($layout);

            $required = GtkConstraintStrength::REQUIRED->value;
            foreach ([GtkConstraintAttribute::LEFT, GtkConstraintAttribute::TOP, GtkConstraintAttribute::RIGHT, GtkConstraintAttribute::BOTTOM] as $edge) {
                $layout->addConstraint(GtkConstraint::new($widget, $edge, GtkConstraintRelation::EQ, null, $edge, 1.0, 0.0, $required));
            }

            $this->guide = GtkConstraintGuide::new();
            $layout->addGuide($this->guide);
            foreach ([GtkConstraintAttribute::WIDTH, GtkConstraintAttribute::HEIGHT] as $side) {
                $layout->addConstraint(GtkConstraint::new($widget, $side, GtkConstraintRelation::EQ, $this->guide, $side, 1.0, 0.0, $required));
            }
        }

        $this->applyAlign(...$this->aligned());
    }

    /**
     * The guide's maximum on each axis is the cap (none where there is none); its natural size
     * is the cap where the view wants its room on that axis, else the player's own.
     */
    private function sizeGuide(): void
    {
        if (is_null($this->guide)) {
            return;
        }

        $widget = $this->content;
        $own = [$widget->measure(GtkOrientation::HORIZONTAL, -1)['natural'], $widget->measure(GtkOrientation::VERTICAL, -1)['natural']];
        $caps = $this->maximumSize();
        $fills = $this->fills();
        $aligned = $this->aligned();
        $natural = [];
        foreach ([0, 1] as $axis) {
            $wants = $fills[$axis] || $aligned[$axis] === Align::FILL;
            $natural[$axis] = $caps[$axis] > 0 && $wants ? $caps[$axis] : min($own[$axis], $caps[$axis] > 0 ? $caps[$axis] : self::NO_CAP);
        }

        $this->guide->setNatSize($natural[0], $natural[1]);
        $this->guide->setMaxSize($caps[0] > 0 ? $caps[0] : self::NO_CAP, $caps[1] > 0 ? $caps[1] : self::NO_CAP);
    }

    protected function releaseNative(): void
    {
        $this->player?->release();
        [$this->player, $this->content] = [null, null];
        $this->releaseWidget();
    }

    protected function applySource(?string $url): void
    {
        $this->player->load($url, $this->muted, $this->looping, $this->controls);
    }

    protected function applyPlay(): void
    {
        $this->player->play();
    }

    protected function applyPause(): void
    {
        $this->player->pause();
    }

    protected function applySeek(float $seconds): void
    {
        $this->player->seek($seconds);
    }

    protected function applyMuted(bool $muted): void
    {
        $this->player->setMuted($muted);
    }

    protected function applyLoop(bool $loop): void
    {
        $this->player->setLoop($loop);
    }

    protected function applyControls(bool $controls): void
    {
        $this->player->setControls($controls);
        $this->sizeGuide();
    }

    protected function readPosition(): float
    {
        return $this->player->position();
    }

    protected function readDuration(): ?float
    {
        return $this->player->duration();
    }

    /** The player the driver made for this view; null when this system has no player for it. */
    public function player(): ?GtkVideoPlayer
    {
        return $this->player;
    }

    public function videoPlaying(): void
    {
        $this->playing();
    }

    public function videoPaused(): void
    {
        $this->paused();
    }

    public function videoEnded(): void
    {
        $this->ended();
    }

    public function videoFailed(?string $reason): void
    {
        $this->failed($reason);
    }

    public function videoMuted(bool $muted): void
    {
        if (! $this->isRemoved()) {
            $this->setMuted($muted);
        }
    }

    /**
     * The driver, asked for once; null when it cannot be made on this system.
     */
    private function driver(): ?GtkVideoDriver
    {
        if (! $this->asked) {
            $this->asked = true;
            /** @var GtkWindow $window */
            $window = $this->window();
            try {
                $this->driver = $window->adapter()->video()->driver();
            } catch (VideoDriverUnsupported) {
                $this->driver = null;
            }
        }

        return $this->driver;
    }
}

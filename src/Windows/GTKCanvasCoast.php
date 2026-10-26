<?php

namespace Jovian\Toolkits\GTK\Windows;

use Jovian\Toolkits\GTK\Primitives\GTKCanvas;
use Surface\Contracts\Windows\Mail\View\ScrollPhase;
use WeakReference;

/**
 * The coast after a flick, posted as MOMENTUM wheels. A kinetic scroll controller hands over the
 * gesture's velocity in pixels per ms (decelerate); GTK's own kinetic scrolling is private, so
 * the velocity decays here, exp(−elapsed / 325 ms) (my call), and each session pump ticks it: the
 * delta is the velocity now times the ms since the last tick. Below 0.01 px/ms on both axes it
 * ends with one MOMENTUM_ENDED. A new gesture or a press ends it the same way; a removed canvas
 * ends it with no mail.
 */
final class GTKCanvasCoast
{
    private const float DECAY_MS = 325.0;

    private const float STOP = 0.01;

    /** @var WeakReference<GTKCanvas> */
    private readonly WeakReference $canvas;

    private float $last_ms;

    private bool $done = false;

    public function __construct(GTKCanvas $canvas, private readonly float $vx, private readonly float $vy, private readonly float $started_ms)
    {
        $this->canvas = WeakReference::create($canvas);
        $this->last_ms = $started_ms;
    }

    public static function nowMs(): float
    {
        return hrtime(true) / 1e6;
    }

    /** When the coast began, in nowMs() time. */
    public function started(): float
    {
        return $this->started_ms;
    }

    public function done(): bool
    {
        return $this->done;
    }

    /** One step at $now_ms: a MOMENTUM wheel, or the end. */
    public function tick(float $now_ms): void
    {
        $canvas = $this->canvas->get();
        if ($this->done || is_null($canvas) || $canvas->isRemoved()) {
            $this->done = true;

            return;
        }
        $decay = exp(-($now_ms - $this->started_ms) / self::DECAY_MS);
        [$vx, $vy] = [$this->vx * $decay, $this->vy * $decay];
        if (abs($vx) < self::STOP && abs($vy) < self::STOP) {
            $this->end();

            return;
        }
        $elapsed = $now_ms - $this->last_ms;
        $this->last_ms = $now_ms;
        if ($elapsed > 0.0) {
            $canvas->coasted($vx * $elapsed, $vy * $elapsed, ScrollPhase::MOMENTUM);
        }
    }

    /** End it now with MOMENTUM_ENDED; nothing for a removed canvas or a coast already over. */
    public function end(): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $canvas = $this->canvas->get();
        if (! is_null($canvas) && ! $canvas->isRemoved()) {
            $canvas->coasted(0.0, 0.0, ScrollPhase::MOMENTUM_ENDED);
        }
    }

    /** Over with no mail: the canvas is going. */
    public function drop(): void
    {
        $this->done = true;
    }
}

<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkScale;
use WeakReference;
use GtkOrientation;
use RuntimeException;
use Surface\Toolkits\UI\TKSlider;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkScale without its number.
 */
class GtkSlider extends TKSlider
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        [$min, $max] = [$this->min(), $this->max()];
        $scale = GtkScale::newWithRange(GtkOrientation::HORIZONTAL, $min, $max, ($max - $min) / 100)
            ?? throw new RuntimeException("GTK made no slider from {$min} to {$max}.");
        $scale->setDrawValue(false);
        // newWithRange() rounds to its step's digits until the increments are set.
        $scale->setIncrements(($max - $min) / 100, ($max - $min) / 10);
        $scale->setValue($this->value());
        $this->adoptNative($scale);
        $this->declare('min-width', '120px');

        $self = WeakReference::create($this);
        $this->connect($scale, 'value-changed', static function () use ($self): void {
            $slider = $self->get();
            $slider?->slid($slider->scaleNative()->getValue());
        });
    }

    protected function applyValue(float $value): void
    {
        $this->scaleNative()->setValue($value);
    }

    protected function applyRange(float $min, float $max, float $value): void
    {
        $scale = $this->scaleNative();
        $scale->setRange($min, $max);
        $scale->setIncrements(($max - $min) / 100, ($max - $min) / 10);
        $scale->setValue($value);
    }

    private function scaleNative(): GtkScale
    {
        /** @var GtkScale */
        return $this->native();
    }
}

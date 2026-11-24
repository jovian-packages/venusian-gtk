<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use WeakReference;
use GtkOrientation;
use GtkToggleButton as NativeToggleButton;
use Surface\Toolkits\UI\TKSegmentedControl;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * Linked GtkToggleButtons in one group: GTK has no segmented control.
 */
class GtkSegmentedControl extends TKSegmentedControl
{
    use GtkNative {
        releaseNative as private releaseWidget;
    }

    /** @var list<array{NativeToggleButton, int}> each button and its handler */
    private array $buttons = [];

    protected function bootstrapNative(): void
    {
        $box = GtkBox::new(GtkOrientation::HORIZONTAL, 0);
        $box->addCssClass('linked');
        $this->adoptNative($box);
        $this->write(fn () => $this->applySegments($this->segments(), $this->selected()));
    }

    protected function applySegments(array $segments, int $selected): void
    {
        $this->dropButtons();

        $self = WeakReference::create($this);
        foreach ($segments as $index => $label) {
            $button = NativeToggleButton::newWithLabel($label);
            if ($this->buttons !== []) {
                $button->setGroup($this->buttons[0][0]);
            }
            $handler = g_signal_connect($button, 'toggled', static function () use ($self, $index): void {
                $control = $self->get();
                if (isset($control?->buttons[$index]) && $control->buttons[$index][0]->getActive()) {
                    $control->chosen($index);
                }
            });
            $this->boxNative()->append($button);
            $this->buttons[] = [$button, $handler];
        }

        if ($selected >= 0) {
            $this->applySelected($selected);
        }
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->buttons[$index][0]->setLabel($label);
    }

    protected function applySelected(int $index): void
    {
        $this->buttons[$index][0]->setActive(true);
    }

    protected function releaseNative(): void
    {
        $this->dropButtons();
        $this->releaseWidget();
    }

    private function dropButtons(): void
    {
        foreach ($this->buttons as [$button, $handler]) {
            g_signal_handler_disconnect($button, $handler);
            $this->boxNative()->remove($button);
        }
        $this->buttons = [];
    }

    private function boxNative(): GtkBox
    {
        /** @var GtkBox */
        return $this->native();
    }
}

<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKSegmentedControl;

/**
 * A horizontal GtkBox with GTK's "linked" style holding one GtkToggleButton per segment, all in
 * one group so one is active at a time. A button the user makes active posts SelectionChanged.
 */
class GTKSegmentedControl extends TKSegmentedControl implements GTKView
{
    use GTKPrimitive;

    /**
     * @var list<\GtkToggleButton>
     */
    protected array $buttons = [];

    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $segments, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $segments, $selected);
        $box = \GtkBox::new(\GtkOrientation::HORIZONTAL, 0);
        $box->addCssClass('linked');
        $this->adoptNative($box);
        $this->applySegments($this->segments);
        $this->applySelected($this->selected);
    }

    /**
     * @return list<\GtkToggleButton>
     */
    public function buttons(): array
    {
        return $this->buttons;
    }

    protected function applySegments(array $segments): void
    {
        $box = $this->widgetBox();
        foreach ($this->buttons as $button) {
            $box->remove($button);
        }
        $this->buttons = [];
        foreach ($segments as $index => $label) {
            $button = \GtkToggleButton::newWithLabel($label);
            if ($this->buttons !== []) {
                $button->setGroup($this->buttons[0]);
            }
            $this->connect($button, 'toggled', fn () => $this->toggled($index));
            $box->append($button);
            $this->buttons[] = $button;
        }
    }

    protected function applySegment(int $index, string $label): void
    {
        $this->buttons[$index]->setLabel($label);
    }

    protected function applySelected(int $index): void
    {
        if (isset($this->buttons[$index])) {
            $this->quietly(fn () => $this->buttons[$index]->setActive(true));
        }
    }

    protected function toggled(int $index): void
    {
        if ($this->applying || ! $this->buttons[$index]->getActive() || $index === $this->selected) {
            return;
        }
        $this->nativeSelected($index);
        $this->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $index, $this->segments[$index]));
    }

    protected function widgetBox(): \GtkBox
    {
        /** @var \GtkBox */
        return $this->native;
    }
}

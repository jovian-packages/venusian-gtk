<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\GTKWindow;
use Surface\Contracts\Toolkits\Mail\Views\SelectionChanged;
use Surface\Contracts\Toolkits\Primitives\Placement;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Toolkits\Primitives\TKDropdown;
use Surface\Toolkits\Primitives\TKPrimitiveGroup;

/**
 * A drop-down over GtkDropDown on a GtkStringList of the options. notify::selected posts
 * SelectionChanged with the index and option; no selection (no options) is -1 and null.
 */
class GTKDropdown extends TKDropdown implements GTKView
{
    use GTKPrimitive;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param list<string> $options
     * @param int $selected
     * @throws ToolkitRuntimeException When the name is not valid, the options are not a list of strings, or the index is out of range.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $options, int $selected)
    {
        parent::__construct($name, $window, $parent, $placement, $options, $selected);
        $this->adoptNative(\GtkDropDown::newFromStrings($this->options));
        $this->applySelected($this->selected);
        $this->connect($this->native, 'notify::selected', function (): void {
            if ($this->applying) {
                return;
            }
            $selected = $this->widgetDropDown()->getSelected();
            $this->nativeSelected($selected === GTK_INVALID_LIST_POSITION ? -1 : $selected);
            $this->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $this->selected, $this->selectedOption()));
        });
    }

    /**
     * @param list<string> $options
     * @return void
     */
    protected function applyOptions(array $options): void
    {
        $this->quietly(fn () => $this->widgetDropDown()->setModel(\GtkStringList::new($options)));
    }

    protected function applySelected(int $index): void
    {
        $this->quietly(fn () => $this->widgetDropDown()->setSelected($index < 0 ? GTK_INVALID_LIST_POSITION : $index));
    }

    protected function widgetDropDown(): \GtkDropDown
    {
        /** @var \GtkDropDown */
        return $this->native;
    }
}

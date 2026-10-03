<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextInput;

/**
 * A single-line input over GtkEntry. Each user-visible edit emits the entry's changed once
 * (a paste over a selection too, though the buffer sees a delete and an insert): TextChanged.
 * Enter emits activate: TextSubmitted. A secret input hides its characters.
 */
class GTKTextInput extends TKTextInput implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $value
     * @param string|null $placeholder
     * @param bool $secret
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, ?string $placeholder, bool $secret)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $placeholder, $secret);
        $this->adoptNative(\GtkEntry::new());
        $this->widgetEntry()->setVisibility(! $secret);
        $this->widgetEntry()->setPlaceholderText($placeholder);
        $this->applyValue($value);

        $this->connect($this->native, 'changed', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeValueChanged($this->widgetEntry()->getBuffer()->getText());
            $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });
        $this->connect($this->native, 'activate', fn () => $this->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value)));
    }

    /**
     * Background and colour reach the entry's own text node.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return ['> text'];
    }

    protected function applyValue(string $value): void
    {
        $this->quietly(fn () => $this->widgetEntry()->getBuffer()->setText($value, -1));
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->widgetEntry()->setPlaceholderText($placeholder);
    }

    protected function widgetEntry(): \GtkEntry
    {
        /** @var \GtkEntry */
        return $this->native;
    }
}

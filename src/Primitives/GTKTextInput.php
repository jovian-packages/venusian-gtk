<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKMacTextKeys;
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

    private ?\GtkEventControllerKey $mac_keys = null;

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
        if (PHP_OS_FAMILY === 'Darwin') {
            // The entry's GtkText holds focus while it is edited; it is what the keybinding signals belong to.
            $this->mac_keys = GTKMacTextKeys::attach($this->native, function (): ?\GtkWidget {
                $focus = $this->window->native()->getFocus();

                return $focus?->typeName() === 'GtkText' ? $focus : null;
            });
        }
    }

    /**
     * The Mac text keys' controller on the entry; null off macOS.
     * @return \GtkEventControllerKey|null
     */
    public function macKeys(): ?\GtkEventControllerKey
    {
        return $this->mac_keys;
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

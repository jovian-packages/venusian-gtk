<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkEntry;
use WeakReference;
use Surface\Toolkits\UI\TKTextInput;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Jovian\Toolkits\GTK\UI\Concerns\HoldsEntryFocus;
use Jovian\Toolkits\GTK\UI\Support\GtkMacTextKeys;

/**
 * A GtkEntry. changed is typing (topside drops the second one macOS gives for a key),
 * activate a submit. On macOS it also gets the Mac's text keys.
 */
class GtkTextInput extends TKTextInput
{
    use GtkNative, HoldsEntryFocus {
        HoldsEntryFocus::hasFocus insteadof GtkNative;
        HoldsEntryFocus::nativeFocus insteadof GtkNative;
    }
    use GtkText;

    protected function bootstrapNative(): void
    {
        $entry = GtkEntry::new();
        $entry->setVisibility(! $this->isSecret());
        $entry->setPlaceholderText($this->placeholder());
        $entry->getBuffer()->setText($this->value(), -1);
        $this->adoptNative($entry);

        $self = WeakReference::create($this);
        $this->connect($entry, 'changed', static function () use ($self): void {
            $input = $self->get();
            if (! is_null($input) && ! $input->isRemoved()) {
                $input->typed($input->entryNative()->getBuffer()->getText());
            }
        });
        $this->connect($entry, 'activate', static function () use ($self): void {
            $input = $self->get();
            if (! is_null($input) && ! $input->isRemoved()) {
                $input->submitted($input->entryNative()->getBuffer()->getText());
            }
        });

        if (PHP_OS_FAMILY === 'Darwin') {
            GtkMacTextKeys::attach($entry, static function () use ($self): ?\GtkWidget {
                $focus = $self->get()?->native?->getRoot()?->getFocus();

                return $focus?->typeName() === 'GtkText' ? $focus : null;
            });
        }
    }

    protected function applyValue(string $value): void
    {
        $this->entryNative()->getBuffer()->setText($value, -1);
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->entryNative()->setPlaceholderText($placeholder);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        g_object_set_property($this->entryNative(), 'xalign', self::xalign($alignment));
    }

    protected function entryNative(): GtkEntry
    {
        /** @var GtkEntry */
        return $this->native();
    }
}

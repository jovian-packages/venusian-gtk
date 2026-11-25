<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkEntry;
use GtkEntryIconPosition;
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
 *
 * A search field is GTK's search look on the entry, the search icon at its start (a
 * GtkSearchEntry is another widget, without the entry's buffer). Return also activates the
 * window's default button. Borderless is the frameless entry, read-only an entry the user
 * cannot edit, the maximum length GTK's own.
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
        $entry->setActivatesDefault(true);
        if ($this->isSearch()) {
            $entry->setIconFromIconName(GtkEntryIconPosition::PRIMARY, 'system-search-symbolic');
        }
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

    /**
     * A maximum length cuts a longer value: the cut text is the value.
     */
    protected function applyValue(string $value): void
    {
        $this->entryNative()->getBuffer()->setText($value, -1);
        $this->takeCut();
    }

    protected function applyPlaceholder(?string $placeholder): void
    {
        $this->entryNative()->setPlaceholderText($placeholder);
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        g_object_set_property($this->entryNative(), 'xalign', self::xalign($alignment));
    }

    protected function applyBorderless(bool $borderless): void
    {
        $this->entryNative()->setHasFrame(! $borderless);
    }

    protected function applyReadOnly(bool $readOnly): void
    {
        $this->entryNative()->setEditable(! $readOnly);
    }

    /**
     * GTK cuts longer text to the length itself: that cut is code's, so it posts nothing, and
     * the cut text is the value.
     */
    protected function applyMaxLength(?int $length): void
    {
        $this->write(fn () => $this->entryNative()->setMaxLength($length ?? 0));
        $this->takeCut();
    }

    private function takeCut(): void
    {
        $text = $this->entryNative()->getBuffer()->getText();
        if ($text !== $this->value()) {
            $this->cutTo($text);
        }
    }

    protected function entryNative(): GtkEntry
    {
        /** @var GtkEntry */
        return $this->native();
    }
}

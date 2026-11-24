<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GtkEntry;
use GtkPopover;
use WeakReference;
use GtkOrientation;
use GtkMenuButton;
use GtkPropagationPhase;
use GtkEventControllerKey;
use GtkEventControllerFocus;
use Surface\Toolkits\UI\TKComboBox;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Jovian\Toolkits\GTK\UI\Concerns\HoldsEntryFocus;
use Jovian\Toolkits\GTK\UI\Support\GtkMacTextKeys;
use Jovian\Toolkits\GTK\UI\Support\GtkCompletionList;

/**
 * A linked box of a GtkEntry and a menu button listing the options. The completions show in
 * a popover under the entry that takes no focus; a capture-phase key controller on the entry
 * moves and chooses in it, and Return with no row highlighted goes on to the entry's activate.
 */
class GtkComboBox extends TKComboBox
{
    use GtkNative, HoldsEntryFocus {
        HoldsEntryFocus::hasFocus insteadof GtkNative;
        HoldsEntryFocus::nativeFocus insteadof GtkNative;
        GtkNative::releaseNative as private releaseWidget;
    }

    private const int UP = 0xff52;

    private const int DOWN = 0xff54;

    private const int RETURN = 0xff0d;

    private const int KP_ENTER = 0xff8d;

    private const int ESCAPE = 0xff1b;

    private GtkEntry $entry;

    private GtkMenuButton $button;

    private GtkCompletionList $options_list;

    private GtkCompletionList $list;

    protected function bootstrapNative(): void
    {
        $box = GtkBox::new(GtkOrientation::HORIZONTAL, 0);
        $box->addCssClass('linked');
        $this->entry = GtkEntry::new();
        $this->entry->setHexpand(true);
        $this->button = GtkMenuButton::new();
        $box->append($this->entry);
        $box->append($this->button);
        $this->adoptNative($box);
        // The entry's hexpand would reach up through the box: the box's own is fill()'s.
        $box->setHexpand(false);

        $self = WeakReference::create($this);
        $this->options_list = new GtkCompletionList(static function (int $row) use ($self): void {
            $self->get()?->pickOption($row);
        });
        $this->list = new GtkCompletionList(static function (int $row) use ($self): void {
            $self->get()?->pickCompletion($row);
        });

        $popover = GtkPopover::new();
        $popover->setChild($this->options_list->content());
        $this->button->setPopover($popover);
        $this->connect($popover, 'show', static function () use ($self): void {
            $self->get()?->options_list->fit();
        });

        $this->applyOptions($this->options());
        $this->entry->getBuffer()->setText($this->value(), -1);

        $this->connect($this->entry, 'changed', static function () use ($self): void {
            $combo = $self->get();
            $combo?->typed($combo->entry->getBuffer()->getText());
        });
        $this->connect($this->entry, 'activate', static function () use ($self): void {
            $combo = $self->get();
            $combo?->submitted($combo->entry->getBuffer()->getText());
        });

        $keys = GtkEventControllerKey::new();
        $keys->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $this->connect($keys, 'key-pressed', static fn (GtkEventControllerKey $keys, int $keyval, int $keycode, int $state): bool => $self->get()?->command($keyval, $state) ?? false);
        $this->entry->addController($keys);

        $focus = GtkEventControllerFocus::new();
        $this->connect($focus, 'leave', static function () use ($self): void {
            $self->get()?->completionsHidden();
        });
        $this->entry->addController($focus);

        if (PHP_OS_FAMILY === 'Darwin') {
            GtkMacTextKeys::attach($this->entry, static function () use ($self): ?\GtkWidget {
                $focus = $self->get()?->native?->getRoot()?->getFocus();

                return $focus?->typeName() === 'GtkText' ? $focus : null;
            });
        }
    }

    protected function entryNative(): GtkEntry
    {
        return $this->entry;
    }

    protected function applyValue(string $value): void
    {
        $this->entry->getBuffer()->setText($value, -1);
    }

    protected function applyOptions(array $options): void
    {
        $this->options_list->setRows($options);
    }

    protected function applyCompletions(array $completions): void
    {
        $completions === [] ? $this->list->hide() : $this->list->show($this->entry, $completions);
    }

    protected function releaseNative(): void
    {
        $this->list->close();
        $this->button->popdown();
        $this->releaseWidget();
    }

    private function pickOption(int $row): void
    {
        $this->button->popdown();
        $this->choseOption($row);
    }

    private function pickCompletion(int $row): void
    {
        $text = $this->list->rows()[$row] ?? null;
        if (! is_null($text)) {
            $this->choseCompletion($text);
        }
    }

    /**
     * A key before the entry has it; true when the completion list took it.
     */
    private function command(int $keyval, int $state): bool
    {
        $modifiers = GDK_SHIFT_MASK | GDK_CONTROL_MASK | GDK_ALT_MASK | GDK_META_MASK | GDK_SUPER_MASK;
        if (! $this->list->isShown() || ($state & $modifiers) !== 0) {
            return false;
        }

        switch ($keyval) {
            case self::DOWN:
            case self::UP:
                $this->list->move($keyval === self::DOWN ? 1 : -1);

                return true;

            case self::ESCAPE:
                $this->completionsHidden();

                return true;

            case self::RETURN:
            case self::KP_ENTER:
                $row = $this->list->highlighted();
                if ($row >= 0) {
                    $this->pickCompletion($row);

                    return true;
                }
        }

        return false;
    }
}

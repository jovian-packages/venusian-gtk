<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKMacTextKeys;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKComboBox;
use Surface\Windows\Primitives\TKPrimitiveGroup;

/**
 * A linked horizontal box of a GtkEntry and a GtkMenuButton whose popover lists the options (a
 * row's activate is a choice, with the option's index). The completions show in a
 * GTKCompletionList under the entry. A capture-phase key controller on the entry takes Up, Down,
 * Return and Escape while they show; Return with no row highlighted goes on to the entry's
 * activate, which posts TextSubmitted. The entry's focus controller hides them when focus leaves.
 * The entry's changed posts TextChanged when the text differs from the value last seen, as
 * GTKTextInput's does.
 */
class GTKComboBox extends TKComboBox implements GTKView
{
    use GTKPrimitive;

    private const int UP = 0xff52;

    private const int DOWN = 0xff54;

    private const int RETURN = 0xff0d;

    private const int KP_ENTER = 0xff8d;

    private const int ESCAPE = 0xff1b;

    protected \GtkEntry $entry;

    protected \GtkMenuButton $button;

    protected GTKCompletionList $options_list;

    protected GTKCompletionList $list;

    protected \GtkEventControllerKey $keys;

    private ?\GtkEventControllerKey $mac_keys = null;

    /**
     * @param list<string> $options
     * @throws WindowException When the name is not valid or the options are not a list of strings.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value, array $options)
    {
        parent::__construct($name, $window, $parent, $placement, $value, $options);
        $box = \GtkBox::new(\GtkOrientation::HORIZONTAL, 0);
        $box->addCssClass('linked');
        $this->entry = \GtkEntry::new();
        $this->entry->setHexpand(true);
        $this->button = \GtkMenuButton::new();
        $box->append($this->entry);
        $box->append($this->button);
        $this->adoptNative($box);

        // The lists' handlers are held by GTK, where PHP's collector cannot see them: a weak reference keeps them from holding the combo.
        $self = \WeakReference::create($this);
        $this->options_list = new GTKCompletionList(static fn (int $row) => $self->get()?->chooseOption($row));
        $popover = \GtkPopover::new();
        $popover->setChild($this->options_list->content());
        $this->button->setPopover($popover);
        $this->connect($popover, 'show', fn () => $this->options_list->fit());
        $this->list = new GTKCompletionList(static fn (int $row) => $self->get()?->chooseCompletion($row));
        $this->applyOptions($this->options);
        $this->quietly(fn () => $this->entry->getBuffer()->setText($value, -1));

        $this->connect($this->entry, 'changed', function (): void {
            if ($this->applying) {
                return;
            }
            $text = $this->entry->getBuffer()->getText();
            if ($text === $this->value) {
                return;
            }
            $this->nativeEdited($text);
            $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });
        $this->connect($this->entry, 'activate', fn () => $this->submitted());

        $this->keys = \GtkEventControllerKey::new();
        $this->keys->setPropagationPhase(\GtkPropagationPhase::CAPTURE);
        $this->connect($this->keys, 'key-pressed', fn (\GtkEventControllerKey $keys, int $keyval, int $keycode, int $state): bool => $this->command($keyval, $state));
        $this->entry->addController($this->keys);
        $focus = \GtkEventControllerFocus::new();
        $this->connect($focus, 'leave', fn () => $this->editingEnded());
        $this->entry->addController($focus);
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->mac_keys = GTKMacTextKeys::attach($this->entry, static function () use ($self): ?\GtkWidget {
                $focus = $self->get()?->window->native()->getFocus();

                return $focus?->typeName() === 'GtkText' ? $focus : null;
            });
        }
    }

    /** The entry holds focus for the combo. */
    public function focusWidget(): \GtkWidget
    {
        return $this->entry;
    }

    public function entry(): \GtkEntry
    {
        return $this->entry;
    }

    public function optionsButton(): \GtkMenuButton
    {
        return $this->button;
    }

    /** The options' list view, in the options button's popover. */
    public function optionsList(): \GtkListView
    {
        return $this->options_list->view();
    }

    /** @return list<string> */
    public function optionRows(): array
    {
        return $this->options_list->rows();
    }

    /** The list the completions show in, for reads. */
    public function completionList(): GTKCompletionList
    {
        return $this->list;
    }

    /** The entry's capture-phase key controller for the completions. */
    public function keys(): \GtkEventControllerKey
    {
        return $this->keys;
    }

    /** The Mac text keys' controller on the entry; null off macOS. */
    public function macKeys(): ?\GtkEventControllerKey
    {
        return $this->mac_keys;
    }

    protected function applyValue(string $value): void
    {
        $this->list->hide();
        $this->quietly(fn () => $this->entry->getBuffer()->setText($value, -1));
    }

    protected function applyOptions(array $options): void
    {
        $this->options_list->setRows($options);
    }

    protected function applyCompletions(array $completions): bool
    {
        if (! $this->nativeHasFocus()) {
            $this->list->hide();

            return false;
        }
        if ($completions === []) {
            $this->list->hide();

            return true;
        }
        $this->list->show($this->entry, $completions);

        return true;
    }

    protected function releaseNative(): void
    {
        $this->list->close();
        $this->button->popdown();
    }

    protected function chooseOption(int $row): void
    {
        $text = $this->options[$row] ?? null;
        if (is_null($text)) {
            return;
        }
        $this->button->popdown();
        $this->list->hide();
        $this->quietly(fn () => $this->entry->getBuffer()->setText($text, -1));
        $this->nativeChose($text);
        $this->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, $row, $text));
    }

    protected function chooseCompletion(int $row): void
    {
        $text = $this->list->rows()[$row] ?? null;
        if (is_null($text)) {
            return;
        }
        $this->list->hide();
        $this->quietly(fn () => $this->entry->getBuffer()->setText($text, -1));
        $this->nativeChose($text);
        $this->post(new SelectionChanged($this->window->name(), $this->path(), $this->uuid, -1, $text));
    }

    protected function editingEnded(): void
    {
        if ($this->list->isShown()) {
            $this->list->hide();
            $this->nativeHidden();
        }
    }

    /** A key on the entry, before the entry has it; true when the completion list took it. */
    protected function command(int $keyval, int $state): bool
    {
        $modifiers = GDK_SHIFT_MASK | GDK_CONTROL_MASK | GDK_ALT_MASK | GDK_META_MASK | GDK_SUPER_MASK;
        if (! $this->list->isShown() || ($state & $modifiers) !== 0) {
            return false;
        }

        switch ($keyval) {
            case self::DOWN:
                $this->list->move(1);

                return true;
            case self::UP:
                $this->list->move(-1);

                return true;
            case self::RETURN:
            case self::KP_ENTER:
                $row = $this->list->highlighted();
                if ($row < 0) {
                    return false;
                }
                $this->chooseCompletion($row);

                return true;
            case self::ESCAPE:
                $this->list->hide();
                $this->nativeHidden();

                return true;
        }

        return false;
    }

    protected function submitted(): void
    {
        $this->list->hide();
        $this->nativeEdited($this->entry->getBuffer()->getText());
        $this->nativeHidden();
        $this->post(new TextSubmitted($this->window->name(), $this->path(), $this->uuid, $this->value));
    }
}

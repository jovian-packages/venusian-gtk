<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;

/**
 * A multi-line editor: a GtkTextView inside a GtkScrolledWindow, which is the primitive's
 * widget (and carries its style class). GTK wraps each user edit in a user action, inside
 * which the buffer may change more than once (a paste over a selection deletes, then
 * inserts): one TextChanged goes out when the outermost action ends, with the final text.
 * A change outside any action posts on its own.
 */
class GTKTextArea extends TKTextArea implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    protected \GtkTextView $text_view;

    /**
     * Nesting depth of the buffer's user actions, and whether the text changed inside them.
     */
    protected int $user_action_depth = 0;

    protected bool $changed_in_action = false;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $value
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $this->text_view = \GtkTextView::new();
        $this->text_view->setWrapMode(\GtkWrapMode::WORD_CHAR);
        $scrolled = \GtkScrolledWindow::new();
        $scrolled->setPolicy(\GtkPolicyType::NEVER, \GtkPolicyType::AUTOMATIC);
        $scrolled->setChild($this->text_view);
        $this->adoptNative($scrolled);
        $this->applyValue($value);

        $buffer = $this->text_view->getBuffer();
        $this->connect($buffer, 'begin-user-action', function (): void {
            $this->user_action_depth++;
        });
        $this->connect($buffer, 'end-user-action', function (): void {
            $this->user_action_depth = max(0, $this->user_action_depth - 1);
            if ($this->user_action_depth === 0 && $this->changed_in_action) {
                $this->changed_in_action = false;
                $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
            }
        });
        $this->connect($buffer, 'changed', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeValueChanged($this->text_view->getBuffer()->getText(0, -1));
            if ($this->user_action_depth > 0) {
                $this->changed_in_action = true;
                return;
            }
            $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });
    }

    /**
     * The text view inside the scrolled window.
     * @return \GtkTextView
     */
    public function textView(): \GtkTextView
    {
        return $this->text_view;
    }

    /**
     * Background and colour reach the text view and its text node, which the theme colours.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return ['> textview', '> textview > text'];
    }

    protected function applyValue(string $value): void
    {
        $this->quietly(fn () => $this->text_view->getBuffer()->setText($value));
    }
}

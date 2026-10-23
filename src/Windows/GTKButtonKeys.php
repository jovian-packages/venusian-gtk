<?php

namespace Jovian\Toolkits\GTK\Windows;

use GtkApplicationWindow;
use GtkCallbackAction;
use GtkPopover;
use GtkPropagationPhase;
use GtkShortcut;
use GtkShortcutAction;
use GtkShortcutController;
use GtkShortcutTrigger;
use Jovian\Toolkits\GTK\Primitives\GTKTextArea;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Windows\Primitives\TKButton;
use WeakReference;

/**
 * A toolkit window's default and cancel buttons on the keyboard: one GtkShortcutController on the
 * window in the capture phase, which runs before the focused widget, with Return|KP_Enter and
 * Escape (a trigger without modifiers matches only a key without them). The callback activates
 * the holder (GtkButton emits clicked 250 ms later) and answers true; false lets the key go on:
 * Return to a focused text area, any key to an open popover (it is in the window's widget tree),
 * and any key whose holder cannot take it (insensitive, or not mapped: hidden, or in a tab not
 * selected). gtk_widget_activate does not check sensitivity, so this does. Esc in a child with no
 * cancel button is the user's close.
 */
final class GTKButtonKeys
{
    private GtkShortcutController $controller;

    private GtkShortcutAction $return_action;

    private GtkShortcutAction $escape_action;

    /** @var WeakReference<GTKWindow> the window holds this */
    private readonly WeakReference $window;

    public function __construct(GTKWindow $window, GtkApplicationWindow $native)
    {
        $this->window = WeakReference::create($window);
        // The actions hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding this.
        $self = WeakReference::create($this);
        $this->return_action = GtkCallbackAction::new(static fn (): bool => $self->get()?->press('return') ?? false);
        $this->escape_action = GtkCallbackAction::new(static fn (): bool => $self->get()?->press('escape') ?? false);
        $this->controller = GtkShortcutController::new();
        $this->controller->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $this->controller->addShortcut(GtkShortcut::new(GtkShortcutTrigger::parseString('Return|KP_Enter'), $this->return_action));
        $this->controller->addShortcut(GtkShortcut::new(GtkShortcutTrigger::parseString('Escape'), $this->escape_action));
        $native->addController($this->controller);
    }

    /** The action of the Return shortcut, for tests: activating it is the real path of a key. */
    public function returnAction(): GtkShortcutAction
    {
        return $this->return_action;
    }

    private bool $attached = true;

    /** The window closed: its controller goes with it, and no key is handled after. */
    public function detach(): void
    {
        $this->attached = false;
    }

    /**
     * A key of the window: 'return' (Return or keypad Enter) or 'escape'. Whether it clicked or
     * closed; false lets the key go on to the focused widget.
     */
    public function press(string $key): bool
    {
        $window = $this->window->get();
        if (! $this->attached || ! $window instanceof GTKWindow || ! $window->isOpen()) {
            return false;
        }
        $focus = $window->native()->getFocus();
        for ($at = $focus; ! is_null($at); $at = $at->getParent()) {
            if ($at instanceof GtkPopover) {
                return false;
            }
        }
        $role = $key === 'return' ? ButtonRole::DEFAULT : ButtonRole::CANCEL;
        if ($role === ButtonRole::DEFAULT && $this->textAreaFocused($window, $focus)) {
            return false;
        }

        $holder = TKButton::holder($window, $role);
        if (! is_null($holder)) {
            $button = $holder->native();
            if (! $button->isSensitive() || ! $button->getMapped()) {
                return false;
            }
            $button->activate();

            return true;
        }
        if ($role === ButtonRole::CANCEL && ! is_null($window->parent())) {
            $window->userClose();

            return true;
        }

        return false;
    }

    private function textAreaFocused(GTKWindow $window, ?\GtkWidget $focus): bool
    {
        foreach ($window->registry()->all() as $primitive) {
            if ($primitive instanceof GTKTextArea && $primitive->textView() === $focus) {
                return true;
            }
        }

        return false;
    }
}

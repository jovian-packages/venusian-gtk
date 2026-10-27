<?php

namespace Jovian\Toolkits\GTK\Services;

use Closure;
use GtkAlign;
use GtkBox;
use GtkButton;
use GtkCallbackAction;
use GtkCheckButton;
use GtkImage;
use GtkLabel;
use GtkOrientation;
use GtkPropagationPhase;
use GtkShortcut;
use GtkShortcutAction;
use GtkShortcutController;
use GtkShortcutTrigger;
use GtkWindow;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\AlertStyle;
use WeakReference;

/**
 * An alert GtkAlertDialog cannot show (a style, a suppression box or a title): a plain GtkWindow,
 * transient for the parent and modal, owned by GTKDialogs. A button answers its index, Esc and the
 * window's close the cancel index, each with the check button's state; dismiss() destroys it
 * without an answer. Return clicks the default button, as GtkAlertDialog's does.
 */
final class GTKComposedAlert
{
    public readonly GtkWindow $window;

    /** null for an alert with no style: the plain alert has no icon. */
    public readonly ?GtkImage $image;

    public readonly GtkLabel $message;

    public readonly ?GtkLabel $detail;

    public readonly ?GtkCheckButton $check;

    /** @var list<GtkButton> in reading order */
    public readonly array $buttons;

    private GtkShortcutAction $escape_action;

    private bool $done = false;

    /** @param Closure(int $button, bool $suppressed): void $answered called once, unless dismissed first */
    public function __construct(GtkWindow $parent, Alert $alert, private readonly Closure $answered)
    {
        $this->window = GtkWindow::new();
        $this->window->setTransientFor($parent);
        $this->window->setModal(true);
        $parent->getGroup()->addWindow($this->window);
        $this->window->setTitle($alert->title);

        $this->image = match ($alert->style) {
            null => null,
            AlertStyle::Info => GtkImage::newFromIconName('dialog-information'),
            AlertStyle::Warning => GtkImage::newFromIconName('dialog-warning'),
            AlertStyle::Critical => GtkImage::newFromIconName('dialog-error'),
        };
        $this->message = GtkLabel::new($alert->message);
        $this->detail = is_null($alert->detail) ? null : GtkLabel::new($alert->detail);
        $this->check = is_null($alert->suppression) ? null : GtkCheckButton::newWithLabel($alert->suppression);

        $text = GtkBox::new(GtkOrientation::VERTICAL, 6);
        foreach ([$this->message, $this->detail] as $label) {
            if (! is_null($label)) {
                $label->setWrap(true);
                $label->setXalign(0.0);
                $text->append($label);
            }
        }
        $top = GtkBox::new(GtkOrientation::HORIZONTAL, 12);
        if (! is_null($this->image)) {
            $this->image->setValign(GtkAlign::START);
            $top->append($this->image);
        }
        $top->append($text);

        $self = WeakReference::create($this);
        $row = GtkBox::new(GtkOrientation::HORIZONTAL, 6);
        $row->setHalign(GtkAlign::END);
        $buttons = [];
        foreach ($alert->buttons as $index => $label) {
            $button = GtkButton::newWithLabel($label);
            g_signal_connect($button, 'clicked', static fn () => $self->get()?->answer($index));
            $row->append($button);
            $buttons[] = $button;
        }
        $this->buttons = $buttons;

        $body = GtkBox::new(GtkOrientation::VERTICAL, 12);
        $body->setMarginTop(18);
        $body->setMarginBottom(18);
        $body->setMarginStart(18);
        $body->setMarginEnd(18);
        $body->append($top);
        if (! is_null($this->check)) {
            $body->append($this->check);
        }
        $body->append($row);
        $this->window->setChild($body);
        $this->window->setDefaultWidget($this->buttons[$alert->default]);

        // The actions hold their callables where PHP's collector cannot see them: a weak reference keeps them from holding this.
        $cancel = $alert->cancel;
        $default = $this->buttons[$alert->default];
        $this->escape_action = GtkCallbackAction::new(static fn (): bool => $self->get()?->answer($cancel) ?? false);
        $return_action = GtkCallbackAction::new(static function () use ($default): bool {
            g_signal_emit_by_name($default, 'clicked');

            return true;
        });
        $controller = GtkShortcutController::new();
        $controller->setPropagationPhase(GtkPropagationPhase::CAPTURE);
        $controller->addShortcut(GtkShortcut::new(GtkShortcutTrigger::parseString('Escape'), $this->escape_action));
        $controller->addShortcut(GtkShortcut::new(GtkShortcutTrigger::parseString('Return|KP_Enter'), $return_action));
        $this->window->addController($controller);

        // The window manager's close answers the cancel index; true keeps GTK from destroying it before the answer does.
        g_signal_connect($this->window, 'close-request', static fn (): bool => $self->get()?->answer($cancel) ?? false);
        $this->window->present();
        $this->buttons[$alert->default]->grabFocus();
    }

    /** The action of the Esc shortcut, for tests: activating it is the real path of the key. */
    public function escapeAction(): GtkShortcutAction
    {
        return $this->escape_action;
    }

    /** The parent closed: destroy the window with no answer. */
    public function dismiss(): void
    {
        $this->done = true;
        $this->window->destroy();
    }

    private function answer(int $button): bool
    {
        if ($this->done) {
            return true;
        }
        $this->done = true;
        $suppressed = $this->check?->getActive() ?? false;
        $this->window->destroy();
        ($this->answered)($button, $suppressed);

        return true;
    }
}

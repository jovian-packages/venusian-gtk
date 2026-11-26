<?php

namespace Jovian\Toolkits\GTK;

use GAsyncResult;
use GCancellable;
use GError;
use GMainContext;
use GtkAlertDialog;
use GtkApplicationWindow;
use GtkUriLauncher;
use GtkBox;
use GtkWidget;
use GtkOrientation;
use GtkWindowGroup;
use RuntimeException;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Toolkits\ToolkitWindow;
use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\UI\TKColumn as ColumnContract;
use WeakReference;

class GtkWindow extends ToolkitWindow
{
    /** The surface scale last posted, or read at realize. */
    private float $scale = 1.0;

    /**
     * The content area's size at the last settle. A zero side (not yet allocated) is recorded
     * without a report, so the first allocation is not a resize.
     *
     * @var array{int, int}
     */
    private array $last_size = [0, 0];

    /**
     * The window was inactive at the end of a pump. On X11 a popover's grab makes the window
     * inactive and active again in one dispatch; that is no focus change and posts nothing.
     */
    protected bool $seen_inactive = true;

    /**
     * The native window while open; null once closed.
     */
    protected ?GtkApplicationWindow $window = null;

    /**
     * A top-level window's own group: a modal child's grab then blocks only its parent, not every
     * window of the app (GTK puts all windows in one default group).
     */
    protected ?GtkWindowGroup $group = null;

    /**
     * Vertical box filling the window: the menu bar (Linux) above the content.
     */
    protected ?GtkBox $scaffold = null;

    /**
     * Where the content container goes: the area below the bar, expanding both ways.
     */
    protected ?GtkBox $content = null;

    /** Cancels the GtkAlertDialog open on the window, until it is answered. */
    protected ?GCancellable $alert = null;

    public function __construct(
        string $name,
        GtkSession $session,
        GtkAdapterDriver $driver,
        int $width,
        int $height,
        ?GtkWindow $parent = null,
    ) {
        parent::__construct($name, $session, $driver, $width, $height, $parent);
    }

    /** A child presents its parent first when the parent is not shown. */
    public function present(): static
    {
        $window = $this->live();
        if ($this->parent instanceof self && ! $this->parent->native()->getVisible()) {
            $this->parent->present();
        }
        $window->present();
        $this->focusInitial();
        $this->session->application()->bringForward();

        return $this;
    }

    public function setTitle(string $title): static
    {
        $this->live()->setTitle($title);

        return $this;
    }

    /**
     * The native window, for engines that draw into it.
     * @throws RuntimeException Once closed.
     */
    public function native(): GtkApplicationWindow
    {
        return $this->live();
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * The area below the menu bar, where the content container goes.
     */
    public function contentArea(): GtkBox
    {
        $this->live();

        return $this->content;
    }

    /**
     * A bar inside the window, above the content (Linux).
     */
    public function attachMenuBar(GtkWidget $bar): void
    {
        $this->live();
        $this->scaffold->prepend($bar);
    }

    public function detachMenuBar(GtkWidget $bar): void
    {
        if (! is_null($this->scaffold) && $bar->getParent() === $this->scaffold) {
            $this->scaffold->remove($bar);
        }
    }

    /**
     * The content area's allocation: the window below its menu bar.
     * @return array{int, int}
     * @throws RuntimeException Once closed.
     */
    public function size(): array
    {
        $this->live();

        return [$this->content->getWidth(), $this->content->getHeight()];
    }

    /**
     * Whether this window is the active one.
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActive();
    }

    /**
     * End of a pump. GTK 4 has no resize signal, so the allocation is compared here; and an
     * inactive window's next activation posts WindowFocused.
     */
    public function settle(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $size = $this->size();
        if ($size !== $this->last_size) {
            $last = $this->last_size;
            $this->last_size = $size;
            if (! in_array(0, $last, true) && ! in_array(0, $size, true)) {
                $this->resized($size[0], $size[1]);
            }
        }

        if (! $this->isActive()) {
            $this->seen_inactive = true;
        }
    }

    /** The scale changed (another display, a display setting): WindowScaleChanged when it differs from the last posted. */
    public function scaleChanged(): void
    {
        $window = $this->window;
        if (is_null($window)) {
            return;
        }
        $scale = $window->getSurface()?->getScale() ?? (float) $window->getScaleFactor();
        if ($scale === $this->scale) {
            return;
        }
        $this->scale = $scale;
        $this->session->post(new WindowScaleChanged($this->name, $scale));
    }

    protected function bootstrapWindow(int $width, int $height): void
    {
        /** @var GtkSession $session */
        $session = $this->session;

        $this->window = GtkApplicationWindow::new($session->application()->get());
        $this->window->setTitle($this->name);
        $this->window->setDefaultSize($width, $height);
        if ($this->parent instanceof self) {
            $this->window->setTransientFor($this->parent->native());
            $this->window->setModal(true);
            $this->parent->native()->getGroup()->addWindow($this->window);
        } else {
            $this->group = GtkWindowGroup::new();
            $this->group->addWindow($this->window);
        }

        $this->scaffold = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content->setHexpand(true);
        $this->content->setVexpand(true);
        $this->scaffold->append($this->content);
        $this->window->setChild($this->scaffold);

        // The handlers hold this window weakly: a strong capture would be a cycle through the
        // GObject's closures that PHP's collector cannot see, keeping every closed window alive.
        $self = WeakReference::create($this);

        // false lets GTK destroy the window, true keeps it.
        g_signal_connect($this->window, 'close-request', static fn (): bool => $self->get()?->closeRequested() ?? false);
        g_signal_connect($this->window, 'notify::is-active', static fn () => $self->get()?->activeChanged());

        // The integer factor changes on the window, a fractional scale on its surface, which exists once realized.
        $this->scale = (float) $this->window->getScaleFactor();
        g_signal_connect($this->window, 'notify::scale-factor', static fn () => $self->get()?->scaleChanged());
        g_signal_connect($this->window, 'realize', static fn () => $self->get()?->realized());

        // Not ported yet (deprecated/Windows): the right-click, group-click,
        // canvas click/pointer, button-key and window-key controllers, and GTKFocus.
    }

    protected function realized(): void
    {
        $surface = $this->window?->getSurface();
        if (is_null($surface)) {
            return;
        }
        $this->scale = $surface->getScale();
        $self = WeakReference::create($this);
        g_signal_connect($surface, 'notify::scale', static fn () => $self->get()?->scaleChanged());
    }

    protected function activeChanged(): void
    {
        if (! $this->isActive()) {
            $this->lostFocus();

            return;
        }

        // On macOS GTK shows one menubar for the application: the active window's.
        $this->gainedFocus();

        if ($this->seen_inactive) {
            $this->seen_inactive = false;
            $this->session->post(new WindowFocused($this->name));
        }
    }

    /**
     * The window's close-request: true keeps the window, false lets GTK destroy it.
     */
    protected function closeRequested(): bool
    {
        // Not ported yet: a parent with an open child or dialog keeps the user's request silently.
        if (! $this->allowsUserClose()) {
            return true;
        }
        $this->closed();

        return false;
    }

    /**
     * The content container fills the content area.
     */
    protected function mountContent(ColumnContract $content): void
    {
        $native = $content->native();
        $native->setHexpand(true);
        $native->setVexpand(true);
        $this->content->append($native);
    }

    protected function unmountContent(ColumnContract $content): void
    {
        $this->content->remove($content->native());
    }

    /**
     * A realized window closes like the window manager's close button: close-request runs
     * inside close() and takes the close path. GTK skips close-request for a window never
     * realized, so that one takes the close path here and is destroyed.
     */
    protected function closeNative(): void
    {
        $window = $this->live();
        if ($window->getRealized()) {
            $window->close();

            return;
        }

        $this->closed();
        $window->destroy();
    }

    /**
     * A GtkAlertDialog, modal to the window, chosen without waiting. Its default and cancel
     * buttons take Return and Esc; Esc and the dialog's own close answer the cancel button.
     * GtkAlertDialog has no style, no checkbox and no title: those parts are left out.
     */
    protected function showAlert(Alert $alert): void
    {
        // gtk_alert_dialog_new() is a printf and is not bound: the message is a property, never a format.
        /** @var GtkAlertDialog $dialog */
        $dialog = g_object_new_with_properties('GtkAlertDialog', ['message'], [$alert->message]);
        if (! is_null($alert->detail)) {
            $dialog->setDetail($alert->detail);
        }
        // Its buttons read one _ as a mnemonic marker, two as an _.
        $dialog->setButtons(array_map(static fn (string $label): string => str_replace('_', '__', $label), $alert->buttons));
        $dialog->setDefaultButton($alert->default);
        $dialog->setCancelButton($alert->cancel);
        $dialog->setModal(true);

        $cancellable = GCancellable::new();
        $self = WeakReference::create($this);
        $cancel = $alert->cancel;
        $this->alert = $cancellable;
        $dialog->choose($this->live(), $cancellable, static function (GtkAlertDialog $dialog, GAsyncResult $result) use ($self, $cancellable, $cancel): void {
            try {
                $button = $dialog->chooseFinish($result);
            } catch (GError) {
                $button = $cancel;
            }
            $window = $self->get();
            if (is_null($window) || $window->alert !== $cancellable) {
                return;
            }
            $window->alert = null;
            $window->alertAnswered($button);
        });
    }

    /**
     * The dialog is cancelled, and its choose finishes, before the window goes; its answer is
     * not posted then. Cancelled outside a GLib dispatch (topside's loop is not GLib's), GTK
     * finishes the choose in an idle, and only then destroys the dialog's window: by then
     * destroyed and freed with its parent. The pending dispatch runs that idle while the
     * parent still lives.
     */
    protected function closeAlert(): void
    {
        $cancellable = $this->alert;
        if (is_null($cancellable)) {
            return;
        }

        $cancellable->cancel();
        $context = GMainContext::default();
        while ($this->alert === $cancellable && $context->pending()) {
            $context->iteration(false);
        }
        $this->alert = null;
    }

    /** GtkUriLauncher opens it with the user's browser, the window its parent. */
    protected function openUrl(string $url): void
    {
        GtkUriLauncher::new($url)->launch($this->live(), null, null);
    }

    /** GTK keeps a destroyed default widget as the default: cleared before the content goes. */
    protected function beforeClose(): void
    {
        $this->window->setDefaultWidget(null);
    }

    protected function releaseNative(): void
    {
        $this->window = null;
    }

    protected function live(): GtkApplicationWindow
    {
        return $this->window ?? throw new RuntimeException("GTK Window '{$this->name}' is closed.");
    }
}

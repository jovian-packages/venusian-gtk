<?php

namespace Jovian\Toolkits\GTK;

use GtkApplicationWindow;
use GtkBox;
use GtkWidget;
use GtkOrientation;
use GtkWindowGroup;
use RuntimeException;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Toolkits\ToolkitWindow;
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

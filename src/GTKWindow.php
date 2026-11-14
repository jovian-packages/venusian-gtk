<?php

namespace Jovian\Toolkits\GTK;

use Closure;
use GtkApplicationWindow;
use GtkBox;
use GtkOrientation;
use GtkWidget;
use GtkWindowGroup;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\GTKPrimitiveFactory;
use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use Surface\Contracts\Toolkits\Mail\Windows\WindowClosed;
use Surface\Contracts\Toolkits\Mail\Windows\WindowCloseRequested;
use Surface\Contracts\Toolkits\Mail\Windows\WindowFocused;
use Surface\Contracts\Toolkits\Mail\Windows\WindowScaleChanged;
use Surface\Contracts\Toolkits\Primitives\PrimitiveFactory;
use Surface\Contracts\Toolkits\Primitives\TKPrimitive;
use Surface\Contracts\Toolkits\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Toolkits\ToolkitRuntimeException;
use Surface\Contracts\Toolkits\ToolkitWindow as WindowContract;
use Surface\Toolkits\Menus\MenuProfile;
use Surface\Toolkits\Windows\ChildWindows;
use Surface\Toolkits\Windows\ToolkitWindow;
use WeakReference;

class GTKWindow extends ToolkitWindow
{
    protected ?PrimitiveFactory $factory = null;

    /**
     * The native window while open; null once closed.
     */
    protected ?GtkApplicationWindow $window;

    /** The surface scale last posted, or read at realize. */
    private float $scale = 1.0;

    /**
     * Vertical box filling the window: the menu bar (Linux) above the content.
     */
    protected GtkBox $scaffold;

    /**
     * Where views go: the area below the bar, expanding both ways.
     */
    protected GtkBox $content;

    /** The window's own menu bar; null when it has none. */
    protected ?GTKMenuBar $menu = null;

    /** Linux: the bar's widget, the scaffold's first child. */
    protected ?GtkWidget $bar_widget = null;

    /** @var WeakReference<TKPrimitive>|null focused on each present(); weak, so a removed primitive is freed */
    protected ?WeakReference $initial_focus = null;

    /**
     * A top-level window's own group: a modal child's grab then blocks only its parent, not every
     * window of the app (GTK puts all windows in one default group).
     */
    protected ?GtkWindowGroup $group = null;

    protected bool $confirming = false;

    /** close() runs: its own close-request goes through while the window confirms close. */
    protected bool $closing = false;

    /**
     * The window was inactive at the end of a pump. On X11 a popover's grab makes the window
     * inactive and active again in one dispatch; that is no focus change and posts nothing.
     */
    protected bool $seen_inactive = true;

    public function __construct(
        protected readonly string $name,
        protected readonly GTKSession $session,
        protected readonly GTKBridge $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
        protected readonly ?WindowContract $parent = null,
    ) {
        $this->window = GtkApplicationWindow::new($session->application());
        $this->window->setTitle($name);
        $this->window->setDefaultSize($width, $height);
        if ($parent instanceof self) {
            $this->window->setTransientFor($parent->native());
            $this->window->setModal(true);
            $parent->native()->getGroup()->addWindow($this->window);
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

        // false lets GTK destroy the window; the mail goes first. While the window confirms close,
        // the user's request posts WindowCloseRequested and true keeps the window; close() goes through.
        // A parent with an open child or dialog keeps the user's request silently: the grab that
        // blocks it exists only once the child shows.
        g_signal_connect($this->window, 'close-request', function (): bool {
            if (! $this->closing && (! is_null(ChildWindows::childOf($this->driver, $this)) || ! is_null($this->driver->dialogs()->openOn($this)))) {
                return true;
            }
            if ($this->confirming && ! $this->closing) {
                $this->session->post(new WindowCloseRequested($this->name));

                return true;
            }
            $this->closed();

            return false;
        });
        g_signal_connect($this->window, 'notify::is-active', fn () => $this->activeChanged());
        // The integer factor changes on the window, a fractional scale on its surface, which exists once realized.
        $this->scale = (float) $this->window->getScaleFactor();
        g_signal_connect($this->window, 'notify::scale-factor', fn () => $this->scaleChanged());
        g_signal_connect($this->window, 'realize', function (): void {
            $surface = $this->window?->getSurface();
            if (is_null($surface)) {
                return;
            }
            $this->scale = $surface->getScale();
            g_signal_connect($surface, 'notify::scale', fn () => $this->scaleChanged());
        });

        // Not ported yet (deprecated/Windows): the right-click, group-click, canvas click/pointer,
        // button-key and window-key controllers, and GTKFocus.
        if (! is_null($menu)) {
            $this->useMenu(new GTKMenuBar($session, $menu, $name, $driver->about(), $this->window));
        }

        $session->watchWindow($this);
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

    public function name(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return $this->live()->getTitle() ?? '';
    }

    public function setTitle(string $title): static
    {
        $this->live()->setTitle($title);

        return $this;
    }

    /** A child presents its parent first when the parent is not shown. */
    public function present(): static
    {
        $window = $this->live();
        if ($this->parent instanceof self && ! $this->parent->native()->getVisible()) {
            $this->parent->present();
        }
        $initial = $this->initial_focus?->get();
        $this->quietly(static function () use ($window, $initial): void {
            $window->present();
            if (! is_null($initial) && ! $initial->isRemoved() && method_exists($initial, 'focusWidget')) {
                $initial->focusWidget()->grabFocus();
            }
        });
        $this->session->bringForward();

        return $this;
    }

    /** Null until GTKFocus is ported. */
    public function focused(): ?TKPrimitive
    {
        return null;
    }

    /**
     * @throws ToolkitRuntimeException When $primitive belongs to another window.
     */
    public function setInitialFocus(?TKPrimitive $primitive): static
    {
        if (! is_null($primitive) && $primitive->window() !== $this) {
            throw new ToolkitRuntimeException("'{$primitive->path()}' is not in window '{$this->name}'.");
        }
        $this->initial_focus = is_null($primitive) ? null : WeakReference::create($primitive);

        return $this;
    }

    /**
     * Run a focus move that code makes. With a focus tracker it posts no mail for the move;
     * GTKFocus is not ported yet, so the move runs as it is.
     */
    public function quietly(Closure $move): void
    {
        $move();
    }

    /** The window's focus as primitives. Null until GTKFocus is ported. */
    public function focusTracker(): null
    {
        return null;
    }

    public function parent(): ?WindowContract
    {
        return $this->parent;
    }

    public function confirmClose(bool $on = true): static
    {
        $this->confirming = $on;

        return $this;
    }

    public function isConfirmingClose(): bool
    {
        return $this->confirming;
    }

    /**
     * The user's close from the keyboard (Esc in a child with no cancel button): the same path as
     * the window manager's close, through close-request.
     */
    public function userClose(): void
    {
        $this->window?->close();
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is the active one.
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActive();
    }

    /**
     * A realized window closes like the window manager's close button: close-request runs
     * inside close() and does the mail and bookkeeping. GTK skips close-request for a window
     * never realized, so that one is destroyed here and takes the same path.
     */
    public function close(): void
    {
        if (is_null($this->window)) {
            return;
        }
        ChildWindows::childOf($this->driver, $this)?->close();
        $this->driver->dialogs()->closeOn($this);

        if ($this->window->getRealized()) {
            $this->closing = true;
            $this->window->close();
            $this->closing = false;

            return;
        }

        $window = $this->window;
        $this->closed();
        $window->destroy();
    }

    /**
     * @throws ToolkitRuntimeException When the window is a child, or closed.
     */
    public function setMenuBar(string $profile): static
    {
        ChildWindows::refuseMenuBar($this);
        $this->useMenu(new GTKMenuBar($this->session, $this->driver->profile($profile), $this->name, $this->driver->about(), $this->live()));

        return $this;
    }

    public function setToggle(string $item, bool $on): static
    {
        $this->menuOrFail()->setToggle($item, $on);

        return $this;
    }

    public function isToggled(string $item): bool
    {
        return $this->menuOrFail()->isToggled($item);
    }

    public function setRadio(string $group, string $item): static
    {
        $this->menuOrFail()->setRadio($group, $item);

        return $this;
    }

    public function radio(string $group): string
    {
        return $this->menuOrFail()->radio($group);
    }

    public function setItemLabel(string $item, string $label): static
    {
        $this->menuOrFail()->setItemLabel($item, $label);

        return $this;
    }

    public function itemLabel(string $item): string
    {
        return $this->menuOrFail()->itemLabel($item);
    }

    public function setItemEnabled(string $item, bool $enabled): static
    {
        $this->menuOrFail()->setItemEnabled($item, $enabled);

        return $this;
    }

    public function isItemEnabled(string $item): bool
    {
        return $this->menuOrFail()->isItemEnabled($item);
    }

    /**
     * The native window, for engines that draw into it.
     * @throws ToolkitRuntimeException Once closed.
     */
    public function native(): GtkApplicationWindow
    {
        return $this->live();
    }

    /**
     * The area below the menu bar, where the content container goes.
     */
    public function contentArea(): GtkBox
    {
        return $this->content;
    }

    /**
     * The session, for the primitives this window hosts.
     */
    public function session(): GTKSession
    {
        return $this->session;
    }

    /**
     * This window's minter, one per window. Typed as the contract so the window loads without
     * loading every primitive class.
     */
    public function factory(): PrimitiveFactory
    {
        return $this->factory ??= new GTKPrimitiveFactory($this);
    }

    /**
     * The content area's allocation: the window below its menu bar.
     * @return array{int, int}
     * @throws ToolkitRuntimeException Once closed.
     */
    public function size(): array
    {
        $this->live();

        return [$this->content->getWidth(), $this->content->getHeight()];
    }

    /**
     * Take the content container's widget out of the content area. Called from its removal.
     */
    public function unmountContent(GtkWidget $native): void
    {
        $this->content->remove($native);
    }

    /**
     * The content container fills the content area.
     *
     * @throws ToolkitRuntimeException When the content is not a GTK primitive.
     */
    protected function mountContent(TKPrimitiveGroup $content): void
    {
        if (! $content instanceof GTKView) {
            throw new ToolkitRuntimeException("'{$content->path()}' is a ".get_debug_type($content).', not a GTK primitive.');
        }
        $native = $content->native();
        $native->setHexpand(true);
        $native->setVexpand(true);
        $this->content->append($native);
    }

    protected function activeChanged(): void
    {
        if (! $this->isActive()) {
            return;
        }

        // macOS: the active window's own bar goes to the top of the screen. A window with no bar
        // leaves the bar as it is; what shows then is undecided (it was the driver's default bar).
        if (GTKBridge::appWideBar() && ! is_null($this->menu)) {
            $this->driver->showWindowMenuBar($this->menu);
        }

        if ($this->seen_inactive) {
            $this->seen_inactive = false;
            $this->session->post(new WindowFocused($this->name));
        }
    }

    /** End of a pump: an inactive window's next activation posts WindowFocused. */
    public function settleActive(): void
    {
        if (! $this->isActive()) {
            $this->seen_inactive = true;
        }
    }

    /**
     * The one close path, while the native window still lives: remove the primitive tree,
     * drop a pending resize, post WindowClosed, let the driver forget the window.
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        // GTK keeps a destroyed default widget as the default: clear it before the content goes.
        $this->window->setDefaultWidget(null);
        $this->removeContent();
        if (! is_null($this->menu)) {
            $this->menu->setAccelerators(false);
            $this->driver->releaseWindowMenuBar($this->menu);
        }
        $this->session->unwatchWindow($this);
        $this->window = null;
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): GtkApplicationWindow
    {
        return $this->window ?? throw new ToolkitRuntimeException("Window '{$this->name}' is closed.");
    }

    public function menuBar(): ?GTKMenuBar
    {
        return $this->menu;
    }

    /**
     * Put a bar where the OS keeps menus: inside the window on Linux, the app's bar on macOS
     * (while this window is active). On macOS the bar this window had leaves the app's bar.
     */
    protected function useMenu(GTKMenuBar $menu): void
    {
        $window = $this->live();
        if (! is_null($this->menu)) {
            $this->menu->setAccelerators(false);
            $this->driver->releaseWindowMenuBar($this->menu);
        }
        $this->menu = $menu;
        $window->insertActionGroup('win', $menu->actions());
        $menu->setAccelerators(true);

        if (GTKBridge::appWideBar()) {
            $window->setShowMenubar(true);
            if ($this->isActive()) {
                $this->driver->showWindowMenuBar($menu);
            }

            return;
        }

        if (! is_null($this->bar_widget)) {
            $this->scaffold->remove($this->bar_widget);
        }
        $this->bar_widget = $menu->widget();
        $this->scaffold->prepend($this->bar_widget);
    }

    protected function menuOrFail(): GTKMenuBar
    {
        return $this->menu ?? throw new ToolkitRuntimeException("Window '{$this->name}' has no menu bar.");
    }
}

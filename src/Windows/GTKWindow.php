<?php

namespace Jovian\Toolkits\GTK\Windows;

use GtkApplicationWindow;
use GtkBox;
use GtkOrientation;
use GtkWidget;
use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\GTKPrimitiveFactory;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\Primitives\TKPrimitiveGroup;
use Surface\Contracts\Windows\ToolkitWindow;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuProfile;
use Surface\Windows\Primitives\HostsPrimitives;

class GTKWindow implements ToolkitWindow
{
    use HostsPrimitives;

    protected ?GTKPrimitiveFactory $factory = null;

    /**
     * The native window while open; null once closed.
     * @var GtkApplicationWindow|null
     */
    protected ?GtkApplicationWindow $window;

    /**
     * Vertical box filling the window: the menu bar (Linux) above the content.
     * @var GtkBox
     */
    protected GtkBox $scaffold;

    /**
     * Where views go: the area below the bar, expanding both ways.
     * @var GtkBox
     */
    protected GtkBox $content;

    protected ?GTKMenuBar $menu = null;

    /**
     * The bar widget inside the window (Linux), removed when the bar is replaced.
     * @var GtkWidget|null
     */
    protected ?GtkWidget $bar_widget = null;

    public function __construct(
        protected readonly string $name,
        protected readonly GTKSession $session,
        protected readonly GTKBridgeDriver $driver,
        int $width,
        int $height,
        ?MenuProfile $menu,
    ) {
        $this->window = GtkApplicationWindow::new($session->application());
        $this->window->setTitle($name);
        $this->window->setDefaultSize($width, $height);

        $this->scaffold = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content->setHexpand(true);
        $this->content->setVexpand(true);
        $this->scaffold->append($this->content);
        $this->window->setChild($this->scaffold);

        // false lets GTK destroy the window; the mail goes first.
        g_signal_connect($this->window, 'close-request', function (): bool {
            $this->closed();

            return false;
        });
        g_signal_connect($this->window, 'notify::is-active', fn () => $this->activeChanged());
        $session->watchWindow($this);

        if (! is_null($menu)) {
            $this->useMenu(new GTKMenuBar($session, $menu, $name, $driver->about(), $this->window));
        }
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

    public function present(): static
    {
        $this->live()->present();
        $this->session->bringForward();

        return $this;
    }

    public function isOpen(): bool
    {
        return ! is_null($this->window);
    }

    /**
     * Whether this window is the active one, and so (macOS) whose bar the app shows.
     * @return bool
     */
    public function isActive(): bool
    {
        return ! is_null($this->window) && $this->window->isActive();
    }

    /**
     * A realized window closes like the window manager's close button: close-request runs
     * inside close() and does the mail and bookkeeping. GTK skips close-request for a window
     * never realized, so that one is destroyed here and takes the same path.
     * @return void
     */
    public function close(): void
    {
        if (is_null($this->window)) {
            return;
        }

        if ($this->window->getRealized()) {
            $this->window->close();
            return;
        }

        $window = $this->window;
        $this->closed();
        $window->destroy();
    }

    public function setMenuBar(string $profile): static
    {
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

    /**
     * The native window, for engines that draw into it.
     * @return GtkApplicationWindow
     * @throws WindowException Once closed.
     */
    public function native(): GtkApplicationWindow
    {
        return $this->live();
    }

    /**
     * The area below the menu bar, where the content container goes.
     * @return GtkBox
     */
    public function contentArea(): GtkBox
    {
        return $this->content;
    }

    /**
     * The session, for the primitives this window hosts.
     * @return GTKSession
     */
    public function session(): GTKSession
    {
        return $this->session;
    }

    public function factory(): GTKPrimitiveFactory
    {
        return $this->factory ??= new GTKPrimitiveFactory($this);
    }

    /**
     * The content area's allocation: the window below its menu bar.
     * @return array{int, int}
     * @throws WindowException Once closed.
     */
    public function size(): array
    {
        $this->live();

        return [$this->content->getWidth(), $this->content->getHeight()];
    }

    /**
     * Take the content container's widget out of the content area. Called from its removal.
     *
     * @param GtkWidget $native
     * @return void
     */
    public function unmountContent(GtkWidget $native): void
    {
        $this->content->remove($native);
    }

    /**
     * The content container fills the content area.
     *
     * @param TKPrimitiveGroup $content
     * @return void
     * @throws WindowException When the content is not a GTK primitive.
     */
    protected function mountContent(TKPrimitiveGroup $content): void
    {
        if (! $content instanceof GTKView) {
            throw new WindowException("'{$content->path()}' is a ".get_debug_type($content).', not a GTK primitive.');
        }
        $native = $content->native();
        $native->setHexpand(true);
        $native->setVexpand(true);
        $this->content->append($native);
    }

    public function menuBar(): ?GTKMenuBar
    {
        return $this->menu;
    }

    /**
     * Put a bar where the OS keeps menus: inside the window on Linux, the app's bar on macOS
     * (while this window is active). Its actions live in the window's "win" group either way.
     * @param GTKMenuBar $menu
     * @return void
     */
    protected function useMenu(GTKMenuBar $menu): void
    {
        $window = $this->live();
        $this->menu = $menu;
        $window->insertActionGroup('win', $menu->actions());
        $menu->setAccelerators(true);

        if (GTKBridgeDriver::appWideBar()) {
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

    protected function activeChanged(): void
    {
        if (! $this->isActive()) {
            $this->driver->showDefaultMenuBar();
            return;
        }

        if (GTKBridgeDriver::appWideBar()) {
            if (is_null($this->menu)) {
                $this->driver->installDefaultMenuBar();
            } else {
                $this->driver->showWindowMenuBar($this->menu);
            }
        }

        $this->session->post(new WindowFocused($this->name));
    }

    /**
     * The one close path, while the native window still lives: remove the primitive tree,
     * drop a pending resize, post WindowClosed, let the driver forget the window.
     * @return void
     */
    protected function closed(): void
    {
        if (is_null($this->window)) {
            return;
        }

        $this->removeContent();
        $this->session->unwatchWindow($this);
        $this->window = null;
        $this->session->post(new WindowClosed($this->name));
        $this->driver->forget($this->name);
    }

    protected function live(): GtkApplicationWindow
    {
        return $this->window ?? throw new WindowException("Window '{$this->name}' is closed.");
    }

    protected function menuOrFail(): GTKMenuBar
    {
        return $this->menu ?? throw new WindowException("Window '{$this->name}' has no menu bar.");
    }
}

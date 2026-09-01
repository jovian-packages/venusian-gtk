<?php

namespace Jovian\Venusian\GTK\Windows;

use Jovian\Bindings\Gtk\Enums\GtkOrientation;
use Jovian\Bindings\Gtk\Gio\GMenu;
use Jovian\Bindings\Gtk\Gio\GSimpleAction;
use Jovian\Bindings\Gtk\Gio\GSimpleActionGroup;
use Jovian\Bindings\Gtk\Gtk\GtkAboutDialog;
use Jovian\Bindings\Gtk\Gtk\GtkBox;
use Jovian\Bindings\Gtk\Gtk\GtkButton as GtkButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkLabel as GtkLabelWidget;
use Jovian\Bindings\Gtk\Gtk\GtkPopoverMenuBar;
use Jovian\Bindings\Gtk\Gtk\GtkWindow;
use Surface\Contracts\Core\AboutInfo;
use Surface\Contracts\NativeWindows\LinuxOSWindow;
use Surface\NativeWindows\Enums\MenuRole;
use Jovian\Venusian\GTK\Styles\CssEngine;
use Jovian\Bindings\Gtk\Enums\GtkContentFit;
use Jovian\Bindings\Gtk\Gtk\GtkPicture;
use Jovian\Bindings\Gtk\Gtk\GtkSpinner as GtkSpinnerWidget;
use Jovian\Bindings\Gtk\Gtk\GtkVideo as GtkVideoWidget;
use Jovian\Venusian\GTK\Views\GTKButton;
use Jovian\Venusian\GTK\Views\GTKImage;
use Jovian\Venusian\GTK\Views\GTKLabel;
use Jovian\Venusian\GTK\Views\GTKSpinner;
use Jovian\Venusian\GTK\Views\GTKVideo;
use Surface\NativeWindows\Menus\MenuItemSpec;
use Surface\NativeWindows\Views\Button;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Views\Label;
use Surface\NativeWindows\Views\Spinner;
use Surface\NativeWindows\Views\Video;
use Surface\NativeWindows\Windowable;

class GTKWindowDelegate extends Windowable implements LinuxOSWindow
{
    protected bool $is_presenting = false;

    /**
     * Whether the native window is gone. GTK close DESTROYS the widget and
     * recycles the handle, so after this flips every native call on
     * $this->window is a touch on a dead handle and must be guarded.
     * @var bool
     */
    protected bool $closed = false;

    /**
     * The bar widget built from this window's elected profile, prepended
     * into the scaffold. Linux menus live inside the window, so unlike
     * macOS there is nothing process-global to swap.
     * @var GtkPopoverMenuBar|null
     */
    protected ?GtkPopoverMenuBar $menu_bar = null;

    /**
     * The window-scoped action group behind the bar's `win.*` names, held so
     * the actions stay reachable for later enable/disable work.
     * @var GSimpleActionGroup|null
     */
    protected ?GSimpleActionGroup $action_group = null;

    /**
     * This window's stylesheet — one provider on the display, one rule
     * block per styled view.
     * @var CssEngine
     */
    public readonly CssEngine $styles;

    public readonly GtkBox $scaffold;
    public readonly GtkFixed $content;

    public function __construct(
        string $name,
        public readonly GtkWindow $window,

    ) {
        parent::__construct($name);
        $this->styles = new CssEngine($name);
        // Linux menus are widgets inside the window, so the child is a scaffold
        // rather than the content root directly: a later chrome slice prepends
        // into this box instead of reparenting a live tree.
        $this->scaffold = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $this->content  = GtkFixed::new();
        // A box hands a child full width by default but only its NATURAL
        // height — for a GtkFixed that is the extent of its children, not the
        // window. Expand both ways so contentSize() is the area below the bar
        // and center() has a real height to divide.
        $this->content->setHexpand(true);
        $this->content->setVexpand(true);
        $this->scaffold->append($this->content);
        $this->window->setChild($this->scaffold);

        // false = let GTK destroy the window; the event is pushed first so
        // the sketch hears about it on its next drain.
        $this->window->onCloseRequest(function (mixed ...$args): bool {
            $this->emitWindowClosed();
            $this->closed = true;

            return false;
        });
    }

    public function setTitle(string $title): static
    {
        $this->window->setTitle($title);
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->window->getTitle();
    }

    public function destroy(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->window->destroy();
    }

    public function present(): void
    {
        if(!$this->isPresenting()) {
            $this->window->present();
        }
    }

    public function isPresenting(): bool
    {
        return ! $this->closed && $this->window->isVisible();
    }

    /**
     * A fresh GtkAboutDialog per showing, transient for this window. The
     * dialog is a toplevel GTK owns, so nothing is held here; closing it
     * destroys it.
     */
    protected function presentAbout(?AboutInfo $about): void
    {
        if ($this->closed) {
            return;
        }

        $dialog = GtkAboutDialog::new();
        $dialog->setTransientFor($this->window);
        $dialog->setModal(true);

        if (! is_null($about)) {
            $dialog->setProgramName($about->name);
            if (! is_null($about->version)) {
                $dialog->setVersion($about->version);
            }
            if (! is_null($about->copyright)) {
                $dialog->setCopyright($about->copyright);
            }
            if (! is_null($about->credits)) {
                $dialog->setComments($about->credits);
            }
            if (! is_null($about->website)) {
                $dialog->setWebsite($about->website);
            }
        }

        $dialog->present();
    }

    /**
     * The GtkFixed content's allocation. A REQUEST until the first layout —
     * GTK answers 0x0 before present() plus a pump, so center() before the
     * window is shown lands at the origin. Placement is honest, not early.
     */
    public function contentSize(): array
    {
        if ($this->closed) {
            return [0, 0];
        }

        return [$this->content->getWidth(), $this->content->getHeight()];
    }

    /**
     * Put a GtkLabel into the content at the origin; Windowable::label()
     * places it after.
     */
    protected function mintLabel(string $name, string $text): Label
    {
        $widget = GtkLabelWidget::new($text);
        $this->content->put($widget, 0.0, 0.0);

        return new GTKLabel($name, $this, $text, $widget, $this->content);
    }

    /**
     * Put a GtkButton into the content at the origin; the GTKButton wires
     * `clicked` itself. Placed by Windowable::button().
     */
    protected function mintButton(string $name, string $label): Button
    {
        $widget = GtkButtonWidget::newWithLabel($label);
        $this->content->put($widget, 0.0, 0.0);

        return new GTKButton($name, $this, $label, $widget, $this->content);
    }

    /**
     * A GtkSpinner in the content at the origin, stopped. Placed by
     * Windowable::spinner().
     */
    protected function mintSpinner(string $name): Spinner
    {
        $widget = GtkSpinnerWidget::new();
        $this->content->put($widget, 0.0, 0.0);

        return new GTKSpinner($name, $this, $widget, $this->content);
    }

    /**
     * A GtkPicture fitting its file inside the frame: CONTAIN keeps the
     * aspect ratio, can-shrink keeps a big picture from flooring measure()
     * at its full size. Placed by Windowable::image().
     */
    protected function mintImage(string $name, ?string $path): Image
    {
        $widget = is_null($path) ? GtkPicture::new() : GtkPicture::newForFilename($path);
        $widget->setContentFit(GtkContentFit::CONTAIN);
        $widget->setCanShrink(true);
        $this->content->put($widget, 0.0, 0.0);

        return new GTKImage($name, $this, $path, $widget, $this->content);
    }

    /**
     * A GtkVideo in the content at the origin; the GTKVideo mints a
     * GtkMediaFile per path itself. Placed by Windowable::video().
     */
    protected function mintVideo(string $name, ?string $path): Video
    {
        $widget = GtkVideoWidget::new();
        $this->content->put($widget, 0.0, 0.0);

        $video = new GTKVideo($name, $this, null, $widget, $this->content);
        if (! is_null($path)) {
            $video->setPath($path);
        }

        return $video;
    }

    /**
     * Build the elected profile into a GMenu model and prepend the bar
     * widget into this window's scaffold — exactly the slot the scaffold
     * exists for.
     *
     * Activation: every hook or PHP-backed role registers a GSimpleAction
     * under the item's spec id in a window-scoped group inserted as `win`,
     * so the model's `win.<id>` references resolve. Action names may contain
     * dots — only a detailed name's first dot splits off the group prefix.
     *
     * @param list<MenuItemSpec> $spec
     * @return void
     */
    protected function applyMenuBar(array $spec): void
    {
        $model = GMenu::new();

        foreach ($spec as $folder) {
            $model->appendSubmenu($folder->label, $this->buildFolderModel($folder));
        }

        $group = GSimpleActionGroup::new();
        foreach ($spec as $folder) {
            $this->registerActions($group, $folder);
        }
        $this->window->insertActionGroup('win', $group->handle);
        $this->action_group = $group;

        $bar = GtkPopoverMenuBar::newFromModel($model);
        $this->scaffold->prepend($bar);
        $this->menu_bar = $bar;
    }

    /**
     * Register a GSimpleAction for every node that needs PHP behind it,
     * recursing through nested folders.
     *
     * Event items push their name into the queue — nothing user-authored
     * runs inside the pump. QUIT is the one PHP-backed role: GTK has no
     * application-quit action, so its honest equivalent is destroying this
     * window.
     *
     * @param GSimpleActionGroup $group
     * @param MenuItemSpec $folder
     * @return void
     */
    protected function registerActions(GSimpleActionGroup $group, MenuItemSpec $folder): void
    {
        foreach ($folder->items as $item) {
            if ($item->separator) {
                continue;
            }

            if ($item->isFolder()) {
                $this->registerActions($group, $item);
                continue;
            }

            if (! is_null($item->event)) {
                $action = GSimpleAction::new($item->id);
                $action->onActivate(fn (mixed ...$args) => $this->emitMenuEvent($item));
                $group->addAction($action);
                continue;
            }

            if ($item->role === MenuRole::QUIT) {
                $action = GSimpleAction::new($item->id);
                $action->onActivate(function (mixed ...$args): void {
                    $this->emitWindowClosed();
                    $this->destroy();
                });
                $group->addAction($action);
                continue;
            }

            if ($item->role === MenuRole::ABOUT) {
                $action = GSimpleAction::new($item->id);
                $action->onActivate(fn (mixed ...$args) => $this->showAbout());
                $group->addAction($action);
            }
        }
    }

    /**
     * Build one folder's GMenu, recursing through nested folders.
     *
     * Separators are skipped this slice: GMenu draws separators from section
     * boundaries, not items, and sectioning comes with the activation wiring.
     *
     * @param MenuItemSpec $folder
     * @return GMenu
     */
    protected function buildFolderModel(MenuItemSpec $folder): GMenu
    {
        $menu = GMenu::new();

        foreach ($folder->items as $item) {
            if ($item->separator) {
                continue;
            }

            if ($item->isFolder()) {
                $menu->appendSubmenu($item->label, $this->buildFolderModel($item));
                continue;
            }

            $menu->append($item->label, $this->actionNameFor($item));
        }

        return $menu;
    }

    /**
     * GTK's half of the role table, as window-scoped action names.
     *
     * CLOSE_WINDOW and MINIMIZE resolve to GTK's built-in window actions.
     * QUIT, ABOUT and event items resolve to `win.<id>` actions this
     * delegate registers. Roles with no honest GTK equivalent (HIDE,
     * FULLSCREEN — built-ins stop at close/minimize/toggle-maximized, and
     * maximize is not fullscreen) fall through to `win.<id>` with nothing
     * registered, so GTK renders them insensitive rather than faking it.
     *
     * @param MenuItemSpec $item
     * @return string
     */
    protected function actionNameFor(MenuItemSpec $item): string
    {
        return match ($item->role) {
            MenuRole::CLOSE_WINDOW => 'window.close',
            MenuRole::MINIMIZE => 'window.minimize',
            default => "win.{$item->id}",
        };
    }
}

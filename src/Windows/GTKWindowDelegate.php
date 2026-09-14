<?php

namespace Jovian\Venusian\GTK\Windows;

use Jovian\Bindings\Gtk\Enums\GdkGLAPI;
use Jovian\Bindings\Gtk\Enums\GtkOrientation;
use Jovian\Bindings\Gtk\Gio\GMenu;
use Jovian\Bindings\Gtk\Gio\GSimpleAction;
use Jovian\Bindings\Gtk\Gio\GSimpleActionGroup;
use Jovian\Bindings\Gtk\Gtk\GtkAboutDialog;
use Jovian\Bindings\Gtk\Gtk\GtkBox;
use Jovian\Bindings\Gtk\Gtk\GtkButton as GtkButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkCalendar as GtkCalendarWidget;
use Jovian\Bindings\Gtk\Gtk\GtkColumnView;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkGLArea;
use Jovian\Bindings\Gtk\Gtk\GtkLabel as GtkLabelWidget;
use Jovian\Bindings\Gtk\Gtk\GtkPopoverMenuBar;
use Jovian\Bindings\Gtk\Gtk\GtkWindow;
use Surface\Contracts\Core\AboutInfo;
use Surface\Contracts\Drawing\GPUEngineDriver;
use Surface\Contracts\Drawing\GPUHost;
use Surface\Contracts\Drawing\SurfaceKind;
use Surface\Contracts\NativeWindows\GPUViewException;
use Surface\Contracts\NativeWindows\LinuxOSWindow;
use Surface\NativeWindows\Enums\MenuRole;
use Jovian\Venusian\GTK\Styles\CssEngine;
use Jovian\Bindings\Gtk\Enums\GtkContentFit;
use Jovian\Bindings\Gtk\Enums\GtkOverflow;
use Jovian\Bindings\Gtk\Enums\GtkPolicyType;
use Jovian\Bindings\Gtk\Gtk\GtkCheckButton as GtkCheckButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkDropDown as GtkDropDownWidget;
use Jovian\Bindings\Gtk\Gtk\GtkSingleSelection;
use Jovian\Bindings\Gtk\Gtk\GtkStringList;
use Jovian\Bindings\Gtk\Gtk\GtkEntry;
use Jovian\Bindings\Gtk\Gtk\GtkPasswordEntry;
use Jovian\Bindings\Gtk\Gtk\GtkPicture;
use Jovian\Bindings\Gtk\Gtk\GtkProgressBar as GtkProgressBarWidget;
use Jovian\Bindings\Gtk\Gtk\GtkScale;
use Jovian\Bindings\Gtk\Gtk\GtkScrolledWindow;
use Jovian\Bindings\Gtk\Gtk\GtkSeparator as GtkSeparatorWidget;
use Jovian\Bindings\Gtk\Gtk\GtkSpinner as GtkSpinnerWidget;
use Jovian\Bindings\Gtk\Gtk\GtkSwitch;
use Jovian\Bindings\Gtk\Gtk\GtkTextBuffer;
use Jovian\Bindings\Gtk\Gtk\GtkTextView;
use Jovian\Bindings\Gtk\Gtk\GtkToggleButton as GtkToggleButtonWidget;
use Jovian\Bindings\Gtk\Gtk\GtkVideo as GtkVideoWidget;
use Jovian\Venusian\GTK\Views\GTKButton;
use Jovian\Venusian\GTK\Views\GTKCheckbox;
use Jovian\Venusian\GTK\Views\GTKDatePicker;
use Jovian\Venusian\GTK\Views\GTKDropdown;
use Jovian\Venusian\GTK\Views\GTKGLSurface;
use Jovian\Venusian\GTK\Views\GTKGLView;
use Jovian\Venusian\GTK\Views\GTKGroup;
use Jovian\Venusian\GTK\Views\GTKImage;
use Jovian\Venusian\GTK\Views\GTKLabel;
use Jovian\Venusian\GTK\Views\GTKProgressBar;
use Jovian\Venusian\GTK\Views\GTKScrollView;
use Jovian\Venusian\GTK\Views\GTKSeparator;
use Jovian\Venusian\GTK\Views\GTKSlider;
use Jovian\Venusian\GTK\Views\GTKSpinner;
use Jovian\Venusian\GTK\Views\GTKTable;
use Jovian\Venusian\GTK\Views\GTKTextArea;
use Jovian\Venusian\GTK\Views\GTKTextInput;
use Jovian\Venusian\GTK\Views\GTKToggle;
use Jovian\Venusian\GTK\Views\GTKToggleButton;
use Jovian\Venusian\GTK\Views\GTKVideo;
use Jovian\Venusian\GTK\Views\HostsGtkChildren;
use Surface\Contracts\NativeWindows\Views\OSGroup;
use Surface\NativeWindows\Menus\MenuItemSpec;
use Surface\NativeWindows\Views\Button;
use Surface\NativeWindows\Views\Checkbox;
use Surface\NativeWindows\Views\DatePicker;
use Surface\NativeWindows\Views\Dropdown;
use Surface\NativeWindows\Views\GPUView;
use Surface\NativeWindows\Views\Group;
use Surface\NativeWindows\Views\Image;
use Surface\NativeWindows\Views\Label;
use Surface\NativeWindows\Views\ProgressBar;
use Surface\NativeWindows\Views\ScrollView;
use Surface\NativeWindows\Views\Separator;
use Surface\NativeWindows\Views\Slider;
use Surface\NativeWindows\Views\Spinner;
use Surface\NativeWindows\Views\Table;
use Surface\NativeWindows\Views\TextArea;
use Surface\NativeWindows\Views\TextInput;
use Surface\NativeWindows\Views\Toggle;
use Surface\NativeWindows\Views\ToggleButton;
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
     * The GtkFixed a mint puts its widget into: a hosting container's
     * child fixed, or the window content. The same fixed rides into the
     * twin so its frame moves land on the right parent.
     */
    protected function mintFixed(?OSGroup $in): GtkFixed
    {
        return $in instanceof HostsGtkChildren ? $in->childFixed() : $this->content;
    }

    /**
     * Put a GtkLabel into the surface at the origin; Windowable::label()
     * places it after.
     */
    protected function mintLabel(string $name, string $text, ?OSGroup $in): Label
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkLabelWidget::new($text);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKLabel($name, $this, $text, $widget, $fixed);
    }

    /**
     * Put a GtkButton into the surface at the origin; the GTKButton wires
     * `clicked` itself. Placed by Windowable::button().
     */
    protected function mintButton(string $name, string $label, ?OSGroup $in): Button
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkButtonWidget::newWithLabel($label);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKButton($name, $this, $label, $widget, $fixed);
    }

    /**
     * A GtkSpinner in the surface at the origin, stopped. Placed by
     * Windowable::spinner().
     */
    protected function mintSpinner(string $name, ?OSGroup $in): Spinner
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkSpinnerWidget::new();
        $fixed->put($widget, 0.0, 0.0);

        return new GTKSpinner($name, $this, $widget, $fixed);
    }

    /**
     * A GtkPicture fitting its file inside the frame: CONTAIN keeps the
     * aspect ratio, can-shrink keeps a big picture from flooring measure()
     * at its full size. Placed by Windowable::image().
     */
    protected function mintImage(string $name, ?string $path, ?OSGroup $in): Image
    {
        $fixed = $this->mintFixed($in);
        $widget = is_null($path) ? GtkPicture::new() : GtkPicture::newForFilename($path);
        $widget->setContentFit(GtkContentFit::CONTAIN);
        $widget->setCanShrink(true);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKImage($name, $this, $path, $widget, $fixed);
    }

    /**
     * A GtkVideo in the surface at the origin; the GTKVideo mints a
     * GtkMediaFile per path itself. Placed by Windowable::video().
     */
    protected function mintVideo(string $name, ?string $path, ?OSGroup $in): Video
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkVideoWidget::new();
        $fixed->put($widget, 0.0, 0.0);

        $video = new GTKVideo($name, $this, null, $widget, $fixed);
        if (! is_null($path)) {
            $video->setPath($path);
        }

        return $video;
    }

    /**
     * A GtkEntry — or a GtkPasswordEntry for a secret input, which masks
     * its glyphs itself. The GTKTextInput wires `changed` and `activate`.
     */
    protected function mintTextInput(string $name, string $value, ?string $placeholder, bool $secret, ?OSGroup $in): TextInput
    {
        $fixed = $this->mintFixed($in);
        $widget = $secret ? GtkPasswordEntry::new() : GtkEntry::new();
        $widget->setText($value);
        if (! $secret && ! is_null($placeholder)) {
            $widget->setPlaceholderText($placeholder);
        }
        $fixed->put($widget, 0.0, 0.0);

        return new GTKTextInput($name, $this, $value, $placeholder, $secret, $widget, $fixed);
    }

    /**
     * A GtkTextView over its own buffer inside a GtkScrolledWindow; the
     * GTKTextArea wires the buffer's `changed` itself.
     */
    protected function mintTextArea(string $name, string $value, ?OSGroup $in): TextArea
    {
        $fixed = $this->mintFixed($in);
        $buffer = GtkTextBuffer::new(null);
        $buffer->setText($value, -1);
        $text = GtkTextView::newWithBuffer($buffer);
        $scrolled = GtkScrolledWindow::new();
        $scrolled->setChild($text);
        $fixed->put($scrolled, 0.0, 0.0);

        return new GTKTextArea($name, $this, $value, $scrolled, $text, $buffer, $fixed);
    }

    /**
     * A horizontal GtkScale over the range, value drawn nowhere — Surface
     * owns presentation. The GTKSlider wires `value-changed` itself.
     */
    protected function mintSlider(string $name, float $min, float $max, float $value, ?OSGroup $in): Slider
    {
        $fixed = $this->mintFixed($in);
        $step = $max > $min ? ($max - $min) / 100.0 : 1.0;
        $widget = GtkScale::newWithRange(GtkOrientation::HORIZONTAL, $min, $max, $step);
        $widget->setDrawValue(false);
        $widget->setValue($value);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKSlider($name, $this, $min, $max, $value, $widget, $fixed);
    }

    /**
     * A GtkSwitch holding the initial state; the GTKToggle listens on
     * notify::active itself.
     */
    protected function mintToggle(string $name, bool $on, ?OSGroup $in): Toggle
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkSwitch::new();
        $widget->setActive($on);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKToggle($name, $this, $on, $widget, $fixed);
    }

    /**
     * A GtkToggleButton; the GTKToggleButton wires `toggled` itself.
     */
    protected function mintToggleButton(string $name, string $label, bool $pressed, ?OSGroup $in): ToggleButton
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkToggleButtonWidget::newWithLabel($label);
        $widget->setActive($pressed);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKToggleButton($name, $this, $label, $pressed, $widget, $fixed);
    }

    /**
     * A GtkCheckButton; the GTKCheckbox wires `toggled` itself.
     */
    protected function mintCheckbox(string $name, string $label, bool $checked, ?OSGroup $in): Checkbox
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkCheckButtonWidget::newWithLabel($label);
        $widget->setActive($checked);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKCheckbox($name, $this, $label, $checked, $widget, $fixed);
    }

    /**
     * A GtkProgressBar — fraction is already 0..1, Surface's promise.
     */
    protected function mintProgressBar(string $name, float $progress, ?OSGroup $in): ProgressBar
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkProgressBarWidget::new();
        $widget->setFraction($progress);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKProgressBar($name, $this, $progress, $widget, $fixed);
    }

    /**
     * A GtkDropDown over a string list; the GTKDropdown listens on
     * notify::selected itself.
     */
    protected function mintDropdown(string $name, array $options, int $selected, ?OSGroup $in): Dropdown
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkDropDownWidget::newFromStrings(array_values($options));
        if ($options !== []) {
            $widget->setSelected(max(0, min(count($options) - 1, $selected)));
        }
        $fixed->put($widget, 0.0, 0.0);

        return new GTKDropdown($name, $this, $options, $selected, $widget, $fixed);
    }

    /**
     * A GtkCalendar; the GTKDatePicker translates 1-based months and
     * wires day-selected itself.
     */
    protected function mintDatePicker(string $name, ?string $date, ?OSGroup $in): DatePicker
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkCalendarWidget::new();
        $fixed->put($widget, 0.0, 0.0);

        return new GTKDatePicker($name, $this, $date, $widget, $fixed);
    }

    /**
     * A GtkColumnView over a string-list of row placeholders; the
     * GTKTable owns the per-column factories and the selection.
     *
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    protected function mintTable(string $name, array $columns, array $rows, ?OSGroup $in): Table
    {
        $fixed = $this->mintFixed($in);
        $placeholders = [];
        foreach (array_keys(array_values($rows)) as $index) {
            $placeholders[] = (string) $index;
        }
        $strings = GtkStringList::new($placeholders);
        $selection = GtkSingleSelection::new($strings);
        $view = GtkColumnView::new($selection);
        $fixed->put($view, 0.0, 0.0);

        return new GTKTable($name, $this, $columns, $rows, $view, $selection, $strings, $fixed);
    }

    /**
     * A GtkSeparator in the orientation the conjure-time aspect decided.
     */
    protected function mintSeparator(string $name, bool $horizontal, ?OSGroup $in): Separator
    {
        $fixed = $this->mintFixed($in);
        $widget = GtkSeparatorWidget::new($horizontal ? GtkOrientation::HORIZONTAL : GtkOrientation::VERTICAL);
        $fixed->put($widget, 0.0, 0.0);

        return new GTKSeparator($name, $this, $horizontal, $widget, $fixed);
    }

    /**
     * A GtkFixed as the container surface, overflow hidden so the group
     * clips its subtree. Children conjured into the group put onto it.
     */
    protected function mintGroup(string $name, ?OSGroup $in): Group
    {
        $fixed = $this->mintFixed($in);
        $surface = GtkFixed::new();
        $surface->setOverflow(GtkOverflow::HIDDEN);
        $fixed->put($surface, 0.0, 0.0);

        return new GTKGroup($name, $this, $surface, $fixed);
    }

    /**
     * A GtkScrolledWindow over an inner GtkFixed sized later by the
     * content extent. Vertical scrolling only, matching the macOS twin.
     */
    protected function mintScrollView(string $name, ?OSGroup $in): ScrollView
    {
        $fixed = $this->mintFixed($in);
        $inner = GtkFixed::new();
        $scrolled = GtkScrolledWindow::new();
        $scrolled->setChild($inner);
        $scrolled->setPolicy(GtkPolicyType::NEVER, GtkPolicyType::AUTOMATIC);
        $fixed->put($scrolled, 0.0, 0.0);

        return new GTKScrollView($name, $this, $scrolled, $inner, $fixed);
    }

    /** GTK hosts a GL context and nothing else — a layer engine is refused by kind, no package named. */
    public static function hostsSurfaceKind(SurfaceKind $kind): bool
    {
        return $kind === SurfaceKind::GL_CONTEXT;
    }

    /**
     * A GtkGLArea that allows GL *and* GLES (a constraint, not a preference:
     * narrowing to GL leaves the Pi with no context and no error), 3.0+, no
     * auto-render (Surface decides when a frame happens), no depth. Order:
     * native → surface → host → attach → twin.
     *
     * @throws GPUViewException When the engine wants a surface kind GTK cannot mint.
     */
    protected function mintGPU(string $name, GPUEngineDriver $driver, ?OSGroup $in): GPUView
    {
        if (! self::hostsSurfaceKind($driver->surfaceKind())) {
            throw GPUViewException::unsupported($driver->engine()->value, 'gtk');
        }

        $area = GtkGLArea::new();
        $area->setAllowedApis(GdkGLAPI::GL->value | GdkGLAPI::GLES->value)
            ->setRequiredVersion(3, 0)
            ->setAutoRender(false)
            ->setHasDepthBuffer(false);

        $fixed = $this->mintFixed($in);
        $fixed->put($area, 0.0, 0.0);

        $scale = (float) max(1, $area->getScaleFactor());
        $gl = new GTKGLSurface($area);
        $attachment = $driver->attach(new GPUHost(0, 0, 0, $scale, $gl));

        return new GTKGLView($name, $this, $driver->engine(), $attachment->executor, $scale, $gl, $fixed);
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

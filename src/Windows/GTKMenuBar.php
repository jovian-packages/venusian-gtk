<?php

namespace Jovian\Toolkits\GTK\Windows;

use GMenu;
use GSimpleAction;
use GSimpleActionGroup;
use GtkApplication;
use GtkPopoverMenuBar;
use GtkWindow;
use GVariant;
use Jovian\Toolkits\GTK\Bridge\GTKSession;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\Menus\MenuRole;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Menus\MenuItem;
use Surface\Windows\Menus\MenuProfile;

/**
 * A menu profile built once into a GMenu model and the actions its items name: "win"
 * actions for a window's bar, "app" actions for the app's default bar (macOS), which
 * works with no window. Separators become section boundaries, which is how GTK draws
 * them; toggles are stateful boolean actions. Every item posts mail; About shows a
 * non-modal dialog.
 */
class GTKMenuBar
{
    /**
     * The action scope its items name: "win" for a window's bar, "app" for the default bar.
     * @var string
     */
    protected readonly string $scope;

    /**
     * Hotkeys by detailed action name, applied while the accelerators are on.
     * @var array<string, string>
     */
    protected array $accels = [];

    protected GMenu $model;

    protected GSimpleActionGroup $actions;

    /**
     * Toggle actions by item id.
     * @var array<string, GSimpleAction>
     */
    protected array $toggles = [];

    /**
     * @param GTKSession $session
     * @param MenuProfile $profile
     * @param string|null $window the window it belongs to; null for the app's default bar, whose items post '' (Quit posts null)
     * @param array{name?: string|null, version?: string|null, copyright?: string|null} $about
     * @param GtkWindow|null $parent the window About is shown over
     */
    public function __construct(
        protected readonly GTKSession $session,
        protected readonly MenuProfile $profile,
        protected readonly ?string $window,
        protected readonly array $about,
        protected readonly ?GtkWindow $parent,
    ) {
        $this->scope = is_null($window) ? 'app' : 'win';
        $this->model = GMenu::new();
        $this->actions = GSimpleActionGroup::new();

        foreach ($profile->folders as $folder) {
            $this->model->appendSubmenu($folder->label, $this->folder($folder));
        }
    }

    /**
     * Turn this bar's hotkeys on or off in the application's accelerator table. A window's bar
     * keeps them on: "win" actions resolve in the focused window only. The default bar has them
     * on only while it is the bar shown, so a hotkey never names both a window's and its action.
     * @param bool $on
     * @return void
     */
    public function setAccelerators(bool $on): void
    {
        foreach ($this->accels as $action => $accel) {
            $this->session->application()->setAccelsForAction($action, $on ? [$accel] : []);
        }
    }

    /**
     * Put the actions in the application's own map, where "app" actions are looked up.
     * @param GtkApplication $application
     * @return void
     */
    public function addTo(GtkApplication $application): void
    {
        foreach ($this->actions->listActions() as $name) {
            $application->addAction($this->actions->lookupAction($name));
        }
    }

    /**
     * Take the actions back out of the application's map and turn the hotkeys off.
     * @param GtkApplication $application
     * @return void
     */
    public function removeFrom(GtkApplication $application): void
    {
        $this->setAccelerators(false);

        foreach ($this->actions->listActions() as $name) {
            $application->removeAction($name);
        }
    }

    /**
     * The platform's menu hotkey: Command (GDK's Meta) on macOS, Control elsewhere. GTK's
     * <Primary> is Control on every platform.
     * @param string $hotkey
     * @return string
     */
    public static function accelerator(string $hotkey): string
    {
        return (PHP_OS_FAMILY === 'Darwin' ? '<Meta>' : '<Control>').strtolower($hotkey);
    }

    public function model(): GMenu
    {
        return $this->model;
    }

    public function actions(): GSimpleActionGroup
    {
        return $this->actions;
    }

    /**
     * A bar widget showing the model, for windows that carry their own (Linux).
     * @return GtkPopoverMenuBar
     */
    public function widget(): GtkPopoverMenuBar
    {
        return GtkPopoverMenuBar::newFromModel($this->model);
    }

    /**
     * One item's action, by its profile id.
     * @param string $id
     * @return GSimpleAction
     * @throws WindowException
     */
    public function action(string $id): GSimpleAction
    {
        return $this->actions->lookupAction($id) ?? throw new WindowException("No item '{$id}' in menu profile '{$this->profile->name}'.");
    }

    public function setToggle(string $item, bool $on): void
    {
        $this->toggle($item)->setState(GVariant::newBoolean($on));
    }

    public function isToggled(string $item): bool
    {
        return $this->toggle($item)->getState()->getBoolean();
    }

    protected function toggle(string $item): GSimpleAction
    {
        return $this->toggles[$item] ?? throw new WindowException("No toggle item '{$item}' in menu profile '{$this->profile->name}'.");
    }

    protected function folder(MenuItem $folder): GMenu
    {
        $menu = GMenu::new();
        $section = GMenu::new();

        foreach ($folder->items as $item) {
            if ($item->separator) {
                $menu->appendSection(null, $section);
                $section = GMenu::new();
                continue;
            }

            if ($item->isFolder()) {
                $section->appendSubmenu($item->label, $this->folder($item));
                continue;
            }

            $section->append($item->label, "{$this->scope}.{$item->id}");
            $this->actions->addAction($this->actionFor($item));

            if (! is_null($item->hotkey)) {
                $this->accels["{$this->scope}.{$item->id}"] = self::accelerator($item->hotkey);
            }
        }

        $menu->appendSection(null, $section);

        return $menu;
    }

    protected function actionFor(MenuItem $item): GSimpleAction
    {
        $window = $this->window ?? '';

        if ($item->toggle) {
            $action = GSimpleAction::newStateful($item->id, null, GVariant::newBoolean($item->on));
            // With no activate handler, activating a boolean stateful action asks to change its state.
            g_signal_connect($action, 'change-state', function (GSimpleAction $action, GVariant $value) use ($item, $window): void {
                $action->setState($value);
                $this->session->post(new MenuToggled($window, $item->id, $value->getBoolean()));
            });
            $this->toggles[$item->id] = $action;

            return $action;
        }

        $action = GSimpleAction::new($item->id, null);
        g_signal_connect($action, 'activate', match ($item->role) {
            MenuRole::ABOUT => function (): void {
                $this->session->showAbout($this->about, $this->parent);
            },
            MenuRole::QUIT => fn () => $this->session->post(new QuitRequested($this->window)),
            default => fn () => $this->session->post(new MenuActivated($window, $item->id)),
        });

        return $action;
    }
}

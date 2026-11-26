<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Gtk;
use Jovian\Toolkits\GTK\GtkAdapterDriver;
use Jovian\Toolkits\GTK\GtkSession;
use Jovian\Toolkits\GTK\GtkWindow;
use Jovian\Toolkits\GTK\Menus\GtkAccelerator;
use Jovian\Toolkits\GTK\Menus\GtkMenuBar;
use Surface\Toolkits\Managers\MenuManager;
use Surface\Toolkits\Menus\About;
use Surface\Toolkits\Menus\AboutPanel;
use Surface\Toolkits\Menus\Choice;
use Surface\Toolkits\Menus\Item;
use Surface\Toolkits\Menus\Menu;
use Surface\Toolkits\Menus\MenuBar;
use Surface\Toolkits\Menus\Separator;
use Surface\Toolkits\Menus\Shortcut;
use Surface\Toolkits\Menus\Standard;
use Surface\Toolkits\Menus\Toggle;
use Surface\Toolkits\Menus\Runtime\MenuNodeActivated;
use Surface\Toolkits\ToolkitSession;
use Surface\Contracts\Toolkits\Menus\BarNode;
use Surface\Contracts\Toolkits\Menus\BarNodeKind;
use Surface\Contracts\Toolkits\Menus\StandardKind;

/*
| The menu bar on real GMenus, GActions and GTK windows that are never shown: the application
| may not become active, and no window is presented. So no window is active here, and GTK draws
| nothing. What needs that (the macOS system bar GTK writes the model into, a real key press,
| the bar inside a window on Linux) is checked by running an app.
*/

const MAC = PHP_OS_FAMILY === 'Darwin';

/** @return array{MenuManager, GtkAdapterDriver, GtkSession} */
function quietMenus(?MenuBar $default = null): array
{
    $windows = is_null($default) ? [] : ['default_menu' => 'app', 'menus' => ['app' => $default]];
    $app = bootCore(['toolkits' => ['windows' => $windows]]);
    $toolkit = quietToolkit();

    $session = new GtkSession($app, APPLICATION_ID);
    Closure::bind(function () use ($toolkit): void {
        [$this->application, $this->initialized, $this->connected] = [$toolkit, true, true];
    }, $session, ToolkitSession::class)();

    $driver = new GtkAdapterDriver($app);
    Closure::bind(function () use ($session): void {
        $this->session = $session;
    }, $driver, GtkAdapterDriver::class)();

    return [$app->make(MenuManager::class), $driver, $session];
}

function quietWindow(array $menus, ?MenuBar $bar = null, string $name = 'main'): GtkWindow
{
    [$manager, $driver] = $menus;
    /** @var GtkWindow $window */
    $window = $driver->open($name, 400, 300);
    $manager->opened($window, $driver, $bar);
    focusOn($window);

    return $window;
}

/** What the window's notify::is-active runs when GTK activates it. */
function focusOn(GtkWindow $window): void
{
    (fn () => $this->gainedFocus())->call($window);
}

function focusOff(GtkWindow $window): void
{
    (fn () => $this->lostFocus())->call($window);
}

function barOf(string $window = 'main'): GtkMenuBar
{
    return (fn () => $this->windows[$window]->mounter->bar)->call(app(MenuManager::class));
}

function unfocusedBar(): GtkMenuBar
{
    return (fn () => $this->unfocused->mounter->bar)->call(app(MenuManager::class));
}

/**
 * The labels in a GMenu as GTK has them; '-' between sections.
 *
 * @return list<string>
 */
function labelsIn(GMenu $menu): array
{
    $labels = [];
    for ($i = 0; $i < $menu->getNItems(); $i++) {
        $labels[] = (string) $menu->getItemAttributeValue($i, 'label', 's')?->getString();
    }

    return $labels;
}

/**
 * The top-level menus of a bar's model, or what a menu at a path of titles holds, from GTK's
 * own GMenus; '-' is where GTK draws a line.
 *
 * @return list<string>
 */
function shown(GtkMenuBar $bar, string ...$titles): array
{
    if ($titles === []) {
        return array_map(fn (string $label): string => str_replace('__', '_', $label), labelsIn($bar->model()));
    }

    $node = nodeOf($bar, ...$titles);
    $out = [];
    foreach ($node->sections as $at => $section) {
        if ($at > 0) {
            $out[] = '-';
        }
        array_push($out, ...array_map(fn (string $label): string => str_replace('__', '_', $label), labelsIn($section)));
    }

    return $out;
}

/** The node at a path of titles. */
function nodeOf(GtkMenuBar $bar, string ...$titles): object
{
    return (function () use ($titles): object {
        [$level, $found] = [$this->top, null];
        foreach ($titles as $title) {
            $found = null;
            foreach ($level as $uuid) {
                $node = $this->nodes[$uuid];
                if ($node->label === $title || in_array($title, array_map(fn ($item) => str_replace('__', '_', (string) $item->getAttributeValue('label', 's')?->getString()), $node->items), true)) {
                    $found = $node;
                    break;
                }
            }
            if (is_null($found)) {
                throw new RuntimeException("No menu node '{$title}'.");
            }
            $level = $found->children;
        }

        return $found;
    })->call($bar);
}

function uuidOf(GtkMenuBar $bar, string ...$titles): string
{
    $node = nodeOf($bar, ...$titles);

    return (fn (): string => (string) array_search($node, $this->nodes, true))->call($bar);
}

function choose(GtkMenuBar $bar, string ...$titles): void
{
    $bar->action(uuidOf($bar, ...$titles))->activate(null);
}

/** @return list<object> what was posted, each node chosen delivered as the loop would */
function delivered(array $menus): array
{
    [$manager, , $session] = $menus;
    $take = fn (): array => Closure::bind(function (): array {
        [$mail, $this->outbox] = [$this->outbox, []];

        return $mail;
    }, $session, ToolkitSession::class)();

    foreach ($take() as $mail) {
        if ($mail instanceof MenuNodeActivated) {
            $manager->deliver($mail);
        }
    }

    return $take();
}

function turnGtk(): void
{
    for ($i = 0; $i < 20; $i++) {
        GMainContext::default()->iteration(false);
    }
}

/** One GTK application for the process, never active. */
function quietToolkit(): Gtk
{
    static $toolkit = null;
    if (is_null($toolkit)) {
        $toolkit = new Gtk(APPLICATION_ID);
        if (MAC) {
            NSApplication::sharedApplication()->setActivationPolicy(NSApplicationActivationPolicy::PROHIBITED);
        }
        $toolkit->connect();
    }

    return $toolkit;
}

function gtkApplication(): GtkApplication
{
    return quietToolkit()->get();
}

afterEach(function (): void {
    foreach ($this->opened ?? [] as $window) {
        if ($window->isOpen()) {
            $window->close();
        }
    }
    turnGtk();
});

it('builds the tree in the order written, a line as a section', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Open', id: 'open'),
            Separator::make(),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf')),
            Choice::make(['s' => 'Small', 'l' => 'Large'], 'l', id: 'size'),
            Toggle::make('Wrap_Lines', true, id: 'wrap'),
        ),
        Menu::make('Help', 'help', Item::make('Guide', id: 'guide')),
    ));
    $bar = barOf();

    expect(array_slice(shown($bar), -2))->toBe(['File', 'Help'])
        ->and(shown($bar, 'File'))->toBe(['Open', '-', 'Export', 'Small', 'Large', 'Wrap_Lines'])
        ->and(shown($bar, 'File', 'Export'))->toBe(['PDF'])
        ->and(labelsIn(nodeOf($bar, 'File')->sections[1])[3])->toBe('Wrap__Lines')    // no mnemonic
        ->and($bar->action(uuidOf($bar, 'File', 'Small'))->getState()->getString())->toBe('l')
        ->and($bar->action(uuidOf($bar, 'File', 'Wrap_Lines'))->getState()->getBoolean())->toBeTrue()
        ->and($bar->detailed(uuidOf($bar, 'File', 'Open')))->toStartWith('win.menu-')
        ->and($this->opened[0]->native()->hasAction(substr($bar->detailed(uuidOf($bar, 'File', 'Open')), 4)))->toBeTrue();
});

it('posts an item\'s mail, after its callback', function (): void {
    $menus = quietMenus();
    $ran = 0;
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('File', 'file',
        Item::make('Save', id: 'save', onClick: function () use (&$ran): void { $ran++; }),
    )));
    delivered($menus);

    choose(barOf(), 'File', 'Save');
    expect($ran)->toBe(0);    // never inside GTK's signal

    $mail = delivered($menus);
    expect($ran)->toBe(1)->and($mail[0]->name())->toBe('menu.clicked.file.save')->and($mail[0]->window)->toBe('main');
});

it('moves a toggle\'s and a choice\'s mark at once, by topside\'s word', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('View', 'view',
        Toggle::make('Wrap', false, id: 'wrap'),
        Choice::make(['1' => 'One', '2' => 'Two'], '1', id: 'zoom'),
    )));
    delivered($menus);
    $bar = barOf();
    $zoom = $bar->action(uuidOf($bar, 'View', 'One'));

    choose($bar, 'View', 'Wrap');
    $zoom->activate(GVariant::newString('2'));

    expect($bar->action(uuidOf($bar, 'View', 'Wrap'))->getState()->getBoolean())->toBeTrue()
        ->and($zoom->getState()->getString())->toBe('2');

    $mail = delivered($menus);
    expect([$mail[0]->name(), $mail[0]->on, $mail[1]->name(), $mail[1]->option])
        ->toBe(['menu.toggled.view.wrap', true, 'menu.chosen.view.zoom', '2']);

    $zoom->activate(GVariant::newString('2'));    // the chosen one again
    expect(delivered($menus))->toBe([]);
});

it('asks to quit and quits nothing', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Program', 'program', Standard::quit())));
    delivered($menus);

    choose(barOf(), 'Program', shown(barOf(), 'Program')[0]);

    expect(delivered($menus)[0]->name())->toBe('quit.requested');
});

it('answers GTK\'s macOS application menu with the shown bar\'s About, Preferences and Quit', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::application(
            About::panel(name: 'My App', version: '1.0'),
            Separator::make(),
            Standard::preferences(id: 'preferences'),
            Standard::hide(),
            Standard::quit(),
        ),
        Menu::make('File', 'file', Item::make('Save', id: 'save')),
    ));
    delivered($menus);
    $gtk = gtkApplication();
    $bar = barOf();

    // GTK's own items, and none of the application's own: the menu is not in the bar.
    expect(shown($bar))->toBe(['File'])
        ->and($bar->isShown())->toBeTrue()
        ->and($gtk->hasAction('about'))->toBeTrue()
        ->and($gtk->hasAction('preferences'))->toBeTrue()
        ->and($gtk->hasAction('quit'))->toBeTrue()
        ->and($gtk->getAccelsForAction('app.quit'))->toBe(['<Meta>q']);

    $gtk->activateAction('preferences', null);
    $gtk->activateAction('quit', null);
    expect(array_map(fn (object $mail): string => $mail->name(), delivered($menus)))
        ->toBe(['menu.clicked.app.preferences', 'quit.requested']);

})->skip(! MAC, 'GTK has an application menu on macOS only.');

it('puts what GTK has no place for in a menu of its own, there while it holds something', function (): void {
    $menus = quietMenus();
    $with = fn (bool $own): MenuBar => MenuBar::make(
        Menu::application(Standard::quit(), ...($own ? [Item::make('Check for Updates', id: 'updates')] : [])),
        Menu::make('File', 'file', Item::make('Save', id: 'save')),
    );
    $this->opened[] = quietWindow($menus, $with(false));
    $bar = barOf();

    expect(shown($bar))->toBe(['File']);

    $menus[0]->window('main', $with(true));
    expect(shown($bar))->toBe(['venusian-gtk-tests', 'File'])
        ->and(shown($bar, 'venusian-gtk-tests'))->toBe(['Check for Updates']);

    $menus[0]->window('main', $with(false));
    expect(shown($bar))->toBe(['File']);
})->skip(! MAC, 'GTK has an application menu on macOS only.');

it('gives GTK\'s application menu actions to the bar shown, and takes them back', function (): void {
    $menus = quietMenus(MenuBar::make(Menu::make('Default', 'default', Item::make('Open', id: 'open')->shortcut(Shortcut::primary('O')))));
    $this->opened[] = $one = quietWindow($menus, MenuBar::make(Menu::application(Standard::quit()), Menu::make('One', 'one')), 'one');
    $gtk = gtkApplication();
    $open = unfocusedBar()->detailed(uuidOf(unfocusedBar(), 'Default', 'Open'));

    expect($gtk->hasAction('quit'))->toBeTrue()->and($gtk->getAccelsForAction($open))->toBe([]);

    focusOff($one);
    expect(unfocusedBar()->isShown())->toBeTrue()
        ->and($gtk->hasAction('quit'))->toBeFalse()    // GTK shows its Quit disabled
        ->and($gtk->getAccelsForAction($open))->toBe(['<Meta>o']);

    focusOn($one);
    expect(barOf('one')->isShown())->toBeTrue()
        ->and($gtk->hasAction('quit'))->toBeTrue()
        ->and($gtk->getAccelsForAction($open))->toBe([]);
})->skip(! MAC, 'GTK shows one bar for the application on macOS only.');

it('makes the application menu an ordinary first menu off macOS', function (): void {
    [, , $session] = quietMenus();
    $bar = new GtkMenuBar($session, null, false);
    $bar->insert(null, 0, new BarNode(BarNodeKind::MENU, 'app', 'My App', application: true));
    $bar->insert('app', 0, new BarNode(BarNodeKind::STANDARD, 'about', 'About', standard: StandardKind::ABOUT, replaced: true));
    $bar->insert('app', 1, new BarNode(BarNodeKind::STANDARD, 'quit', 'Quit', standard: StandardKind::QUIT));
    $bar->insert(null, 1, new BarNode(BarNodeKind::MENU, 'file', 'File'));

    expect(shown($bar))->toBe(['My App', 'File'])
        ->and(shown($bar, 'My App'))->toBe(['About', 'Quit'])
        ->and($bar->detailed('quit'))->toBe('app.menu-quit');

    $bar->release();
});

it('edits a text widget with its own actions, and nothing else', function (): void {
    quietMenus();
    $entry = GtkEntry::new();
    $entry->getBuffer()->setText('one two', -1);
    $text = $entry->getFirstChild();    // the GtkText that has the focus in an entry

    expect(GtkMenuBar::editWidget($text, StandardKind::SELECT_ALL))->toBeTrue()
        ->and(GtkMenuBar::editWidget($text, StandardKind::CUT))->toBeTrue()
        ->and($entry->getBuffer()->getText())->toBe('')
        ->and(GtkMenuBar::editWidget($text, StandardKind::UNDO))->toBeTrue()
        ->and($entry->getBuffer()->getText())->toBe('one two')
        ->and(GtkMenuBar::editWidget($text, StandardKind::QUIT))->toBeFalse()
        ->and(GtkMenuBar::editWidget(GtkBox::new(GtkOrientation::VERTICAL, 0), StandardKind::COPY))->toBeFalse()
        ->and(GtkMenuBar::edit(null, StandardKind::COPY))->toBeFalse();
});

it('pastes as plain text for Paste and Match Style, as Paste does', function (): void {
    quietMenus();
    $entry = GtkEntry::new();
    $text = $entry->getFirstChild();
    $text->activateActionVariant('selection.select-all', null);

    expect(GtkMenuBar::EDIT_ACTIONS[StandardKind::PASTE_AND_MATCH_STYLE->value])
        ->toBe(GtkMenuBar::EDIT_ACTIONS[StandardKind::PASTE->value])
        ->and(GtkMenuBar::editWidget($text, StandardKind::PASTE_AND_MATCH_STYLE))->toBeTrue();
});

it('has an edit action for every edit kind', function (): void {
    foreach (StandardKind::cases() as $kind) {
        if ($kind->isEdit()) {
            expect(GtkMenuBar::EDIT_ACTIONS)->toHaveKey($kind->value);
        }
    }
});

it('lets a standard item given an id report instead', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Edit it', 'edit', Standard::copy()->id('copy'))));
    delivered($menus);

    choose(barOf(), 'Edit it', 'Copy');

    expect(array_map(fn (object $mail): string => $mail->name(), delivered($menus)))->toBe(['menu.clicked.edit.copy']);
});

it('closes its window from Close Window, by the window\'s own close path', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('Window', 'window', Standard::closeWindow())));

    choose(barOf(), 'Window', shown(barOf(), 'Window')[0]);

    expect($window->isOpen())->toBeFalse();
});

it('shows what ext-gtk cannot do disabled', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('Window', 'window',
        Standard::minimize(), Standard::fullscreen(), Standard::closeWindow(),
    )));
    $bar = barOf();
    $enabled = array_map(fn (string $label): bool => $bar->action(uuidOf($bar, 'Window', $label))->getEnabled(), shown($bar, 'Window'));

    expect($enabled)->toBe([false, false, true])
        ->and($bar->supports(StandardKind::MINIMIZE))->toBeFalse()
        ->and($bar->supports(StandardKind::FULLSCREEN))->toBeFalse()
        ->and($bar->supports(StandardKind::COPY))->toBeTrue();
});

it('shows enabled and disabled, menus inside menus too', function (): void {
    $menus = quietMenus();
    $bar = fn (bool $on): MenuBar => MenuBar::make(
        Menu::make('File', 'file',
            Item::make('Save', id: 'save')->enabled($on),
            Menu::make('Export', 'export', Item::make('PDF', id: 'pdf'))->enabled($on),
            Standard::copy()->enabled($on),
        ),
        Menu::make('Tools', 'tools', Item::make('Run', id: 'run'))->enabled($on),
    );
    $this->opened[] = quietWindow($menus, $bar(false));
    $native = barOf();

    $state = fn (): array => array_map(
        fn (array $path): bool => $native->action(uuidOf($native, ...$path))->getEnabled(),
        [['File', 'Save'], ['File', 'Export'], ['File', 'Export', 'PDF'], ['File', 'Copy'], ['Tools'], ['Tools', 'Run']],
    );
    expect($state())->toBe([false, false, false, false, false, false]);

    $menus[0]->window('main', $bar(true));
    expect($state())->toBe([true, true, true, true, true, true]);

    $menus[0]->window('main', $bar(false));
    expect($state())->toBe([false, false, false, false, false, false]);
});

it('changes in place: label, order, lines, what is added and removed, shortcut', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('List', 'list',
        Item::make('One', id: 'one'),
        Item::make('Two', id: 'two'),
        Separator::make(),
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('Three', id: 'three'),
    )));
    $bar = barOf();
    $kept = $bar->action(uuidOf($bar, 'List', 'One'));
    $gone = $bar->detailed(uuidOf($bar, 'List', 'Two'));

    $menus[0]->window('main', MenuBar::make(Menu::make('List', 'list',
        Choice::make(['a' => 'A', 'b' => 'B'], 'a', id: 'letter'),
        Item::make('New', id: 'new'),
        Separator::make(),
        Item::make('Three', id: 'three'),
        Item::make('First', id: 'one')->shortcut(Shortcut::primary('J')),
    )));

    $first = uuidOf($bar, 'List', 'First');
    expect(shown($bar, 'List'))->toBe(['A', 'B', 'New', '-', 'Three', 'First'])
        ->and($bar->action($first))->toBe($kept)
        ->and(gtkApplication()->getAccelsForAction($bar->detailed($first)))->toBe([MAC ? '<Meta>j' : '<Control>j'])
        ->and($this->opened[0]->native()->hasAction(substr($gone, 4)))->toBeFalse();
});

it('leaves a bare key to the focused widget and an edit item\'s keys to the widgets', function (): void {
    $menus = quietMenus();
    $this->opened[] = quietWindow($menus, MenuBar::make(
        Menu::make('Format', 'format', Item::make('Remove Last Line', id: 'trim')->shortcut(Shortcut::key('Delete'))),
        Menu::make('Edit', 'edit', Standard::copy()),
    ));
    $bar = barOf();
    $trim = uuidOf($bar, 'Format', 'Remove Last Line');
    $copy = uuidOf($bar, 'Edit', 'Copy');
    $accel = fn (string $uuid): ?string => nodeOf($bar, ...($uuid === $trim ? ['Format', 'Remove Last Line'] : ['Edit', 'Copy']))
        ->items[0]->getAttributeValue('accel', 's')?->getString();

    expect(gtkApplication()->getAccelsForAction($bar->detailed($trim)))->toBe([])    // no accelerator before the widget
        ->and($accel($trim))->toBe('Delete')                                          // the menu shows it
        ->and(nodeOf($bar, 'Format', 'Remove Last Line')->late)->toBeInstanceOf(GtkShortcut::class)
        ->and(gtkApplication()->getAccelsForAction($bar->detailed($copy)))->toBe([])
        ->and($accel($copy))->toBe(MAC ? '<Meta>c' : '<Control>c');

    delivered($menus);
    expect($bar->lateKey($trim))->toBeTrue()
        ->and(delivered($menus)[0]->name())->toBe('menu.clicked.format.trim');
});

it('writes a shortcut as a GTK accelerator', function (string $written, string $mac, string $elsewhere): void {
    $shortcut = Shortcut::of($written);

    expect(GtkAccelerator::accelerator($shortcut, true))->toBe($mac)
        ->and(GtkAccelerator::accelerator($shortcut, false))->toBe($elsewhere)
        ->and(GtkShortcutTrigger::parseString(GtkAccelerator::accelerator($shortcut, MAC)))->not->toBeNull();    // GTK reads it
})->with([
    ['primary+s', '<Meta>s', '<Control>s'],
    ['primary+shift+s', '<Meta><Shift>s', '<Control><Shift>s'],
    ['ctrl+alt+up', '<Control><Alt>Up', '<Control><Alt>Up'],
    ['primary+ctrl+f', '<Meta><Control>f', '<Control>f'],
    ['primary+,', '<Meta>comma', '<Control>comma'],
    ['alt+pagedown', '<Alt>Page_Down', '<Alt>Page_Down'],
    ['delete', 'Delete', 'Delete'],
    ['shift+f5', '<Shift>F5', '<Shift>F5'],
    ['primary+enter', '<Meta>Return', '<Control>Return'],
    ['primary+1', '<Meta>1', '<Control>1'],
]);

it('gives a window its own actions and the bar for no window the application\'s', function (): void {
    $menus = quietMenus(MenuBar::make(Menu::make('Default', 'default', Item::make('Open', id: 'open'))));
    $this->opened[] = quietWindow($menus, MenuBar::make(Menu::make('One', 'one')), 'one');
    $this->opened[] = $two = quietWindow($menus, MenuBar::make(Menu::make('Two', 'two', Item::make('Go', id: 'go'))), 'two');
    delivered($menus);

    $go = barOf('two')->detailed(uuidOf(barOf('two'), 'Two', 'Go'));
    $open = unfocusedBar()->detailed(uuidOf(unfocusedBar(), 'Default', 'Open'));
    expect($go)->toStartWith('win.')
        ->and($two->native()->hasAction(substr($go, 4)))->toBeTrue()
        ->and($open)->toStartWith('app.')
        ->and(gtkApplication()->hasAction(substr($open, 4)))->toBeTrue();

    // A bar reports for its own window whichever is active, and the bar for none for none.
    focusOff($two);
    choose(barOf('two'), 'Two', 'Go');
    choose(unfocusedBar(), 'Default', 'Open');
    expect(array_map(fn (object $m): array => [$m->name(), $m->window], delivered($menus)))
        ->toBe([['menu.clicked.two.go', 'two'], ['menu.clicked.default.open', null]]);
})->skip(! MAC, 'Topside keeps a bar for no window on macOS only.');

it('drops its actions and shortcuts when its window closes', function (): void {
    $menus = quietMenus();
    $this->opened[] = $window = quietWindow($menus, MenuBar::make(Menu::make('File', 'file', Item::make('Save', id: 'save')->shortcut(Shortcut::primary('S')))));
    $save = barOf()->detailed(uuidOf(barOf(), 'File', 'Save'));
    expect(gtkApplication()->getAccelsForAction($save))->not->toBe([]);

    $window->close();

    expect(gtkApplication()->getAccelsForAction($save))->toBe([]);
});

it('fills GTK\'s About dialog with the fields given', function (): void {
    [, , $session] = quietMenus();

    $box = $session->aboutDialog(new AboutPanel('My App', '1.2', '2026 Me', 'Thanks'));
    expect([$box->getProgramName(), $box->getVersion(), $box->getCopyright(), $box->getComments()])->toBe(['My App', '1.2', '2026 Me', 'Thanks'])
        ->and($session->aboutBox())->toBe($box);

    $again = $session->aboutDialog(new AboutPanel('My App'));
    expect($again)->toBe($box)->and([$box->getVersion(), $box->getCopyright()])->toBe([null, null]);

    $box->destroy();    // GTK forgets a dialog that was shown when the user closes it; this one never was
});

it('never put anything on screen', function (): void {
    expect(MAC ? NSApplication::sharedApplication()->isActive() : false)->toBeFalse()
        ->and(MAC ? NSApplication::sharedApplication()->keyWindow() : null)->toBeNull();
});

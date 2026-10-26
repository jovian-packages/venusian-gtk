<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use Surface\Windows\Menus\Accelerator;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\Mail\QuitRequested;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The accelerator GTK reports for a menu hotkey: Command (Meta) on macOS, Control elsewhere. */
function hotkey(string $key): string
{
    return (PHP_OS_FAMILY === 'Darwin' ? '<Meta>' : '<Control>').$key;
}

it('builds the profile into a model with sections, actions and accelerators', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($bar->model()->getNItems())->toBe(2)
        ->and($bar->actions()->listActions())->toEqualCanonicalizing(['app', 'app.about', 'app.quit', 'view', 'grid', 'view.refresh', 'view.next', 'view.tab', 'view.erase', 'view.locked', 'view.locked.item'])
        ->and($bar->action('grid')->getState()->getBoolean())->toBeFalse()
        ->and(session()->application()->getAccelsForAction(barScope().'.app.quit'))->toBe([hotkey('q')])
        ->and(session()->application()->getAccelsForAction(barScope().'.view.refresh'))->toBe(['<Shift>'.hotkey('F5')])
        ->and(session()->application()->getAccelsForAction(barScope().'.view.next'))->toBe(['<Alt>Down'])
        ->and(session()->application()->getAccelsForAction(barScope().'.view.tab'))->toBe([hotkey('bracketright')]);
});

it('puts the bar where the OS keeps menus', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'))->present();
    requireActive($window);

    if (GTKBridgeDriver::appWideBar()) {
        expect(session()->application()->getMenubar())->toBe($window->menuBar()->model())
            ->and($window->native()->getShowMenubar())->toBeTrue();
    } else {
        expect($window->contentArea()->getParent()->getParent())->toBe($window->native());
    }
});

it('posts MenuActivated for an action item', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window, 'view.refresh');

    expect(takeMail(session()))->toEqual([new MenuActivated('main', 'view.refresh')]);
});

it('flips a toggle and posts MenuToggled with the new state', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window, 'grid');
    expect($window->isToggled('grid'))->toBeTrue();

    choose($window, 'grid');
    expect($window->isToggled('grid'))->toBeFalse()
        ->and(takeMail(session()))->toEqual([new MenuToggled('main', 'grid', true), new MenuToggled('main', 'grid', false)]);
});

it('sets a toggle without posting', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    $window->setToggle('grid', true);

    expect($window->isToggled('grid'))->toBeTrue()
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setToggle('view.refresh', true))->toThrow(WindowException::class, 'No toggle item');
});

it('shows About with the configured identity for the About item and posts nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window, 'app.about');
    $dialog = session()->aboutDialog();

    expect(takeMail(session()))->toBe([])
        ->and($dialog->getProgramName())->toBe('venusian-gtk tests')
        ->and($dialog->getVersion())->toBe('0.10.0')
        ->and($dialog->getCopyright())->toBeNull()
        ->and($dialog->getModal())->toBeFalse()
        ->and($dialog->getTransientFor())->toBe($window->native())
        ->and($dialog->getVisible())->toBeTrue();

    $dialog->close();
    expect(session()->aboutDialog())->toBeNull();
});

it('keeps one About dialog while it is open and builds a new one after close', function (): void {
    $first = session()->showAbout(['name' => 'Probe', 'version' => '1.2.3'], null);
    $again = session()->showAbout(['name' => 'Probe', 'version' => '1.2.4'], null);

    expect($again)->toBe($first)
        ->and($again->getVersion())->toBe('1.2.4')
        ->and($again->getTransientFor())->toBeNull()
        ->and($again->getVisible())->toBeTrue();

    $again->close();
    $fresh = session()->showAbout(['name' => 'Probe'], null);

    expect($fresh)->not->toBe($first)
        ->and(session()->aboutDialog())->toBe($fresh);

    $fresh->close();
});

it('posts QuitRequested for Quit and quits nothing', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));

    choose($window, 'app.quit');

    expect(takeMail(session()))->toEqual([new QuitRequested('main')])
        ->and($window->isOpen())->toBeTrue()
        ->and(session()->connected())->toBeTrue();
});

it('swaps the bar by profile name', function (): void {
    $window = driver()->open('main', 320, 200);

    expect($window->menuBar())->toBeNull()
        ->and(fn () => $window->isToggled('grid'))->toThrow(WindowException::class, 'no menu bar');

    $window->setMenuBar('main');
    $window->setMenuBar('tools');
    choose($window, 'tools.measure');

    expect($window->menuBar()->actions()->listActions())->toEqualCanonicalizing(['tools', 'tools.measure'])
        ->and(takeMail(session()))->toEqual([new MenuActivated('main', 'tools.measure')])
        ->and(fn () => $window->setMenuBar('nope'))->toThrow(WindowException::class, "No menu profile named 'nope'");
});

it('shows the default bar on macOS when no window is active, and its items post for no window', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $app = session()->application();

    expect($app->getMenubar())->toBe(driver()->defaultMenuBar()->model())
        ->and($app->getAccelsForAction('app.app.quit'))->toBe([hotkey('q')]);

    $app->activateAction('app.quit', null);
    $app->activateAction('view.refresh', null);
    $app->activateAction('grid', null);

    expect(takeMail(session()))->toEqual([new QuitRequested(null), new MenuActivated('', 'view.refresh'), new MenuToggled('', 'grid', true)]);

    driver()->setDefaultMenuBar(driver()->profile('tools'));

    expect($app->getMenubar())->toBe(driver()->defaultMenuBar()->model())
        ->and($app->hasAction('app.quit'))->toBeFalse()
        ->and($app->hasAction('tools.measure'))->toBeTrue()
        ->and($app->getAccelsForAction('app.app.quit'))->toBe([]);
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('shows the default bar on macOS while a window without a bar is active', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $app = session()->application();

    $tools = driver()->open('tools', 320, 200, driver()->profile('tools'))->present();
    requireActive($tools);

    expect($tools->isActive())->toBeTrue()
        ->and($app->getMenubar())->toBe($tools->menuBar()->model())
        ->and($app->getAccelsForAction('app.app.quit'))->toBe([]);

    $plain = driver()->open('plain', 320, 200)->present();
    requireActive($plain);

    expect($plain->isActive())->toBeTrue()
        ->and($app->getMenubar())->toBe(driver()->defaultMenuBar()->model())
        ->and($app->getAccelsForAction('app.app.quit'))->toBe([hotkey('q')]);
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('names app actions in a window\'s bar on macOS, held by the app while the window is active: the app bar shows their states', function (): void {
    // GTK's macOS exporter reads item states through the application's actions only.
    $app = session()->application();
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $window = driver()->open('main', 320, 200, driver()->profile('choices'))->present();
    requireActive($window);
    $bar = $window->menuBar();
    [$section, $index] = $bar->place('case.all');
    $window->setRadio('ignore-case', 'case.all');

    expect($section->getItemAttributeValue($index, 'action', 's')->getString())->toBe('app.case.all')
        ->and($app->lookupAction('case.all'))->toBe($bar->action('case.all'))
        ->and($app->lookupAction('case.all')->getState()->getString())->toBe('case.all')
        ->and($app->hasAction('view.refresh'))->toBeFalse();

    $plain = driver()->open('plain', 320, 200)->present();
    requireActive($plain);

    expect($app->getMenubar())->toBe(driver()->defaultMenuBar()->model())
        ->and($app->hasAction('case.all'))->toBeFalse()
        ->and($app->lookupAction('view.refresh'))->toBe(driver()->defaultMenuBar()->action('view.refresh'));

    $window->present();
    requireActive($window);

    expect($app->lookupAction('case.all'))->toBe($bar->action('case.all'))
        ->and($app->hasAction('view.refresh'))->toBeFalse();
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

/** The titles in the native app bar's top-level menu called $title, read through ext-appkit. */
function nativeMenuTitles(string $title): array
{
    $main = NSApplication::sharedApplication()->mainMenu();
    for ($i = 0; $i < ($main?->numberOfItems() ?? 0); $i++) {
        if ($main->itemAtIndex($i)->title() === $title && ! is_null($menu = $main->itemAtIndex($i)->submenu())) {
            return array_map(fn (int $j): string => $menu->itemAtIndex($j)->title(), range(0, $menu->numberOfItems() - 1));
        }
    }

    return [];
}

it('relabels in the macOS app bar by rebuilding it: macOS adds items of its own that GTK\'s exporter does not count', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'))->present();
    requireActive($window);
    pumpFor(0.1);
    $main = NSApplication::sharedApplication()->mainMenu();
    for ($i = 0; $i < $main->numberOfItems(); $i++) {
        if ($main->itemAtIndex($i)->title() === 'Compare') {
            // As macOS puts Show Tab Bar and Show All Tabs at the top of a View menu.
            $main->itemAtIndex($i)->submenu()->insertItemAtIndex(NSMenuItem::separatorItem(), 0);
        }
    }

    $window->setItemLabel('compare.wrap', 'Wrapping');
    pumpFor(0.1);
    $titles = nativeMenuTitles('Compare');

    expect($titles)->toContain('None')
        ->and($titles)->toContain('Unimportant')
        ->and($titles)->toContain('All')
        ->and($titles)->toContain('Wrapping')
        ->and($titles)->not->toContain('Wrap');
})->skip(! GTKBridgeDriver::appWideBar() || ! class_exists('NSApplication'), 'the macOS app bar, read through ext-appkit');

it('keeps the default bar built when the same profile is set again', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = driver()->defaultMenuBar();

    driver()->setDefaultMenuBar(driver()->profile('main'));

    expect(driver()->defaultMenuBar())->toBe($bar)
        ->and(session()->application()->getMenubar())->toBe($bar->model())
        ->and(session()->application()->hasAction('view.refresh'))->toBeTrue();
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

/**
 * The bar widget, with the bar's actions reachable under the scope its items name. On Linux the
 * window holds the widget and gives it them; on macOS the bar lives in the app bar and the widget
 * is in no window.
 */
function barWidget(GTKMenuBar $bar): GtkPopoverMenuBar
{
    $bar->widget()->insertActionGroup(barScope(), $bar->actions());

    return $bar->widget();
}

it('writes each accelerator as a GTK accelerator string that GTK parses', function (string $keys, bool $macos, string $gtk): void {
    $app = session()->application();
    $app->setAccelsForAction('app.c5-probe', [GTKMenuBar::accelerator(Accelerator::parse($keys), $macos)]);

    expect(GTKMenuBar::accelerator(Accelerator::parse($keys), $macos))->toBe($gtk)
        ->and($app->getAccelsForAction('app.c5-probe'))->toHaveCount(1);
    $app->setAccelsForAction('app.c5-probe', []);
})->with([
    'letter' => ['Primary+A', false, '<Control>a'],
    'digit' => ['Primary+5', false, '<Control>5'],
    'minus' => ['Primary+-', false, '<Control>minus'],
    'equals' => ['Primary+=', false, '<Control>equal'],
    'left bracket' => ['Primary+[', false, '<Control>bracketleft'],
    'right bracket' => ['Primary+]', false, '<Control>bracketright'],
    'backslash' => ['Primary+\\', false, '<Control>backslash'],
    'semicolon' => ['Primary+;', false, '<Control>semicolon'],
    'apostrophe' => ["Primary+'", false, '<Control>apostrophe'],
    'grave' => ['Primary+`', false, '<Control>grave'],
    'comma' => ['Primary+,', false, '<Control>comma'],
    'period' => ['Primary+.', false, '<Control>period'],
    'slash' => ['Primary+/', false, '<Control>slash'],
    'F12 alone' => ['F12', false, 'F12'],
    'up' => ['Alt+UP', false, '<Alt>Up'],
    'down' => ['Alt+DOWN', false, '<Alt>Down'],
    'left' => ['Alt+LEFT', false, '<Alt>Left'],
    'right' => ['Alt+RIGHT', false, '<Alt>Right'],
    'home' => ['Primary+HOME', false, '<Control>Home'],
    'end' => ['Primary+END', false, '<Control>End'],
    'page up' => ['Primary+PAGE_UP', false, '<Control>Page_Up'],
    'page down' => ['Primary+PAGE_DOWN', false, '<Control>Page_Down'],
    'space' => ['Primary+SPACE', false, '<Control>space'],
    'enter' => ['Primary+ENTER', false, '<Control>Return'],
    'tab' => ['Primary+TAB', false, '<Control>Tab'],
    'escape' => ['Primary+ESCAPE', false, '<Control>Escape'],
    'backspace' => ['Primary+BACKSPACE', false, '<Control>BackSpace'],
    'delete' => ['Primary+DELETE', false, '<Control>Delete'],
    'meta, other platforms' => ['Meta+X', false, '<Super>x'],
    'ctrl, macOS' => ['Ctrl+X', true, '<Control>x'],
    'primary and shift, macOS' => ['Primary+Shift+F5', true, '<Meta><Shift>F5'],
    'primary and shift, other platforms' => ['Primary+Shift+F5', false, '<Control><Shift>F5'],
]);

it('builds a node with enabled false disabled, and the nodes below it', function (): void {
    $bar = driver()->open('main', 320, 200, driver()->profile('main'))->menuBar();

    expect($bar->action('view.locked')->getEnabled())->toBeFalse()
        ->and($bar->action('view.locked.item')->getEnabled())->toBeFalse()
        ->and($bar->action('view.refresh')->getEnabled())->toBeTrue()
        ->and(labelIn(barWidget($bar), 'Locked')?->isSensitive())->toBeFalse();
});

it('disables a folder and the nodes below it, and restores each own state when it is enabled again', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($window->setItemEnabled('view', false))->toBe($window)
        ->and($bar->action('view')->getEnabled())->toBeFalse()
        ->and($bar->action('view.refresh')->getEnabled())->toBeFalse()
        ->and($bar->action('view.locked.item')->getEnabled())->toBeFalse()
        ->and($window->isItemEnabled('view'))->toBeFalse()
        ->and($window->isItemEnabled('view.refresh'))->toBeTrue();

    $window->setItemEnabled('view', true);

    expect($bar->action('view.refresh')->getEnabled())->toBeTrue()
        ->and($bar->action('view.locked.item')->getEnabled())->toBeFalse()
        ->and(takeMail(session()))->toBe([]);
});

it('disables a top-level folder in the bar widget, which GTK does not do from the action', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();
    $view = $bar->widget()->getFirstChild()->getNextSibling();

    $window->setItemEnabled('view', false);
    $off = $view->getSensitive();
    $window->setItemEnabled('view', true);

    expect($bar->widget())->toBe($bar->widget())
        ->and($off)->toBeFalse()
        ->and($view->getSensitive())->toBeTrue();
});

it('shows a nested folder as disabled from its action, in the bar widget', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    $window->setItemEnabled('view.locked', true);
    $on = labelIn(barWidget($bar), 'Locked')->isSensitive();
    $window->setItemEnabled('view.locked', false);

    expect($on)->toBeTrue()
        ->and(labelIn(barWidget($bar), 'Locked')->isSensitive())->toBeFalse();
});

it('refuses an unknown id, an edit role and the first folder', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('editing'));

    expect(fn () => $window->setItemEnabled('nope', false))->toThrow(WindowException::class, "'nope'")
        ->and(fn () => $window->setItemEnabled('edit.copy', false))->toThrow(WindowException::class, "'Copy'")
        ->and(fn () => $window->setItemEnabled('app', false))->toThrow(WindowException::class, "'App'")
        ->and(fn () => driver()->open('bare', 320, 200)->setItemEnabled('view', false))->toThrow(WindowException::class, 'no menu bar');
});

it('starts a new bar from its own profile', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $window->setItemEnabled('view.refresh', false);

    $window->setMenuBar('main');

    expect($window->isItemEnabled('view.refresh'))->toBeTrue()
        ->and($window->menuBar()->action('view.refresh')->getEnabled())->toBeTrue();
});

it('keeps a state of the default bar set before the bar is built', function (): void {
    driver()->setDefaultMenuBar(null);
    $active = driver()->open('active', 320, 200, driver()->profile('tools'))->present();
    requireActive($active);
    driver()->setDefaultMenuBar(driver()->profile('main'));

    driver()->setItemEnabled('view.refresh', false);
    driver()->closeAll();
    pumpFor(0.05);
    driver()->showDefaultMenuBar();

    expect(driver()->isItemEnabled('view.refresh'))->toBeFalse()
        ->and(driver()->defaultMenuBar()->action('view.refresh')->getEnabled())->toBeFalse();
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

it('refuses a default bar state with no default profile', function (): void {
    driver()->setDefaultMenuBar(null);

    expect(fn () => driver()->setItemEnabled('view.refresh', false))->toThrow(WindowException::class, 'default');
});

/** Each radio item's action state: the group's chosen id, on every item. */
function caseStates(GTKMenuBar $bar): array
{
    return array_map(fn (string $id): string => $bar->action($id)->getState()->getString(), ['case.none' => 'case.none', 'case.unimportant' => 'case.unimportant', 'case.all' => 'case.all']);
}

/** The label a node's GMenuItem carries in its section. */
function modelLabel(GTKMenuBar $bar, string $id): string
{
    [$section, $index] = $bar->place($id);

    return $section->getItemAttributeValue($index, 'label', 's')->getString();
}

it('gives each radio item a string action whose state is the group\'s chosen id, and sets it without posting', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));
    [$section, $index] = $window->menuBar()->place('case.unimportant');

    expect(caseStates($window->menuBar()))->toBe(['case.none' => 'case.none', 'case.unimportant' => 'case.none', 'case.all' => 'case.none'])
        ->and($window->menuBar()->action('case.unimportant')->getParameterType())->toBe('s')
        ->and($section->getItemAttributeValue($index, 'target', 's')->getString())->toBe('case.unimportant');

    $window->setRadio('ignore-case', 'case.all');

    expect(caseStates($window->menuBar()))->toBe(['case.none' => 'case.all', 'case.unimportant' => 'case.all', 'case.all' => 'case.all'])
        ->and($window->radio('ignore-case'))->toBe('case.all')
        ->and(takeMail(session()))->toBe([])
        ->and(fn () => $window->setRadio('ignore-case', 'compare.wrap'))->toThrow(WindowException::class, "'compare.wrap' is not in radio group 'ignore-case'.");
});

it('posts MenuRadioChosen for the user\'s choice, and nothing for the item already on', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    $window->native()->activateActionVariant('win.case.unimportant', GVariant::newString('case.unimportant'));
    $window->native()->activateActionVariant('win.case.unimportant', GVariant::newString('case.unimportant'));

    expect(takeMail(session()))->toEqual([new MenuRadioChosen('main', 'ignore-case', 'case.unimportant')])
        ->and($window->radio('ignore-case'))->toBe('case.unimportant')
        ->and(caseStates($window->menuBar())['case.all'])->toBe('case.unimportant');
});

it('disables one radio item on its own, and relabels an item and a folder in the model', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('choices'));

    $window->setItemEnabled('case.all', false);
    $window->setItemLabel('case.none', 'Nothing');
    $window->setItemLabel('compare', 'Diff');
    $window->setItemLabel('compare.more', 'Further');

    expect($window->menuBar()->action('case.all')->getEnabled())->toBeFalse()
        ->and($window->menuBar()->action('case.none')->getEnabled())->toBeTrue()
        ->and(modelLabel($window->menuBar(), 'case.none'))->toBe('Nothing')
        ->and(modelLabel($window->menuBar(), 'compare'))->toBe('Diff')
        ->and(modelLabel($window->menuBar(), 'compare.more'))->toBe('Further')
        ->and($window->itemLabel('compare'))->toBe('Diff')
        ->and($window->menuBar()->model()->getNItems())->toBe(2)
        ->and(takeMail(session()))->toBe([]);

    // The relabelled item keeps its action, target and radio state.
    $window->native()->activateActionVariant('win.case.none', GVariant::newString('case.none'));
    $window->setRadio('ignore-case', 'case.unimportant');
    expect(caseStates($window->menuBar())['case.none'])->toBe('case.unimportant');
});

it('keeps the default bar\'s radios, toggles and labels in the driver, and in the bar where one shows', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('choices'));
    driver()->setRadio('ignore-case', 'case.all');
    driver()->setToggle('compare.wrap', true);
    driver()->setItemLabel('compare.wrap', 'Wrap Lines');

    expect(driver()->radio('ignore-case'))->toBe('case.all')
        ->and(driver()->isToggled('compare.wrap'))->toBeTrue()
        ->and(driver()->itemLabel('compare.wrap'))->toBe('Wrap Lines');
    if (GTKBridgeDriver::appWideBar()) {
        driver()->showDefaultMenuBar();
        expect(caseStates(driver()->defaultMenuBar())['case.none'])->toBe('case.all')
            ->and(driver()->defaultMenuBar()->isToggled('compare.wrap'))->toBeTrue()
            ->and(modelLabel(driver()->defaultMenuBar(), 'compare.wrap'))->toBe('Wrap Lines');
    }
    driver()->setDefaultMenuBar(driver()->profile('main'));
});

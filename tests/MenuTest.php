<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Surface\Contracts\Windows\Mail\MenuActivated;
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

/** Activate an item the way its menu does: through the window's "win" action. */
function choose(Jovian\Toolkits\GTK\Windows\GTKWindow $window, string $id): void
{
    expect($window->native()->activateAction("win.{$id}", null))->toBeTrue();
}

it('builds the profile into a model with sections, actions and accelerators', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('main'));
    $bar = $window->menuBar();

    expect($bar->model()->getNItems())->toBe(2)
        ->and($bar->actions()->listActions())->toEqualCanonicalizing(['app.about', 'app.quit', 'grid', 'view.refresh'])
        ->and($bar->action('grid')->getState()->getBoolean())->toBeFalse()
        ->and(session()->application()->getAccelsForAction('win.app.quit'))->toBe([hotkey('q')])
        ->and(session()->application()->getAccelsForAction('win.view.refresh'))->toBe([hotkey('r')]);
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

    expect($window->menuBar()->actions()->listActions())->toBe(['tools.measure'])
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

it('keeps the default bar built when the same profile is set again', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('tools'));
    driver()->setDefaultMenuBar(driver()->profile('main'));
    $bar = driver()->defaultMenuBar();

    driver()->setDefaultMenuBar(driver()->profile('main'));

    expect(driver()->defaultMenuBar())->toBe($bar)
        ->and(session()->application()->getMenubar())->toBe($bar->model())
        ->and(session()->application()->hasAction('view.refresh'))->toBeTrue();
})->skip(! GTKBridgeDriver::appWideBar(), 'Linux keeps menus inside windows');

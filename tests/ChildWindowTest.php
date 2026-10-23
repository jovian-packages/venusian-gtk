<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    driver()->setDefaultMenuBar(driver()->profile('main'));
    pumpFor(0.05);
    takeMail(session());
});

/** The WindowClosed and WindowCloseRequested mail the session holds, as 'closed:<name>' / 'requested:<name>', in order. */
function closeMail(): array
{
    return array_values(array_map(
        fn (object $mail): string => ($mail instanceof WindowCloseRequested ? 'requested:' : 'closed:').$mail->window,
        array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowClosed || $mail instanceof WindowCloseRequested),
    ));
}

it('makes a child transient and modal, in its parent window group', function (): void {
    $main = driver()->open('main', 480, 320);
    $other = driver()->open('other', 200, 100);
    $child = driver()->open('child', 300, 120, null, $main);

    expect($child->native()->getTransientFor())->toBe($main->native())
        ->and($child->native()->getModal())->toBeTrue()
        ->and($child->native()->getGroup())->toBe($main->native()->getGroup())
        ->and($other->native()->getGroup())->not->toBe($main->native()->getGroup())
        ->and($child->parent())->toBe($main)
        ->and($main->parent())->toBeNull();
});

it('presents the parent first when the parent is not shown', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main)->present();
    pumpFor(0.2);

    expect($main->native()->getVisible())->toBeTrue();
});

it('gives the parent its input again when the child closes', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();
    pumpFor(0.2);
    $native = $child->native();
    expect($native->getMapped())->toBeTrue();

    $child->close();
    pumpFor(0.1);

    // GTK 4 keeps the group grab private; it removes a modal window's grab when the window unmaps
    // (gtkwindow.c, gtk_window_unmap). The child's native is unmapped, so its grab is gone, and the
    // driver's own refusal of the parent's user close (an open child) is gone too.
    expect($native->getMapped())->toBeFalse();
    $main->native()->close();
    pumpFor(0.1);

    expect(closeMail())->toBe(['closed:child', 'closed:main']);
});

it('refuses the user close of a parent that has an open child, presented or not', function (bool $presented): void {
    $main = driver()->open('main', 480, 320);
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    if ($presented) {
        $child->present();
    }
    pumpFor(0.2);
    closeMail();

    $main->native()->close();
    pumpFor(0.1);

    expect(closeMail())->toBe([])
        ->and($main->isOpen())->toBeTrue()
        ->and($child->isOpen())->toBeTrue();
})->with(['not presented' => false, 'presented' => true]);

it('refuses a menu bar for a child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => $child->setMenuBar('main'))->toThrow(WindowException::class, "Window 'child' is a child, so it cannot have a menu bar.");
});

it('closes the child first when the parent closes, with no close request from a confirming child', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main)->confirmClose();
    $child->present();
    pumpFor(0.2);
    $main->close();
    pumpFor(0.1);

    expect(closeMail())->toBe(['closed:child', 'closed:main'])
        ->and($child->isOpen())->toBeFalse();
});

it('refuses a child as parent, a parent with an open child, and a menu', function (): void {
    $main = driver()->open('main', 480, 320);
    $child = driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->open('grandchild', 100, 100, null, $child))->toThrow(WindowException::class, "'child' is a child")
        ->and(fn () => driver()->open('second', 100, 100, null, $main))->toThrow(WindowException::class, "already has the open child 'child'")
        ->and(fn () => driver()->open('menu', 100, 100, driver()->profile('tools'), driver()->open('other', 100, 100)))->toThrow(WindowException::class, 'cannot have a menu bar');
});

it('posts nothing for open with a parent, present and confirmClose', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main)->confirmClose()->present();
    pumpFor(0.2);

    expect(closeMail())->toBe([]);
});

it('blocks every item of the default bar but the edit roles while a child is active (macOS)', function (): void {
    driver()->setDefaultMenuBar(driver()->profile('editing'));
    $main = driver()->open('main', 480, 320, driver()->profile('tools'));
    $main->present();
    $child = driver()->open('child', 300, 120, null, $main);
    $child->present();
    requireActive($child);
    $app = session()->application();

    expect($app->lookupAction('edit.copy')->getEnabled())->toBeTrue()
        ->and($app->lookupAction('edit.find')->getEnabled())->toBeFalse()
        ->and($app->lookupAction('app.quit')->getEnabled())->toBeFalse();

    $child->close();
    $main->close();
    pumpFor(0.2);
    driver()->installDefaultMenuBar();

    expect($app->lookupAction('edit.find')->getEnabled())->toBeTrue()
        ->and($app->lookupAction('app.quit')->getEnabled())->toBeTrue();
})->skip(! GTKBridgeDriver::appWideBar(), 'The default bar shows only in the app-wide bar (macOS).');

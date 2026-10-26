<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowFocused;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('opens a titled window under its name', function (): void {
    $window = driver()->open('main', 480, 320);

    expect($window)->toBeInstanceOf(GTKWindow::class)
        ->and($window->name())->toBe('main')
        ->and($window->title())->toBe('main')
        ->and($window->isOpen())->toBeTrue()
        ->and($window->native())->toBeInstanceOf(GtkApplicationWindow::class)
        ->and($window->native()->getDefaultSize())->toBe([480, 320])
        ->and($window->contentArea()->getParent()?->getParent())->toBe($window->native())
        ->and(session()->application()->getWindows())->toContain($window->native())
        ->and(driver()->get('main'))->toBe($window)
        ->and(driver()->all())->toBe(['main' => $window]);

    $window->setTitle('Main Window');
    expect($window->title())->toBe('Main Window');
});

it('refuses a second window under the same name', function (): void {
    driver()->open('main', 100, 100);

    expect(fn () => driver()->open('main', 100, 100))->toThrow(WindowException::class, 'already open');
});

it('posts WindowFocused when presented and active', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    requireActive($window);

    expect(takeMail(session()))->toContainEqual(new WindowFocused('main'));
});

it('posts no WindowFocused while its own popover opens and closes', function (): void {
    // On X11 a popover's grab makes the window inactive and active again in one dispatch.
    $window = driver()->open('main', 320, 200);
    $combo = $window->column('m')->comboBox('path', '/tmp', ['/tmp', '/']);
    $window->present();
    requireActive($window);
    pumpFor(0.1);
    takeMail(session());

    $combo->optionsButton()->popup();
    pumpUntil(fn (): bool => $combo->optionsButton()->getPopover()->getMapped());
    $combo->optionsButton()->popdown();
    // GTK's tooltip hover timer (500 ms) starts on the popover's crossing and outlives a closed window.
    pumpFor(0.6);

    expect(takeMail(session()))->not->toContainEqual(new WindowFocused('main'))
        ->and($window->isActive())->toBeTrue();
    $window->close();
});

it('posts WindowFocused again when it comes back from another window', function (): void {
    $main = driver()->open('main', 320, 200)->present();
    requireActive($main);
    $other = driver()->open('other', 200, 100)->present();
    requireActive($other);
    pumpFor(0.1);
    takeMail(session());

    $main->present();
    requireActive($main);
    pumpFor(0.1);

    expect(takeMail(session()))->toContainEqual(new WindowFocused('main'));
    $other->close();
    $main->close();
});

it('closes once, posts WindowClosed once, and is gone afterwards', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());

    $window->close();
    $window->close();
    pumpFor(0.05);

    expect(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse()
        ->and(driver()->has('main'))->toBeFalse()
        ->and(fn () => $window->title())->toThrow(WindowException::class, 'closed');
});

it('keeps a confirming window open on the user close and posts WindowCloseRequested', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());
    $closing = fn (): array => array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed || $m instanceof WindowCloseRequested));

    expect($window->confirmClose())->toBe($window)
        ->and($window->isConfirmingClose())->toBeTrue();

    $window->native()->close();
    pumpFor(0.05);
    expect($closing())->toEqual([new WindowCloseRequested('main')])
        ->and($window->isOpen())->toBeTrue();

    $window->close();
    expect($closing())->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse();
});

it('closes on the user close when it does not confirm close', function (): void {
    $window = driver()->open('main', 320, 200)->present();
    pumpFor(0.1);
    takeMail(session());
    $window->native()->close();

    expect(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('main')])
        ->and($window->isOpen())->toBeFalse();
});

it('closes every window', function (): void {
    driver()->open('a', 100, 100);
    driver()->open('b', 100, 100);

    driver()->closeAll();

    expect(driver()->all())->toBe([])
        ->and(array_values(array_filter(takeMail(session()), fn ($m) => $m instanceof WindowClosed)))->toEqual([new WindowClosed('a'), new WindowClosed('b')]);
});

it('posts WindowScaleChanged when the surface scale changes, and nothing for the same scale', function (): void {
    $window = driver()->open('main', 200, 100);
    $window->present();
    pumpUntil(fn (): bool => ! is_null($window->native()->getSurface()));
    takeMail(session());

    // One display here: the handler runs with the scale unchanged, then with the last one posted set off by hand.
    $window->scaleChanged();
    $same = array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof Surface\Contracts\Windows\Mail\WindowScaleChanged);
    (fn () => $this->scale = 0.5)->call($window);
    $window->scaleChanged();

    expect($same)->toBe([])
        ->and(array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof Surface\Contracts\Windows\Mail\WindowScaleChanged)))
        ->toEqual([new Surface\Contracts\Windows\Mail\WindowScaleChanged('main', $window->native()->getSurface()->getScale())]);
    $window->close();
});

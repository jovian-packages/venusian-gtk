<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Bridge\GTKBridgeDriver;
use Surface\Contracts\Windows\Mail\View\ViewResized;
use Surface\Contracts\Windows\Mail\WindowResized;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<ViewResized> */
function viewResized(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof ViewResized));
}

it('posts ViewResized only for watched views, coalesced', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $watched = $main->label('w', 'W')->fill(vertical: false)->watchSize();
    $silent = $main->label('s', 'S')->fill(vertical: false);
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    // Sizes stay within 480 px: the Pi's panel is 480 wide and its compositor caps windows there.
    $window->native()->setDefaultSize(440, 300);
    $window->native()->setDefaultSize(460, 300);
    pumpFor(0.3);

    $resized = viewResized();
    expect($resized)->toHaveCount(1)
        ->and($resized[0]->path())->toBe('m.w')
        ->and($resized[0]->uuid())->toBe($watched->uuid())
        ->and($resized[0]->width)->toBe($watched->size()[0])
        ->and($resized[0]->width)->toBe(460)
        ->and($silent->size()[0])->toBe($watched->size()[0]);

    $watched->watchSize(false);
    $window->native()->setDefaultSize(420, 300);
    pumpFor(0.3);
    expect(viewResized())->toBe([])
        ->and($watched->size()[0])->toBe(420);
});

it('keeps watched views of windows apart even when window names hold dots', function (): void {
    $dotted = driver()->open('a.b', 300, 200);
    $plain = driver()->open('a', 300, 200);
    $one = $dotted->column('c')->fill()->watchSize();
    $two = $plain->column('b')->column('c')->fill()->watchSize();
    $dotted->present();
    $plain->present();
    pumpFor(0.2);
    takeMail(session());
    $dotted->native()->setDefaultSize(320, 220);
    $plain->native()->setDefaultSize(330, 230);
    pumpFor(0.3);

    $resized = viewResized();
    expect(array_map(fn (ViewResized $mail): string => $mail->uuid(), $resized))->toEqualCanonicalizing([$one->uuid(), $two->uuid()]);
});

it('posts WindowResized when an in-window menu bar takes room from the content area', function (): void {
    $window = driver()->open('main', 400, 300)->present();
    pumpFor(0.2);
    takeMail(session());
    $window->setMenuBar('main');
    pumpFor(0.3);

    $resized = array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowResized));
    expect($resized)->toEqual([new WindowResized('main', ...$window->size())])
        ->and($window->size()[1])->toBeLessThan(300);
})->skip(GTKBridgeDriver::appWideBar(), 'macOS keeps the menu bar outside the window');

it('reports nothing for a watched view until its size changes', function (): void {
    $window = driver()->open('main', 400, 300);
    $label = $window->column('m')->label('w', 'W');
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $label->watchSize();
    pumpFor(0.2);

    expect(viewResized())->toBe([]);
});

it('forgets a watched view removed with its container: no mail, no path, no uuid', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('m');
    $row = $main->row('r');
    $watched = $row->label('w', 'W')->fill(vertical: false)->watchSize();
    $window->present();
    pumpFor(0.2);
    takeMail(session());
    $uuid = $watched->uuid();

    $row->remove();
    $window->native()->setDefaultSize(440, 320);
    pumpFor(0.3);

    expect(viewResized())->toBe([])
        ->and($watched->isWatchingSize())->toBeFalse()
        ->and($window->view('m.r.w'))->toBeNull()
        ->and($window->uuid($uuid))->toBeNull();
});

<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Mail\Views\ViewResized;
use Surface\Contracts\Toolkits\UI\Align;

/*
| GTK 4 has no resize signal for a widget: the window compares each watching view's size
| after every pump. These cover a watched view laid out at a new size, and an unwatched one.
*/

afterEach(fn () => closeAll());

/** @return list<ViewResized> */
function viewResizes(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof ViewResized));
}

/**
 * A column that fills the window, the view a resize reaches. The window is given time to
 * finish opening first: on macOS a frame change made while it is still opening is undone
 * when the opening ends (measured: a resize in the first 0.4 s comes back to the opening size
 * about 0.1 s later; one at 0.5 s holds), so a resize from the window system is played after.
 */
function framedWindow(): array
{
    $window = openWindow('main', 400, 300);
    $frame = $window->column('root')->column('frame')->fill();
    pumpFor(0.6);
    takeMail();

    return [$window, $frame];
}

it('posts the size a watched view is laid out at, and the next when the window resizes', function (): void {
    [$window, $frame] = framedWindow();

    $frame->watchSize();
    $first = viewResizes(mailUntil('ViewResized'));
    expect($first)->toHaveCount(1)
        ->and([$first[0]->window, $first[0]->path, $first[0]->uuid])->toBe(['main', $frame->path(), $frame->uuid()])
        ->and([$first[0]->width, $first[0]->height])->toBe($frame->size());

    nativeResize($window, 520, 410);
    $laidOut = pumpUntil(fn (): bool => $frame->size() === [520, 410]);
    pumpFor(0.2);
    $posted = viewResizes(takeMail());

    expect($laidOut)->toBeTrue()
        ->and($posted)->not->toBeEmpty()
        ->and([end($posted)->width, end($posted)->height])->toBe([520, 410]);
});

it('posts a size once, not again while the view stays as it is', function (): void {
    [, $frame] = framedWindow();
    $frame->watchSize();
    mailUntil('ViewResized');

    pumpFor(0.3);

    expect(viewResizes(takeMail()))->toBe([]);
});

it('posts nothing for a view that is not watched, nor after it stops being watched', function (): void {
    [$window, $frame] = framedWindow();

    nativeResize($window, 520, 410);
    pumpFor(0.4);
    expect(viewResizes(takeMail()))->toBe([]);

    $frame->watchSize();
    mailUntil('ViewResized');
    $frame->watchSize(false);
    nativeResize($window, 460, 350);
    pumpFor(0.4);

    expect(viewResizes(takeMail()))->toBe([])
        ->and($frame->size())->toBe([460, 350]);
});

it('posts the size of a view that is not the window\'s content, with a size of its own', function (): void {
    [, $frame] = framedWindow();
    $chip = $frame->column('chip');
    $chip->align(Align::START, Align::START)->minSize(100, 20);
    pumpFor(0.2);
    takeMail();

    $chip->watchSize();
    $posted = viewResizes(mailUntil('ViewResized'));
    $chip->minSize(140, 30);
    $later = viewResizes(mailUntil('ViewResized'));
    pumpFor(0.2);
    $later = [...$later, ...viewResizes(takeMail())];

    expect([$posted[0]->width, $posted[0]->height])->toBe([100, 20])
        ->and([end($later)->width, end($later)->height])->toBe([140, 30]);
});

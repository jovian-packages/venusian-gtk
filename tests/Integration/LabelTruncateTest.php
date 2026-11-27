<?php

declare(strict_types=1);

use GtkOrientation;

/*
| A label that truncates is Pango's ellipsis at the end: its minimum width is the ellipsis
| alone, so a long text neither widens the window nor its column, and it is one line.
*/

const TRUNCATE_LONG = 'A title far longer than the narrow window it is shown in, so it must be cut';

afterEach(fn () => closeAll());

/** The label's minimum width, as GTK measures it. */
function minimumWidth(object $label): int
{
    return $label->native()->measure(GtkOrientation::HORIZONTAL, -1)['minimum'];
}

it('cuts a long title at the window\'s width instead of widening it', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setTruncate(true);
    pumpFor(0.4);

    expect($window->size()[0])->toBe(200)
        ->and($label->native()->getWidth())->toBeLessThanOrEqual(200)
        ->and(minimumWidth($label))->toBeLessThan(50)
        ->and($label->native()->getWrap())->toBeFalse();
});

it('wins over wrap, and gives the wrap back when it stops', function (): void {
    $window = openWindow('main', 200, 300);
    $label = $window->column('root')->label('title', TRUNCATE_LONG)->setWrap(true)->setTruncate(true);
    pumpFor(0.4);
    expect($label->native()->getWrap())->toBeFalse();
    $oneLine = $label->native()->getHeight();

    $label->setTruncate(false);
    pumpFor(0.4);

    expect($label->native()->getWrap())->toBeTrue()
        ->and($label->native()->getHeight())->toBeGreaterThan($oneLine * 2)
        ->and($window->size()[0])->toBe(200);
});

it('needs the cut: whole and unwrapped, the text asks for more than the window', function (): void {
    $window = openWindow('main', 200, 120);
    $label = $window->column('root')->label('title', TRUNCATE_LONG);

    expect(minimumWidth($label))->toBeGreaterThan(200);
});

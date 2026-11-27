<?php

declare(strict_types=1);

use GrapheneRect;

/*
| A flow lays its children out side by side at their natural size and wraps them onto the next
| line where the width runs out: 100x20 chips in a 400-wide window, three to a line at most.
*/

afterEach(fn () => closeAll());

/** A flow of $count chips, each 100x20, in a window $width wide, filling across. */
function chips(int $count, int $width = 400, int $spacing = 10, int $padding = 5): array
{
    $window = openWindow('main', $width, 300);
    $flow = $window->column('root')->flow('chips', $spacing, $padding)->fill(true, false);
    $chips = [];
    for ($i = 1; $i <= $count; $i++) {
        $chips[$i] = $flow->column("chip{$i}")->minSize(100, 20);
    }
    pumpFor(0.3);

    return [$flow, $chips];
}

/** @return array{int, int} where a chip's border box starts, from the flow's content box (GTK measures from inside the padding) */
function origin(object $flow, object $chip): array
{
    $bounds = null;
    $chip->native()->computeBounds($flow->native(), $bounds);
    assert($bounds instanceof GrapheneRect);

    return [(int) round($bounds->origin->x), (int) round($bounds->origin->y)];
}

it('builds a GtkFlowBox with no selection and no cap on a line', function (): void {
    [$flow] = chips(1);
    $native = $flow->native()->getFirstChild();

    expect($native)->toBeInstanceOf(GtkFlowBox::class)
        ->and($native->getSelectionMode())->toBe(GtkSelectionMode::NONE)
        ->and($native->getHomogeneous())->toBeFalse()
        ->and($native->getMaxChildrenPerLine())->toBeGreaterThan(7);
});

it('keeps children on one line at their own size while they fit, from the left, spaced and padded', function (): void {
    [$flow, $chips] = chips(3);

    expect(origin($flow, $chips[1]))->toBe([0, 0])
        ->and(origin($flow, $chips[2]))->toBe([110, 0])
        ->and(origin($flow, $chips[3]))->toBe([220, 0])
        ->and($chips[1]->size())->toBe([100, 20])
        ->and($flow->native()->getFirstChild()->getWidth())->toBe(390)
        ->and($flow->size())->toBe([400, 30]);
});

it('wraps onto a second line where the width runs out, the height growing with the lines', function (): void {
    [$flow, $chips] = chips(4);

    expect(origin($flow, $chips[3]))->toBe([220, 0])
        ->and(origin($flow, $chips[4]))->toBe([0, 30])
        ->and($flow->size()[1])->toBe(60);
});

it('wraps at the width it has: two to a line in 230 points', function (): void {
    [$flow, $chips] = chips(4, 230);

    expect(origin($flow, $chips[2]))->toBe([110, 0])
        ->and(origin($flow, $chips[3]))->toBe([0, 30])
        ->and(origin($flow, $chips[4]))->toBe([110, 30])
        ->and($flow->size()[1])->toBe(60);
});

it('changes the gap on a line and between lines with the spacing, and the edge with the padding', function (): void {
    [$flow, $chips] = chips(4);

    $flow->setSpacing(20);
    pumpFor(0.2);
    expect(origin($flow, $chips[2]))->toBe([120, 0])
        ->and(origin($flow, $chips[4]))->toBe([0, 40])
        ->and($flow->size()[1])->toBe(70);

    $flow->setPadding([2, 4]);
    pumpFor(0.2);
    expect($flow->size()[1])->toBe(64)
        ->and($flow->native()->getFirstChild()->getWidth())->toBe(392);
});

it('gives a hidden child no room, and the room back when it is shown', function (): void {
    [$flow, $chips] = chips(3);

    $chips[2]->hide();
    pumpFor(0.2);
    expect(origin($flow, $chips[3]))->toBe([110, 0]);

    $chips[2]->show();
    pumpFor(0.2);
    expect(origin($flow, $chips[3]))->toBe([220, 0]);
});

it('keeps the display order when a child is moved', function (): void {
    [$flow, $chips] = chips(4);

    $chips[3]->moveBefore($chips[1]);
    pumpFor(0.2);

    expect(origin($flow, $chips[3]))->toBe([0, 0])
        ->and(origin($flow, $chips[1]))->toBe([110, 0])
        ->and(origin($flow, $chips[2]))->toBe([220, 0])
        ->and(origin($flow, $chips[4]))->toBe([0, 30]);
});

it('takes a removed child out of the line', function (): void {
    [$flow, $chips] = chips(4);

    $chips[2]->remove();
    pumpFor(0.2);

    expect(origin($flow, $chips[3]))->toBe([110, 0])
        ->and(origin($flow, $chips[4]))->toBe([220, 0])
        ->and($flow->size()[1])->toBe(30);
});

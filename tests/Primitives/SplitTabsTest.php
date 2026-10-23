<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKSplitView;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\TabSelected;
use Surface\Contracts\Windows\Primitives\Align;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * Where a pane's widget starts and ends along the split, in the split box's coordinates.
 *
 * @return array{float, float}
 */
function paneSpan(GTKSplitView $split, string $pane): array
{
    $box = $split->native();
    expect($split->view($pane)->native()->computeBounds($box, $bounds))->toBeTrue();

    return $split->isHorizontal()
        ? [$bounds->origin->x, $bounds->origin->x + $bounds->size->width]
        : [$bounds->origin->y, $bounds->origin->y + $bounds->size->height];
}

function splitLength(GTKSplitView $split): float
{
    return (float) ($split->isHorizontal() ? $split->native()->getWidth() : $split->native()->getHeight());
}

/**
 * Pump until the window is mapped, the split is not laying out, and its length and pane spans
 * have held still for 60 ms: several frames, so a change GTK has queued for its next frame shows.
 */
function settledSplit(GTKWindow $window, GTKSplitView $split, float $seconds = 3.0): bool
{
    $last = null;
    $since = microtime(true);

    return pumpUntil(function () use ($window, $split, &$last, &$since): bool {
        $now = [splitLength($split)];
        foreach ($split->children() as $pane) {
            $pane->native()->computeBounds($split->native(), $bounds);
            array_push($now, $bounds?->origin->x, $bounds?->origin->y, $bounds?->size->width, $bounds?->size->height);
        }
        if ($now !== $last) {
            $last = $now;
            $since = microtime(true);

            return false;
        }

        return $window->native()->getMapped() && ! $split->isSettling() && $now[0] > 0.0 && microtime(true) - $since >= 0.06;
    }, $seconds);
}

/** One pixel plus the handle between the first two visible panes, as a fraction of the length. */
function splitTolerance(GTKSplitView $split, string $first, string $second): float
{
    return (1.0 + paneSpan($split, $second)[0] - paneSpan($split, $first)[1]) / splitLength($split);
}

/** A three-pane split filling a 600×300 window. */
function threePanes(bool $horizontal = true): array
{
    $window = driver()->open('main', 600, 300);
    $split = $window->column('m')->split('panes', $horizontal)->fill();
    foreach (['a', 'b', 'c'] as $pane) {
        $split->column($pane)->label("{$pane}-label", strtoupper($pane));
    }

    return [$window, $split];
}

it('round-trips divider positions within a pixel and a handle of the split length, and posts nothing for them', function (bool $horizontal): void {
    [$window, $split] = threePanes($horizontal);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split, 'a', 'b');
    expect(abs($split->positions()[0] - 1 / 3))->toBeLessThanOrEqual($tolerance)   // the first layout shares the length
        ->and(abs($split->positions()[1] - 2 / 3))->toBeLessThanOrEqual($tolerance);

    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    $positions = $split->positions();

    expect($positions)->toHaveCount(2)
        ->and(abs($positions[0] - 0.25))->toBeLessThanOrEqual($tolerance)
        ->and(abs($positions[1] - 0.6))->toBeLessThanOrEqual($tolerance)
        ->and(abs(paneSpan($split, 'a')[1] / splitLength($split) - $positions[0]))->toBeLessThanOrEqual(1.0 / splitLength($split))
        ->and(viewMail(session()))->toBe([]);
})->with(['side by side' => true, 'stacked' => false]);

it('applies positions set before the split is on screen at its first layout', function (): void {
    [$window, $split] = threePanes();
    $split->setPositions([0.3, 0.7]);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split, 'a', 'b');

    expect(abs($split->positions()[0] - 0.3))->toBeLessThanOrEqual($tolerance)
        ->and(abs($split->positions()[1] - 0.7))->toBeLessThanOrEqual($tolerance);
});

it('follows the visible panes when the middle one hides, and shows it again', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $split->view('b')->hide();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(1);
    $split->setPositions([0.5]);
    expect(settledSplit($window, $split))->toBeTrue();
    expect(abs($split->positions()[0] - 0.5))->toBeLessThanOrEqual(splitTolerance($split, 'a', 'c'))
        ->and($split->view('b')->native()->getMapped())->toBeFalse();

    $split->view('b')->show();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(2)
        ->and(viewMail(session()))->toBe([]);
});

it('posts nothing when a window resize or a position set on the native moves the dividers', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25, 0.6]);
    expect(settledSplit($window, $split))->toBeTrue();
    viewMail(session());

    $window->native()->setDefaultSize(900, 400);
    expect(pumpUntil(fn (): bool => splitLength($split) > 600.0))->toBeTrue()
        ->and(settledSplit($window, $split))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);

    // A position set on the native moves the divider; a late window resize may rescale it, so
    // only the move and the silence are pinned.
    $paned = $split->native()->getFirstChild();
    expect($paned)->toBeInstanceOf(GtkPaned::class);
    $before = paneSpan($split, 'a')[1];
    $paned->setPosition((int) $before + 60);
    expect(pumpUntil(fn (): bool => paneSpan($split, 'a')[1] !== $before))->toBeTrue()
        ->and(settledSplit($window, $split))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('titles, selects and removes pages without mail, and posts TabSelected for a user switch', function (): void {
    $window = driver()->open('main', 400, 300);
    $tabs = $window->column('m')->tabs('sessions')->fill();
    $tabs->addPage('one', 'left.txt ↔ right.txt')->label('l1', 'One');
    $tabs->addPage('two', 'Folders')->label('l2', 'Two');
    $tabs->addPage('three', 'Three');
    $window->present();
    pumpFor(0.2);

    /** @var GtkNotebook $native */
    $native = $tabs->native();
    expect($tabs->pages())->toBe(['one', 'two', 'three'])
        ->and($tabs->selected())->toBe('one')
        ->and($native->getNPages())->toBe(3);

    $tabs->setTitle('two', 'Folders *')->select('two');
    $two = $tabs->view('two')->native();
    expect($native->getTabLabelText($two))->toBe('Folders *')
        ->and($tabs->selected())->toBe('two')
        ->and($native->getCurrentPage())->toBe($native->pageNum($two));

    $tabs->view('two')->remove();
    expect($tabs->pages())->toBe(['one', 'three'])
        ->and($native->getNPages())->toBe(2)
        ->and($tabs->selected())->not->toBeNull()
        ->and(viewMail(session()))->toBe([]);

    $other = $tabs->selected() === 'one' ? 'three' : 'one';
    $native->setCurrentPage($native->pageNum($tabs->view($other)->native()));
    $mail = viewMail(session());
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(TabSelected::class)
        ->and($mail[0]->page)->toBe($other)
        ->and($mail[0]->name())->toBe('view.tab-selected.main.m.sessions')
        ->and($tabs->selected())->toBe($other);
});

it('gives the one visible pane the whole length, with no handle, when the panes after it hide', function (): void {
    [$window, $split] = threePanes();
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $split->view('b')->hide();
    $split->view('c')->hide();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toBe([])
        ->and(abs(paneSpan($split, 'a')[1] - splitLength($split)))->toBeLessThanOrEqual(1.0);

    $split->view('c')->show();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(1)
        ->and(viewMail(session()))->toBe([]);
});

it('keeps the dividers and the pane widgets where they are when a pane is added or removed', function (): void {
    $window = driver()->open('main', 600, 300);
    $split = $window->column('m')->split('panes')->fill();
    $split->column('a')->label('a-label', 'A');
    $split->column('b')->label('b-label', 'B');
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();
    $split->setPositions([0.25]);
    expect(settledSplit($window, $split))->toBeTrue();
    $parent = $split->view('a')->native()->getParent();

    $split->column('c')->label('c-label', 'C');
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split, 'a', 'b');
    expect($split->positions())->toHaveCount(2)
        ->and(abs($split->positions()[0] - 0.25))->toBeLessThanOrEqual($tolerance)
        ->and($split->view('a')->native()->getParent())->toBe($parent);

    $split->view('c')->remove();
    expect(settledSplit($window, $split))->toBeTrue()
        ->and($split->positions())->toHaveCount(1)
        ->and(abs($split->positions()[0] - 0.25))->toBeLessThanOrEqual($tolerance)
        ->and($split->view('a')->native()->getParent())->toBe($parent)
        ->and(viewMail(session()))->toBe([]);
});

it('measures dividers by their slots, so a pane aligned inside its slot does not skew them', function (): void {
    [$window, $split] = threePanes();
    $split->view('a')->align(Align::START);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $split->setPositions([0.5, 0.75]);
    $started = microtime(true);
    expect(settledSplit($window, $split))->toBeTrue()
        ->and(microtime(true) - $started)->toBeLessThan(0.4)
        ->and(abs($split->positions()[0] - 0.5))->toBeLessThanOrEqual(splitTolerance($split, 'b', 'c'));
});

it('applies positions set while the split is on a hidden tab page when the page shows', function (): void {
    $window = driver()->open('main', 600, 300);
    $tabs = $window->column('m')->tabs('sessions')->fill();
    $tabs->addPage('one', 'One')->label('l1', 'One');
    $split = $tabs->addPage('two', 'Two')->split('panes')->fill();
    foreach (['a', 'b', 'c'] as $pane) {
        $split->column($pane)->label("{$pane}-label", strtoupper($pane));
    }
    $window->present();
    $tabs->select('two');
    expect(settledSplit($window, $split))->toBeTrue();
    $tabs->select('one');
    pumpFor(0.1);

    $split->setPositions([0.2, 0.8]);
    pumpFor(0.1);
    $tabs->select('two');
    expect(settledSplit($window, $split))->toBeTrue();
    $tolerance = splitTolerance($split, 'a', 'b');
    expect(abs($split->positions()[0] - 0.2))->toBeLessThanOrEqual($tolerance)
        ->and(abs($split->positions()[1] - 0.8))->toBeLessThanOrEqual($tolerance)
        ->and(abs(paneSpan($split, 'b')[1] / splitLength($split) - 0.8))->toBeLessThanOrEqual($tolerance);
});

it('posts no TabSelected when hiding the selected page makes GTK switch', function (): void {
    $window = driver()->open('main', 400, 300);
    $tabs = $window->column('m')->tabs('sessions')->fill();
    $tabs->addPage('one', 'One')->label('l1', 'One');
    $tabs->addPage('two', 'Two')->label('l2', 'Two');
    $window->present();
    pumpFor(0.2);
    viewMail(session());

    $tabs->view('one')->hide();
    pumpFor(0.1);
    expect($tabs->selected())->toBe('two')
        ->and(viewMail(session()))->toBe([]);

    $tabs->view('one')->show();
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([]);
});

it('finds a divider handle at its laid-out rectangle, where the panes overlap it, and nowhere in a pane', function (bool $horizontal): void {
    [$window, $split] = threePanes($horizontal);
    $window->present();
    expect(settledSplit($window, $split))->toBeTrue();

    $at = fn (float $along, float $across): bool => (fn (): bool => $this->handleAt($horizontal ? $along : $across, $horizontal ? $across : $along))->call($split);
    $across = ($horizontal ? $split->native()->getHeight() : $split->native()->getWidth()) / 2;
    [, $end] = paneSpan($split, 'a');
    [$start] = paneSpan($split, 'b');
    [$middle] = [($end + $start) / 2];

    expect($at($middle, $across))->toBeTrue()
        ->and($at($end - 2, $across))->toBeTrue()       // GtkPaned grabs a few pixels into each pane
        ->and($at($start + 2, $across))->toBeTrue()
        ->and($at($end / 2, $across))->toBeFalse()       // the middle of pane a
        ->and($at(paneSpan($split, 'c')[1] - 5, $across))->toBeFalse();
})->with(['side by side' => true, 'stacked' => false]);

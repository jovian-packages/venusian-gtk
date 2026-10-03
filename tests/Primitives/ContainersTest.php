<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKColumn;
use Jovian\Toolkits\GTK\Primitives\GTKFixed;
use Jovian\Toolkits\GTK\Primitives\GTKGrid;
use Jovian\Toolkits\GTK\Primitives\GTKRow;
use Jovian\Toolkits\GTK\Primitives\GTKScrollView;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Mail\WindowResized;
use Surface\Contracts\Windows\Primitives\Align;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('mounts a column as the window content and lays children out natively', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main', spacing: 8, padding: 10);
    $title = $main->label('title', 'Hello')->align(Align::CENTER);
    $body = $main->row('body', spacing: 4);
    $left = $body->button('left', 'L')->fill(vertical: false);
    $window->present();
    pumpFor(0.2);

    expect($main)->toBeInstanceOf(GTKColumn::class)
        ->and($body)->toBeInstanceOf(GTKRow::class)
        ->and($main->native())->toBeInstanceOf(GtkBox::class)
        ->and($main->native()->getParent())->toBe($window->contentArea())
        ->and($main->native()->getFirstChild())->toBe($title->native())
        ->and($title->native()->getNextSibling())->toBe($body->native())
        ->and($body->native()->getNextSibling())->toBeNull()
        ->and($window->content())->toBe($main)
        ->and($main->size()[0])->toBe(400)
        ->and($window->size())->toBe([400, 300])
        ->and($left->size()[0])->toBeGreaterThan(300)
        ->and($title->size()[0])->toBeLessThan(200)
        ->and($main->stylesheet())->toContain('padding: 10px;');
});

it('fills grid cells and absolute frames', function (): void {
    $window = driver()->open('main', 400, 300);
    $grid = $window->grid('g', spacing: 2);
    $a = $grid->at(0, 0, 1, 2)->label('a', 'A');
    $b = $grid->at(1, 1)->button('b', 'B');
    $fixed = $grid->at(2, 0)->fixed('f');
    $c = $fixed->at(10, 20, 30, 40)->label('c', 'C');
    $window->present();
    pumpFor(0.2);

    expect($grid)->toBeInstanceOf(GTKGrid::class)
        ->and($fixed)->toBeInstanceOf(GTKFixed::class)
        ->and($grid->native()->getChildAt(0, 0))->toBe($a->native())
        ->and($grid->native()->getChildAt(1, 0))->toBe($a->native())
        ->and($grid->native()->getChildAt(1, 1))->toBe($b->native())
        ->and($grid->native()->getRowSpacing())->toBe(2)
        ->and($grid->native()->getColumnSpacing())->toBe(2)
        ->and($fixed->native()->getChildPosition($c->native()))->toBe([10.0, 20.0])
        ->and($c->size())->toBe([30, 40]);

    $fixed->move($c, 15, 25)->resize($c, 50, 60);
    pumpFor(0.1);
    expect($c->size())->toBe([50, 60])
        ->and($fixed->native()->getChildPosition($c->native()))->toBe([15.0, 25.0]);

    // A minimum size above the frame wins on that axis; the frame holds the other.
    $c->minSize(70, 10);
    pumpFor(0.1);
    expect($c->size())->toBe([70, 60]);
});

it('posts WindowResized once per pump with the last size', function (): void {
    $window = driver()->open('main', 400, 300)->present();
    pumpFor(0.2);
    takeMail(session());
    $window->native()->setDefaultSize(420, 300);
    $window->native()->setDefaultSize(440, 310);
    pumpFor(0.3);

    $resized = array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowResized));
    expect($resized)->toEqual([new WindowResized('main', 440, 310)])
        ->and($window->size())->toBe([440, 310]);
});

it('removes the content tree when the window closes', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main');
    $inner = $main->column('a');
    $window->present();
    pumpFor(0.1);
    takeMail(session());
    $window->close();
    pumpFor(0.05);

    expect($inner->isRemoved())->toBeTrue()
        ->and($main->isRemoved())->toBeTrue()
        ->and($inner->native()->getParent())->toBeNull()
        ->and(fn () => $window->view('main.a'))->toThrow(WindowException::class, 'closed')
        ->and(fn () => $window->content())->toThrow(WindowException::class, 'closed')
        ->and(takeMail(session()))->toEqual([new WindowClosed('main')]);
});

it('reorders children natively', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main');
    $a = $main->column('a');
    $b = $main->column('b');
    $c = $main->column('c');

    $c->moveBefore($a);
    expect(nativeOrder($main->native()))->toBe([$c->native(), $a->native(), $b->native()])
        ->and(array_map(fn ($child) => $child->name(), $main->children()))->toBe(['c', 'a', 'b']);

    $c->moveAfter($b);
    expect(nativeOrder($main->native()))->toBe([$a->native(), $b->native(), $c->native()]);

    $b->moveTo(0);
    expect(nativeOrder($main->native()))->toBe([$b->native(), $a->native(), $c->native()]);
});

it('takes a removed child out of its container and the content out of the window', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main');
    $row = $main->row('row');
    $leaf = $row->column('leaf');

    $row->remove();
    expect($row->native()->getParent())->toBeNull()
        ->and($leaf->isRemoved())->toBeTrue()
        ->and($window->view('main.row'))->toBeNull()
        ->and($window->view('main.row.leaf'))->toBeNull()
        ->and($main->native()->getFirstChild())->toBeNull()
        ->and(fn () => $row->hide())->toThrow(WindowException::class, 'removed');

    $main->remove();
    expect($window->contentArea()->getFirstChild())->toBeNull()
        ->and($window->content())->toBeNull();

    $again = $window->row('again');
    expect($window->contentArea()->getFirstChild())->toBe($again->native());
});

it('scrolls exactly one container', function (): void {
    $window = driver()->open('main', 400, 300);
    $scroll = $window->column('main')->scrollView('s');
    $inner = $scroll->column('inner');
    $scroll->setScrollbars(false, true);

    expect($scroll)->toBeInstanceOf(GTKScrollView::class)
        ->and($scroll->native())->toBeInstanceOf(GtkScrolledWindow::class)
        ->and($scroll->native()->getChild()->getFirstChild())->toBe($inner->native())
        ->and($scroll->content())->toBe($inner)
        ->and(fn () => $scroll->column('second'))->toThrow(WindowException::class, 'exactly one');

    $inner->remove();
    expect($scroll->native()->getChild())->toBeNull();
});

it('writes visibility, sensitivity, background, fill and alignment to the widget', function (): void {
    $window = driver()->open('main', 400, 300);
    $main = $window->column('main');
    $row = $main->row('row');

    $row->hide()->disable()->setBackground(Color::rgb(255, 0, 0))->fill(true, false)->align(Align::END, Align::START)->minSize(50, 20);
    expect($row->native()->getVisible())->toBeFalse()
        ->and($row->native()->getSensitive())->toBeFalse()
        ->and($row->native()->hasCssClass($row->styleClass()))->toBeTrue()
        // A container paints only itself: no rule reaches the inputs nested inside it.
        ->and($row->stylesheet())->toBe(".{$row->styleClass()} { background-color: rgba(255, 0, 0, 1); background-image: none; }")
        ->and($row->native()->getHexpand())->toBeTrue()
        ->and($row->native()->getVexpand())->toBeFalse()
        ->and($row->native()->getHalign())->toBe(GtkAlign::END)
        ->and($row->native()->getValign())->toBe(GtkAlign::START)
        ->and($row->native()->getSizeRequest())->toBe([50, 20]);

    $row->show()->enable()->setBackground(null);
    expect($row->native()->getVisible())->toBeTrue()
        ->and($row->native()->getSensitive())->toBeTrue()
        ->and($row->stylesheet())->toBeNull();
});

/** @return list<GtkWidget> */
function nativeOrder(GtkWidget $parent): array
{
    $order = [];
    for ($child = $parent->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
        $order[] = $child;
    }

    return $order;
}

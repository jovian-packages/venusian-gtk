<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\View\ItemActivated;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Primitives\CollectionItem;
use Surface\Contracts\Windows\Primitives\ListRow;

// No window is presented: GTK sets up list cells only once mapped, so cell contents are a GUI
// run's to check. These cover the widgets, the models and the mail.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A protected method of $object, called as the native would. */
function callNative(object $object, string $method, mixed ...$args): mixed
{
    return Closure::bind(fn () => $this->{$method}(...$args), $object, $object::class)();
}

it('links one toggle button per segment and posts SelectionChanged for a user toggle only', function (): void {
    $window = driver()->open('main', 400, 200);
    $control = $window->column('m')->segmentedControl('s', ['Today', 'Recently', 'APODdex'], 0);
    $buttons = $control->buttons();

    expect($control->native()->hasCssClass('linked'))->toBeTrue()
        ->and(array_map(fn (GtkToggleButton $b): ?string => $b->getLabel(), $buttons))->toBe(['Today', 'Recently', 'APODdex'])
        ->and($buttons[0]->getActive())->toBeTrue();

    $control->setSegment(0, 'Yesterday')->select(2);
    expect($buttons[0]->getLabel())->toBe('Yesterday')
        ->and($buttons[2]->getActive())->toBeTrue()
        ->and($buttons[0]->getActive())->toBeFalse()
        ->and(viewMail(session()))->toBe([]);

    $buttons[1]->setActive(true);
    expect(viewMail(session()))->toEqual([new SelectionChanged('main', 'm.s', $control->uuid(), 1, 'Recently')])
        ->and($control->selected())->toBe(1);
});

it('keeps one model row per collection item through inserts, removals and replaces', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [new CollectionItem(null, 'a'), new CollectionItem(null, 'b')]);
    $model = $view->selection()->getModel();

    expect($view->native())->toBeInstanceOf(GtkScrolledWindow::class)
        ->and($view->gridView()->getSingleClickActivate())->toBeTrue()
        ->and($model->getNItems())->toBe(2);
    $view->insertItems(1, [new CollectionItem(null, 'x'), new CollectionItem(null, 'y')]);
    expect($model->getNItems())->toBe(4);
    $view->removeItems(0, 3);
    expect($model->getNItems())->toBe(1);
    $view->setItems([]);
    expect($model->getNItems())->toBe(0);
});

it('clears a collection cell\'s selection once it is activated, so no cell stays highlighted', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [new CollectionItem(null, 'a'), new CollectionItem(null, 'b')]);
    $view->selection()->setSelected(1);

    callNative($view, 'activated', 1);

    expect(viewMail(session()))->toEqual([new ItemActivated('main', 'm.c', $view->uuid(), 1)])
        ->and($view->selection()->getSelected())->toBe(GTK_INVALID_LIST_POSITION);
});

it('clears the hover highlight when the pointer leaves the grid', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [new CollectionItem(null, 'a'), new CollectionItem(null, 'b')]);
    $view->selection()->setSelected(1);

    callNative($view, 'pointerLeft');

    expect($view->selection()->getSelected())->toBe(GTK_INVALID_LIST_POSITION)
        ->and(viewMail(session()))->toBe([]);
});

it('floats a cell\'s status icons on discs over its top-right corner, left of the action button', function (): void {
    $png = __DIR__.'/../fixtures/pixel.png';
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->collectionView('c', [new CollectionItem($png, 'a', $png, [$png, $png])]);
    $view->fill();
    $window->present();
    $cells = fn (): array => (fn () => $this->cells)->call($view);
    for ($waited = 0; $cells() === [] && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    pumpFor(0.1);
    $cell = array_values($cells())[0];
    $kids = function (GtkWidget $box): array {
        $out = [];
        for ($c = $box->getFirstChild(); ! is_null($c); $c = $c->getNextSibling()) {
            $out[] = $c;
        }

        return $out;
    };
    $icons = $kids($cell['icons']);

    expect($icons)->toHaveCount(2)
        ->and($icons[0]->hasCssClass('surface-item-status'))->toBeTrue()
        ->and($kids($cell['icons']->getParent()))->toBe([$cell['icons'], $cell['action']]);

    $view->updateItem(0, new CollectionItem($png, 'a'));
    pumpFor(0.1);
    // The update splices row 0: GTK may bind it to another list item.
    $cell = array_values(array_filter($cells(), fn (array $c) => $c['item']->getPosition() === 0 && $c['caption']->getText() === 'a'));
    $cell = end($cell);
    expect($kids($cell['icons']))->toBe([])
        ->and($cell['action']->getVisible())->toBeFalse();
});

it('posts ItemActivated for enabled list rows only', function (): void {
    $window = driver()->open('main', 600, 400);
    $view = $window->column('m')->listView('l', [new ListRow(null, 'open'), new ListRow(null, '???', enabled: false)]);

    expect($view->selection()->getModel()->getNItems())->toBe(2);
    callNative($view, 'activated', 0);
    callNative($view, 'activated', 1);
    expect(viewMail(session()))->toEqual([new ItemActivated('main', 'm.l', $view->uuid(), 0)]);
});

<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\MenuRadioChosen;
use Surface\Contracts\Windows\Mail\MenuToggled;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

const CHOICE_MENU = [
    ['id' => 'wrap', 'label' => 'Wrap', 'toggle' => true],
    ['separator' => true],
    ['id' => 'case.none', 'label' => 'None', 'radio' => 'case', 'on' => true],
    ['id' => 'case.all', 'label' => 'All', 'radio' => 'case'],
];

/** The menu mail posted since the last read. */
function menuMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof MenuActivated || $mail instanceof MenuToggled || $mail instanceof MenuRadioChosen));
}

function shownLabel(): array
{
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label')->minSize(120, 40);
    $label->setContextMenu(CHOICE_MENU);
    $point = canvasPointIn($window, $label, 4.0, 5.0);
    takeMail(session());

    return [$window, $label, $point];
}

it('posts a context toggle and keeps it for the next open and in contextMenu()', function (): void {
    [$window, $label, [$x, $y]] = shownLabel();

    $window->rightClicks()->at($x, $y);
    $window->rightClicks()->openMenu()->popover()->activateActionVariant('ctx.wrap', null);
    $window->rightClicks()->openMenu()->popover()->popdown();
    $window->rightClicks()->at($x, $y);
    $next = $window->rightClicks()->openMenu();

    expect(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($next->action('wrap')->getState()->getBoolean())->toBeTrue()
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeTrue();
    $next->popover()->popdown();
    $window->close();
});

it('posts a context radio choice once and keeps it', function (): void {
    [$window, $label, [$x, $y]] = shownLabel();

    $window->rightClicks()->at($x, $y);
    $menu = $window->rightClicks()->openMenu();
    $menu->popover()->activateActionVariant('ctx.case.all', GVariant::newString('case.all'));
    $menu->popover()->activateActionVariant('ctx.case.all', GVariant::newString('case.all'));
    $menu->popover()->popdown();
    $window->rightClicks()->at($x, $y);
    $next = $window->rightClicks()->openMenu();

    expect(menuMail())->toEqual([new MenuRadioChosen('main', 'case', 'case.all')])
        ->and($next->action('case.none')->getState()->getString())->toBe('case.all')
        ->and($label->contextMenu()->radios())->toBe(['case' => 'case.all']);
    $next->popover()->popdown();
    $window->close();
});

it('opens a menu at a point from code at once, its choices posted and not kept', function (): void {
    [$window, $label] = shownLabel();

    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $open = $label->openPopup();
    $shown = pumpUntil(fn (): bool => $open->popover()->getMapped());
    $open->popover()->activateActionVariant('ctx.wrap', null);
    [$bx, $by] = boundsOrigin($label->native());
    [$cx, $cy] = widgetPoint($label->native(), $window->contentArea(), 10.0 + $bx, 5.0 + $by);
    $open->popover()->getPointingTo($rect);

    expect($shown)->toBeTrue()
        ->and($open->popover()->getParent())->toBe($window->contentArea())
        ->and([$rect->x, $rect->y])->toBe([(int) round($cx), (int) round($cy)])
        ->and(menuMail())->toEqual([new MenuToggled('main', 'wrap', true)])
        ->and($label->contextMenu()->items()['wrap']->isOn())->toBeFalse();
    $open->popover()->popdown();
    $window->close();
});

it('refuses to open a menu in a window not shown', function (): void {
    $window = driver()->open('main', 300, 200);
    $label = $window->column('m')->label('l', 'Label');

    expect(fn () => $label->popupMenu(CHOICE_MENU, 0.0, 0.0))->toThrow(WindowException::class, "'m.l' cannot open a menu: it is not shown.");
    $window->close();
});

it('takes the popped menu down when its primitive is removed, posting nothing', function (): void {
    [$window, $label] = shownLabel();
    $label->popupMenu(CHOICE_MENU, 10.0, 5.0);
    $popover = $label->openPopup()->popover();
    pumpUntil(fn (): bool => $popover->getMapped());

    $label->remove();
    pumpUntil(fn (): bool => ! $popover->getMapped());

    expect($popover->getMapped())->toBeFalse()
        ->and(menuMail())->toBe([]);
    $window->close();
});

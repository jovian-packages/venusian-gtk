<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKRightClicks;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\MenuActivated;
use Surface\Contracts\Windows\Mail\View\RightClicked;
use Surface\Contracts\Windows\Mail\WindowRightClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/**
 * The window point of ($x, $y) measured from $primitive's border-box top-left (as Surface sizes
 * primitives), once the window is laid out. GTK's own widget coordinates start inside the border and
 * padding, so the border box's corner is the widget's bounds' origin in its own coordinates.
 */
function windowPointIn(GTKWindow $window, TKPrimitive $primitive, float $x, float $y): array
{
    $window->present();
    for ($waited = 0; $primitive->native()->getWidth() === 0 && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    [$bx, $by] = boundsOrigin($primitive->native());

    return widgetPoint($primitive->native(), $window->native(), $x + $bx, $y + $by);
}

/** @return list<object> the right-click mail posted since the last read */
function rightClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked));
}

it('posts view.right-clicked for the innermost primitive under the point, from its border-box top-left', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->row('r')->button('go', 'Go');
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    takeMail(session());
    $window->rightClicks()->at($x, $y);

    $mail = rightClickMail();
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(RightClicked::class)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.r.go', $go->uuid()])
        ->and([round($mail[0]->x), round($mail[0]->y)])->toBe([4.0, 5.0]);
});

it('posts window.right-clicked on content with no primitive, in content coordinates', function (): void {
    $window = driver()->open('bare', 300, 200)->present();
    for ($waited = 0; $window->contentArea()->getWidth() === 0 && $waited < 60; $waited++) {
        pumpFor(0.05);
    }
    [$x, $y] = widgetPoint($window->contentArea(), $window->native(), 30.0, 40.0);
    takeMail(session());
    $window->rightClicks()->at($x, $y);

    $mail = rightClickMail();
    expect($mail)->toHaveCount(1)
        ->and($mail[0])->toBeInstanceOf(WindowRightClicked::class)
        ->and([$mail[0]->window, round($mail[0]->x), round($mail[0]->y)])->toBe(['bare', 30.0, 40.0]);
});

it('posts nothing for a disabled primitive, or one inside a disabled group, and nothing for its parents', function (): void {
    $window = driver()->open('main', 300, 200);
    $row = $window->column('m')->row('r');
    $go = $row->button('go', 'Go');
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    takeMail(session());
    $go->setEnabled(false);
    $window->rightClicks()->at($x, $y);
    $own = rightClickMail();
    $go->setEnabled(true);
    $row->setEnabled(false);
    $window->rightClicks()->at($x, $y);

    expect([$own, rightClickMail()])->toBe([[], []]);
});

it('reads the right button, and Ctrl with the primary button on macOS only, as a right click', function (): void {
    expect([
        GTKRightClicks::secondary(3, 0, macos: false),
        GTKRightClicks::secondary(3, 0, macos: true),
        GTKRightClicks::secondary(1, GDK_CONTROL_MASK, macos: true),
        GTKRightClicks::secondary(1, GDK_CONTROL_MASK, macos: false),
        GTKRightClicks::secondary(1, 0, macos: true),
        GTKRightClicks::secondary(2, 0, macos: false),
    ])->toBe([true, true, true, false, false, false]);
});

it('watches the window in capture phase, and a primary click alone posts nothing', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go');
    [$x, $y] = windowPointIn($window, $go, 2.0, 3.0);
    takeMail(session());
    $clicks = $window->rightClicks();
    g_signal_emit_by_name($clicks->click(), 'pressed', 1, $x, $y);

    expect([$clicks->click()->getPropagationPhase(), $clicks->holds()->getPropagationPhase()])->toBe([GtkPropagationPhase::CAPTURE, GtkPropagationPhase::CAPTURE])
        ->and($clicks->click()->getButton())->toBe(0)
        ->and(rightClickMail())->toBe([]);
});

it('posts for a primary press held still past GTK\'s long-press time, not one let go early or dragged', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go');
    [$x, $y] = windowPointIn($window, $go, 2.0, 3.0);
    $clicks = $window->rightClicks();
    $clicks->holdOnPress(true);
    $settings = GtkSettings::getDefault();
    $hold = g_object_get_property($settings, 'gtk-long-press-time') / 1000;
    $drag = g_object_get_property($settings, 'gtk-dnd-drag-threshold');
    takeMail(session());

    // Let go before the hold time: released at once, as a wait for half of it can overrun on a loaded machine.
    $clicks->pressed($x, $y);
    $clicks->released();
    pumpFor($hold * 1.5);
    $early = rightClickMail();

    $clicks->pressed($x, $y);
    $clicks->moved($x + $drag + 2.0, $y);
    pumpFor($hold * 1.5);
    $dragged = rightClickMail();

    $clicks->pressed($x, $y);
    $clicks->moved($x + 1.0, $y);
    $held = [];
    pumpUntil(function () use (&$held): bool {
        $held = [...$held, ...array_map(fn (object $mail): string => $mail->name(), rightClickMail())];

        return $held !== [];
    }, $hold * 4);
    $clicks->released();

    expect([$early, $dragged, $held])->toBe([[], [], ['view.right-clicked.main.m.go']]);
});

/** @return list<string> the names of the right-click and menu mail posted since the last read */
function contextMail(): array
{
    return array_values(array_map(fn (object $mail): string => $mail->name(), array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof RightClicked || $mail instanceof WindowRightClicked || $mail instanceof MenuActivated)));
}

it('opens a primitive\'s context menu at the right click, after its mail, and posts menu.activated for a chosen item', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([
        ['label' => 'Download'],
        ['separator' => true],
        ['label' => 'Share', 'items' => [['label' => 'Copy Link']]],
    ]);
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    [$cx, $cy] = widgetPoint($window->native(), $window->contentArea(), $x, $y);
    takeMail(session());

    $opened = $window->rightClicks()->at($x, $y);
    $menu = $window->rightClicks()->openMenu();
    $shown = pumpUntil(fn (): bool => $menu->popover()->getMapped());
    $clicked = contextMail();
    $menu->popover()->activateActionVariant('ctx.share.copy-link', null);
    $chosen = contextMail();
    $pointing = $menu->popover()->getPointingTo($rect);

    expect($opened)->toBeTrue()
        ->and($shown)->toBeTrue()
        ->and($menu->popover()->getParent())->toBe($window->contentArea())
        ->and($pointing)->toBeTrue()
        ->and([$rect->x, $rect->y, $rect->width, $rect->height])->toBe([(int) round($cx), (int) round($cy), 1, 1])
        ->and($clicked)->toBe(['view.right-clicked.main.m.go'])
        ->and($chosen)->toBe(['menu.activated.main.share.copy-link']);
    $menu->popover()->popdown();
});

it('opens the nearest group\'s menu when the primitive has none, and none on bare content or without one', function (): void {
    $window = driver()->open('main', 300, 200);
    $outer = $window->column('m');
    $column = $outer->column('c')->setContextMenu([['label' => 'Refresh']]);
    $label = $column->label('l', 'Label');
    $plain = $outer->button('plain', 'Plain');
    [$x, $y] = windowPointIn($window, $label, 2.0, 2.0);
    $opened = $window->rightClicks()->at($x, $y);
    $menu = $window->rightClicks()->openMenu();
    $menu->popover()->activateActionVariant('ctx.refresh', null);
    $menu->popover()->popdown();
    takeMail(session());
    [$px, $py] = windowPointIn($window, $plain, 2.0, 2.0);

    expect($opened)->toBeTrue()
        ->and($window->rightClicks()->at($px, $py))->toBeFalse()
        ->and($window->rightClicks()->openMenu())->toBe($menu)
        ->and(contextMail())->toBe(['view.right-clicked.main.m.plain']);
});

it('opens nothing for a disabled primitive with a menu', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([['label' => 'Download']]);
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    $go->disable();
    takeMail(session());

    expect($window->rightClicks()->at($x, $y))->toBeFalse()
        ->and($window->rightClicks()->openMenu())->toBeNull()
        ->and(contextMail())->toBe([]);
});

it('takes a menu off the window once it closes, and closes an open one before opening the next', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([['label' => 'Download']]);
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    $window->rightClicks()->at($x, $y);
    $first = $window->rightClicks()->openMenu();
    pumpUntil(fn (): bool => $first->popover()->getMapped());
    $window->rightClicks()->at($x, $y);
    $second = $window->rightClicks()->openMenu();
    $gone = pumpUntil(fn (): bool => is_null($first->popover()->getParent()));
    $second->popover()->popdown();
    $closed = pumpUntil(fn (): bool => is_null($second->popover()->getParent()));

    expect($second)->not->toBe($first)
        ->and([$gone, $closed])->toBe([true, true]);
});

it('builds a context menu folder with enabled false disabled, and the items below it', function (): void {
    $window = driver()->open('main', 300, 200);
    $go = $window->column('m')->button('go', 'Go')->setContextMenu([
        ['label' => 'Share', 'enabled' => false, 'items' => [['label' => 'Copy Link']]],
        ['label' => 'Download'],
    ]);
    [$x, $y] = windowPointIn($window, $go, 4.0, 5.0);
    $window->rightClicks()->at($x, $y);
    $menu = $window->rightClicks()->openMenu();

    expect($menu->action('share')->getEnabled())->toBeFalse()
        ->and($menu->action('share.copy-link')->getEnabled())->toBeFalse()
        ->and($menu->action('download')->getEnabled())->toBeTrue()
        ->and(labelIn($menu->popover(), 'Share')->isSensitive())->toBeFalse();
    $menu->popover()->popdown();
});

<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\GroupClicked;
use Surface\Contracts\Windows\Primitives\TKPrimitive;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The window point of $primitive's centre, once laid out. */
function groupClickPoint(GTKWindow $window, TKPrimitive $primitive): array
{
    $window->present();
    for ($waited = 0; $primitive->native()->getWidth() === 0 && $waited < 60; $waited++) {
        pumpFor(0.05);
    }

    return widgetPoint($primitive->native(), $window->native(), $primitive->native()->getWidth() / 2, $primitive->native()->getHeight() / 2);
}

/** @return list<GroupClicked> */
function groupClickMail(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof GroupClicked));
}

it('watches the window in capture phase for the primary button', function (): void {
    $window = driver()->open('main', 300, 200);
    $click = $window->groupClicks()->click();

    expect([$click->getPropagationPhase(), $click->getButton()])->toBe([GtkPropagationPhase::CAPTURE, 1]);
});

it('posts view.group-clicked for a press and release on a label inside a clickable group', function (): void {
    $window = driver()->open('main', 300, 200);
    $card = $window->column('m')->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    [$x, $y] = groupClickPoint($window, $title);
    takeMail(session());

    $window->groupClicks()->pressAt($x, $y);
    $window->groupClicks()->releaseAt($x, $y);

    $mail = groupClickMail();
    expect($mail)->toHaveCount(1)
        ->and([$mail[0]->window, $mail[0]->path, $mail[0]->uuid])->toBe(['main', 'm.card', $card->uuid()]);
});

it('posts nothing for a group that is not clickable, a control inside one, or a release elsewhere', function (): void {
    $window = driver()->open('main', 300, 200);
    $m = $window->column('m');
    $text = $m->column('plain')->label('text', 'Plain');
    $card = $m->column('card')->setClickable();
    $title = $card->label('title', 'Stargazer');
    $go = $card->button('go', 'Go');
    $at = groupClickPoint($window, $title);
    $elsewhere = groupClickPoint($window, $text);
    $button = groupClickPoint($window, $go);
    takeMail(session());

    foreach ([[$elsewhere, $elsewhere], [$button, $button], [$at, $elsewhere]] as [$down, $up]) {
        $window->groupClicks()->pressAt(...$down);
        $window->groupClicks()->releaseAt(...$up);
    }

    expect(groupClickMail())->toBe([]);
});

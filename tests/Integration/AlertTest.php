<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Dialogs\Alert;
use Surface\Contracts\Toolkits\Dialogs\AlertStyle;
use Surface\Contracts\Toolkits\Mail\Windows\AlertAnswered;

/*
| GtkAlertDialog gives no way to its window or buttons, so a press is the user's. These cover
| what code reaches: the dialog chosen without waiting, its cancellation, the window's close.
*/

/** A confirmation whose default is also its cancel button, as one that deletes something asks. */
function confirmation(): Alert
{
    return new Alert('Clear your key?', 'It goes back to demo mode.', ['Clear Key', 'Cancel'], default: 1, cancel: 1, style: AlertStyle::Warning);
}

/** @return list<AlertAnswered> */
function answers(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof AlertAnswered));
}

it('chooses a GtkAlertDialog on the window and returns at once', function (): void {
    $window = openWindow();
    takeMail();

    $id = $window->alert(confirmation());
    pumpFor(0.3);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($window->isAlerting())->toBeTrue()
        ->and((fn (): ?GCancellable => $this->alert)->call($window))->toBeInstanceOf(GCancellable::class)
        ->and(answers(takeMail()))->toBe([]);
});

it('answers the cancel button when the dialog is cancelled, under the alert\'s id', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    pumpFor(0.3);

    (fn (): ?GCancellable => $this->alert)->call($window)->cancel();
    $answer = answers(mailUntil('AlertAnswered'));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->id)->toBe($id)
        ->and($answer[0]->window)->toBe('main')
        ->and($answer[0]->button)->toBe(1)
        ->and($window->isAlerting())->toBeFalse();
});

it('cancels its dialog and answers -1 when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = $window->alert(confirmation());
    pumpFor(0.3);

    $window->close();
    $mail = takeMail();
    pumpFor(0.3);

    expect(answers($mail))->toHaveCount(1)
        ->and(answers($mail)[0]->id)->toBe($id)
        ->and(answers($mail)[0]->button)->toBe(-1)
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(answers(takeMail()))->toBe([]);
});

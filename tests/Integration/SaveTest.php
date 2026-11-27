<?php

declare(strict_types=1);

use Surface\Contracts\Toolkits\Dialogs\SaveRequest;
use Surface\Contracts\Toolkits\Mail\Windows\SaveAnswered;

/*
| GtkFileDialog gives no way to its panel, nor a getter for what it was given, so a choice is
| the user's and its name, title and folder are not readable. These cover what code reaches:
| the panel chosen without waiting, its cancellation, the window's close.
*/

/** @return list<SaveAnswered> */
function saveAnswers(array $mail): array
{
    return array_values(array_filter($mail, fn (object $m): bool => $m instanceof SaveAnswered));
}

function openSave(object $window): string
{
    return $window->saveFile(new SaveRequest('report.txt', sys_get_temp_dir(), 'Save the report'));
}

it('shows a GtkFileDialog save panel on the window and returns at once', function (): void {
    $window = openWindow();
    takeMail();

    $id = openSave($window);
    pumpFor(0.3);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($window->isSaving())->toBeTrue()
        ->and((fn (): ?GCancellable => $this->save)->call($window))->toBeInstanceOf(GCancellable::class)
        ->and(saveAnswers(takeMail()))->toBe([]);
});

it('shows a panel for a request with no name, folder or title', function (): void {
    $window = openWindow();
    takeMail();

    $id = $window->saveFile(new SaveRequest());
    pumpFor(0.3);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and($window->isSaving())->toBeTrue();
});

it('answers null when the panel is cancelled, under the request\'s id', function (): void {
    $window = openWindow();
    takeMail();
    $id = openSave($window);
    pumpFor(0.3);

    (fn (): ?GCancellable => $this->save)->call($window)->cancel();
    $answer = saveAnswers(mailUntil('SaveAnswered'));

    expect($answer)->toHaveCount(1)
        ->and($answer[0]->id)->toBe($id)
        ->and($answer[0]->window)->toBe('main')
        ->and($answer[0]->path)->toBeNull()
        ->and($window->isSaving())->toBeFalse();
});

it('takes its panel down and answers null when the window closes first', function (): void {
    $window = openWindow();
    takeMail();
    $id = openSave($window);
    pumpFor(0.3);

    $window->close();
    $mail = takeMail();
    pumpFor(0.3);

    expect(saveAnswers($mail))->toHaveCount(1)
        ->and(saveAnswers($mail)[0]->id)->toBe($id)
        ->and(saveAnswers($mail)[0]->path)->toBeNull()
        ->and(mailNames($mail))->toContain('WindowClosed')
        ->and(saveAnswers(takeMail()))->toBe([])
        ->and((fn (): ?GCancellable => $this->save)->call($window))->toBeNull();
});

it('asks one thing at a time', function (): void {
    $window = openWindow();
    openSave($window);

    expect(fn () => openSave($window))->toThrow(Surface\Toolkits\ToolkitException::class, 'already shows a save panel');
});

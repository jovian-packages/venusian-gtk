<?php

declare(strict_types=1);

use Surface\Contracts\Windows\Mail\Services\AlertAnswered;
use Surface\Contracts\Windows\Mail\Services\FilesChosen;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Jovian\Toolkits\GTK\Services\GTKComposedAlert;
use Surface\Contracts\Windows\Services\Alert;
use Surface\Contracts\Windows\Services\AlertStyle;
use Surface\Contracts\Windows\Services\DialogCapability;
use Surface\Contracts\Windows\Services\OpenRequest;
use Surface\Contracts\Windows\Services\SaveRequest;
use Surface\Contracts\Windows\WindowException;

// GTK 4 cannot make a key event, and GtkAlertDialog keeps its window private: the answers by key
// and by button are checked by eye. These tests pin the request ids and the parent's close.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The dialog and close mail the session holds, in order, as short strings. */
function dialogMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof AlertAnswered => "alert:{$mail->button}" . ($mail->suppressed ? ':suppressed' : ''),
        $mail instanceof FilesChosen => 'files:' . implode(',', $mail->paths),
        $mail instanceof WindowClosed => "closed:{$mail->window}",
        $mail instanceof WindowCloseRequested => "requested:{$mail->window}",
        default => null,
    }, takeMail(session()))));
}

/** A shown main window. */
function shownMain(): Jovian\Toolkits\GTK\Windows\GTKWindow
{
    $main = driver()->open('main', 480, 320);
    $main->present();
    pumpFor(0.3);

    return $main;
}

it('shows an alert whose text looks like a format, and posts nothing', function (): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('100% %s done'));
    pumpFor(0.3);

    expect($id)->toMatch('/^[0-9a-f]{16}$/')
        ->and(dialogMail())->toBe([]);
    $main->close();
});

it('doubles _ in a button label, so GTK shows it and makes no mnemonic', function (): void {
    expect(Jovian\Toolkits\GTK\Services\GTKDialogs::buttonLabel('Use_Left'))->toBe('Use__Left');
});

it('answers -1 when the parent closes, then closes it, and nothing more', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?', 'Two files', ['Save', 'Discard', 'Cancel']));
    pumpFor(0.3);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:main']);
});

it('answers the alert of a child before the child and its parent close', function (): void {
    $main = shownMain();
    $note = driver()->open('note', 300, 160, null, $main);
    $note->present();
    pumpFor(0.3);
    driver()->dialogs()->alert($note, new Alert('Save?'));
    pumpFor(0.3);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:note', 'closed:main']);
});

it('refuses a second modal on a parent with an open dialog, and keeps the user close', function (): void {
    $main = shownMain();
    driver()->dialogs()->alert($main, new Alert('Save?'));
    pumpFor(0.3);
    $main->confirmClose();

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Again')))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->dialogs()->openFiles($main, new OpenRequest()))->toThrow(WindowException::class, "'main'")
        ->and(fn () => driver()->open('child', 300, 120, null, $main))->toThrow(WindowException::class, "'main'");

    $main->native()->close();
    pumpFor(0.2);

    expect(dialogMail())->toBe([])
        ->and($main->isOpen())->toBeTrue();
    $main->close();
});

it('refuses a dialog on a parent with an open child', function (): void {
    $main = driver()->open('main', 480, 320);
    driver()->open('child', 300, 120, null, $main);

    expect(fn () => driver()->dialogs()->alert($main, new Alert('Save?')))->toThrow(WindowException::class, "'main'");
});

it('answers [] when the parent of a file dialog closes, then closes it, and nothing more', function (string $kind): void {
    $main = shownMain();
    match ($kind) {
        'open' => driver()->dialogs()->openFiles($main, new OpenRequest('Pick', __DIR__)),
        'open several' => driver()->dialogs()->openFiles($main, new OpenRequest(multiple: true)),
        'folder' => driver()->dialogs()->openFiles($main, new OpenRequest(folders: true)),
        'save' => driver()->dialogs()->saveFile($main, new SaveRequest(null, __DIR__, 'c7-save.txt')),
    };
    pumpFor(0.5);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['files:', 'closed:main']);
})->with(['open', 'open several', 'folder', 'save']);

it('composes a styled alert with a suppression box and a title, modal to its parent', function (?AlertStyle $style, ?string $icon): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('Delete?', 'It cannot be undone.', ['Delete', 'Cancel'], style: $style, suppression: 'Don\'t ask again', title: 'Delete files'));
    pumpFor(0.3);
    $alert = driver()->dialogs()->native($id);

    expect($alert)->toBeInstanceOf(GTKComposedAlert::class)
        ->and($alert->window->getTitle())->toBe('Delete files')
        ->and($alert->window->getTransientFor())->toBe($main->native())
        ->and($alert->window->getModal())->toBeTrue()
        ->and($alert->image?->getIconName())->toBe($icon)
        ->and($alert->message->getText())->toBe('Delete?')
        ->and($alert->detail?->getText())->toBe('It cannot be undone.')
        ->and($alert->check?->getLabel())->toBe('Don\'t ask again')
        ->and($alert->check?->getActive())->toBeFalse()
        ->and(array_map(fn (GtkButton $button): ?string => $button->getLabel(), $alert->buttons))->toBe(['Delete', 'Cancel'])
        ->and($alert->window->getDefaultWidget())->toBe($alert->buttons[0])
        ->and(dialogMail())->toBe([]);
    $main->close();
})->with([
    'no style: no image, as the plain alert' => [null, null],
    'info' => [AlertStyle::Info, 'dialog-information'],
    'warning' => [AlertStyle::Warning, 'dialog-warning'],
    'critical' => [AlertStyle::Critical, 'dialog-error'],
]);

it('answers a composed alert\'s button with whether the box was ticked, then destroys it', function (bool $ticked): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('Delete?', null, ['Delete', 'Cancel'], style: AlertStyle::Warning, suppression: 'Don\'t ask again'));
    pumpFor(0.3);
    $alert = driver()->dialogs()->native($id);
    $alert->check->setActive($ticked);
    g_signal_emit_by_name($alert->buttons[0], 'clicked');
    pumpFor(0.2);

    expect(dialogMail())->toBe([$ticked ? 'alert:0:suppressed' : 'alert:0'])
        ->and(driver()->dialogs()->native($id))->toBeNull()
        ->and($alert->window->getVisible())->toBeFalse()
        ->and(driver()->dialogs()->openOn($main))->toBeNull();
    $main->close();
})->with(['ticked' => true, 'not ticked' => false]);

it('answers a composed alert\'s own close and Esc with the cancel button', function (string $how): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('Delete?', null, ['Delete', 'Keep', 'Cancel'], cancel: 1, title: 'Delete'));
    pumpFor(0.3);
    $alert = driver()->dialogs()->native($id);
    $how === 'close' ? $alert->window->close() : $alert->escapeAction()->activate(0, $alert->window, null);
    pumpFor(0.2);

    expect(dialogMail())->toBe(['alert:1'])
        ->and(driver()->dialogs()->openOn($main))->toBeNull();
    $main->close();
})->with(['close', 'Esc']);

it('answers a composed alert -1 and not suppressed when the parent closes, and destroys it', function (): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('Delete?', style: AlertStyle::Critical, suppression: 'Don\'t ask again'));
    pumpFor(0.3);
    $alert = driver()->dialogs()->native($id);
    $alert->check->setActive(true);
    $main->close();
    pumpFor(0.6);

    expect(dialogMail())->toBe(['alert:-1', 'closed:main'])
        ->and($alert->window->getVisible())->toBeFalse();
});

it('keeps GtkAlertDialog for an alert with no style, suppression or title', function (): void {
    $main = shownMain();
    $id = driver()->dialogs()->alert($main, new Alert('Save?'));
    pumpFor(0.3);

    expect(driver()->dialogs()->native($id))->toBeInstanceOf(GtkAlertDialog::class);
    $main->close();
});

it('titles alerts and has no hidden files', function (string $kind): void {
    $main = shownMain();

    expect(driver()->dialogs()->supports(DialogCapability::AlertTitle))->toBeTrue()
        ->and(driver()->dialogs()->supports(DialogCapability::ShowHidden))->toBeFalse()
        ->and(fn () => $kind === 'open'
            ? driver()->dialogs()->openFiles($main, new OpenRequest(showHidden: true))
            : driver()->dialogs()->saveFile($main, new SaveRequest(showHidden: true)))->toThrow(WindowException::class, 'hidden')
        ->and(driver()->dialogs()->openOn($main))->toBeNull();
    $main->close();
})->with(['open', 'save']);

it('gives a file dialog one GtkFileFilter for each filter, in order', function (string $kind): void {
    $main = shownMain();
    $filters = ['Text' => ['*.txt', '*.md'], 'Images' => ['*.png']];
    $id = $kind === 'open'
        ? driver()->dialogs()->openFiles($main, new OpenRequest(filters: $filters))
        : driver()->dialogs()->saveFile($main, new SaveRequest(filters: $filters));
    pumpFor(0.3);
    $model = driver()->dialogs()->native($id)->getFilters();

    expect($model->getNItems())->toBe(2)
        ->and($model->getItem(0))->toBeInstanceOf(GtkFileFilter::class)
        ->and($model->getItem(0)->getName())->toBe('Text')
        ->and($model->getItem(1)->getName())->toBe('Images');
    $main->close();
})->with(['open', 'save']);

it('shows a file dialog for a missing directory', function (): void {
    $main = shownMain();
    driver()->dialogs()->openFiles($main, new OpenRequest(directory: '/nonexistent-c7'));
    pumpFor(0.3);

    expect(dialogMail())->toBe([]);
    $main->close();
});

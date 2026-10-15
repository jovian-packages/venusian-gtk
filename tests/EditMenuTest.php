<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKMenuBar;
use Surface\Contracts\Windows\Menus\MenuRole;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

it('gives edit items no accelerator: the text widgets keep their own keys', function (): void {
    $window = driver()->open('main', 320, 200, driver()->profile('editing'));
    $app = session()->application();

    expect($app->getAccelsForAction('win.edit.copy'))->toBe([])
        ->and($app->getAccelsForAction('win.edit.select-all'))->toBe([])
        ->and($app->getAccelsForAction('win.app.quit'))->not->toBe([])
        ->and(takeMail(session()))->toBe([]);
});

it('selects all in the focused entry, and undoes and redoes a deletion of it, posting nothing', function (): void {
    $window = driver()->open('main', 360, 160, driver()->profile('editing'));
    $input = $window->column('c')->textInput('name', 'hello world');
    $window->present();
    pumpFor(0.2);
    expect($input->native()->grabFocus())->toBeTrue();
    $text = $window->native()->getFocus();

    choose($window, 'edit.select-all');
    g_signal_emit_by_name($text, 'delete-from-cursor', 0, 1);   // GTK_DELETE_CHARS: a selection is deleted whole
    $deleted = $input->native()->getBuffer()->getText();
    choose($window, 'edit.undo');
    $undone = $input->native()->getBuffer()->getText();
    choose($window, 'edit.redo');

    expect([$deleted, $undone, $input->native()->getBuffer()->getText()])->toBe(['', 'hello world', ''])
        ->and(array_filter(takeMail(session()), fn (object $m): bool => $m instanceof Surface\Contracts\Windows\Mail\MenuActivated))->toBe([]);
});

it('runs cut, copy and paste on the focused widget, and nothing with no focus', function (): void {
    // Nothing is selected, so cut and copy leave the system clipboard alone.
    $window = driver()->open('main', 360, 160, driver()->profile('editing'));
    $input = $window->column('c')->textInput('name', 'hello');
    $window->present();
    pumpFor(0.2);
    $input->native()->grabFocus();

    expect(GTKMenuBar::edit($window->native(), MenuRole::CUT))->toBeTrue()
        ->and(GTKMenuBar::edit($window->native(), MenuRole::COPY))->toBeTrue()
        ->and(GTKMenuBar::edit($window->native(), MenuRole::PASTE))->toBeTrue()
        ->and(GTKMenuBar::edit(null, MenuRole::COPY))->toBeFalse();
});

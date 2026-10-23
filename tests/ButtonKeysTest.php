<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\WindowCloseRequested;
use Surface\Contracts\Windows\Mail\WindowClosed;
use Surface\Contracts\Windows\Primitives\ButtonRole;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A window with a text input, a tree, a text area, an OK button (DEFAULT) and a Cancel button (CANCEL); presented. */
function rolesWindow(): GTKWindow
{
    $window = driver()->open('main', 480, 400);
    $column = $window->column('m');
    $column->textInput('input', '12');
    $column->tree('tree', [new TableColumn('name', 'Name')], [new TreeNode('a', ['name' => 'a'])]);
    $column->textArea('area', 'one');
    $column->button('ok', 'OK')->setRole(ButtonRole::DEFAULT);
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $window->present();
    pumpFor(0.3);
    takeMail(session());

    return $window;
}

/** Focus a primitive's native (the text view of a text area). */
function focusOn(GTKWindow $window, string $path): void
{
    $primitive = $window->view($path);
    $native = method_exists($primitive, 'textView') ? $primitive->textView() : $primitive->native();
    $native->grabFocus();
    pumpFor(0.05);
    takeMail(session());
}

/** The class and button of each view mail, in order, after GtkButton's 250 ms activate. */
function roleMail(): array
{
    pumpFor(0.4);

    return array_map(
        fn (object $mail): string => substr(strrchr(get_class($mail), '\\'), 1).($mail instanceof ButtonClicked ? ':'.$mail->path : ''),
        viewMail(session()),
    );
}

it('clicks the holder for Return and Esc, from a text input and from a tree', function (string $path): void {
    $window = rolesWindow();
    focusOn($window, $path);
    $keys = $window->buttonKeys();

    expect($keys->press('return'))->toBeTrue()
        ->and(roleMail())->toBe(['ButtonClicked:m.ok'])
        ->and($keys->press('escape'))->toBeTrue()
        ->and(roleMail())->toBe(['ButtonClicked:m.cancel']);
})->with(['m.input', 'm.tree']);

it('lets Return go on to a focused text area, and clicks the cancel button for Esc', function (): void {
    $window = rolesWindow();
    focusOn($window, 'm.area');
    $keys = $window->buttonKeys();

    expect($keys->press('return'))->toBeFalse()
        ->and($keys->press('escape'))->toBeTrue()
        ->and(roleMail())->toBe(['ButtonClicked:m.cancel']);
});

it('lets Return go on when the holder cannot take the key', function (string $case): void {
    $window = rolesWindow();
    $ok = $window->view('m.ok');
    if ($case === 'disabled') {
        $ok->setEnabled(false);
    } elseif ($case === 'hidden group') {
        $ok->remove();
        $window->content()->row('r')->button('ok2', 'OK')->setRole(ButtonRole::DEFAULT);
        $window->view('m.r')->hide();
    } else {
        $ok->remove();
        $tabs = $window->content()->tabs('tabs');
        $tabs->addPage('one', 'One');
        $tabs->addPage('two', 'Two')->button('ok3', 'OK')->setRole(ButtonRole::DEFAULT);
        $tabs->select('one');
    }
    pumpFor(0.2);
    focusOn($window, 'm.input');

    expect($window->buttonKeys()->press('return'))->toBeFalse()
        ->and(roleMail())->toBe([]);
})->with(['disabled', 'hidden group', 'tab not selected']);

it('makes the default holder the window default widget only while it shows', function (string $case): void {
    // GtkWindow's own Return binding (activate-default) checks sensitivity, not mapped: a hidden
    // holder that stayed the default widget would be clicked by a Return the focus does not take.
    $window = rolesWindow();
    $window->view('m.ok')->remove();
    if ($case === 'hidden group') {
        $window->content()->row('r')->button('ok2', 'OK')->setRole(ButtonRole::DEFAULT);
        $holder = $window->view('m.r.ok2');
        $hide = fn () => $window->view('m.r')->hide();
        $show = fn () => $window->view('m.r')->show();
    } else {
        $tabs = $window->content()->tabs('tabs');
        $tabs->addPage('one', 'One');
        $tabs->addPage('two', 'Two')->button('ok3', 'OK')->setRole(ButtonRole::DEFAULT);
        $holder = $window->view('m.tabs.two.ok3');
        $hide = fn () => $tabs->select('one');
        $show = fn () => $tabs->select('two');
    }
    $show();
    pumpFor(0.2);
    expect($window->native()->getDefaultWidget())->toBe($holder->native());

    $hide();
    pumpFor(0.2);
    expect($window->native()->getDefaultWidget())->toBeNull();

    $show();
    pumpFor(0.2);
    expect($window->native()->getDefaultWidget())->toBe($holder->native());
})->with(['hidden group', 'tab not selected']);

it('lets the keys go on to an open popover', function (): void {
    $window = rolesWindow();
    requireActive($window);
    $menu = GMenu::new();
    $menu->append('Item', 'win.nothing');
    $popover = GtkPopoverMenu::newFromModel($menu);
    $popover->setParent($window->view('m.ok')->native());
    $popover->popup();
    pumpFor(0.3);
    $focus = $window->native()->getFocus();
    $inside = false;
    for ($at = $focus; ! is_null($at); $at = $at->getParent()) {
        $inside = $inside || $at instanceof GtkPopover;
    }

    expect($inside)->toBeTrue()
        ->and($window->buttonKeys()->press('return'))->toBeFalse()
        ->and($window->buttonKeys()->press('escape'))->toBeFalse();

    // GTK on X11 moves the pointer focus off the popover surface only in the next pump: an unparent
    // before that leaves a dead surface that the next grab reads (GDK_IS_SURFACE criticals).
    $popover->popdown();
    pumpFor(0.1);
    $popover->unparent();
});

it('closes a child that has no cancel button on Esc, or asks when it confirms close', function (): void {
    $main = driver()->open('main', 480, 320);
    $note = driver()->open('note', 200, 100, null, $main);
    $note->column('m')->label('l', 'Note');
    $note->confirmClose()->present();
    pumpFor(0.2);
    takeMail(session());
    $closing = fn (): array => array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowCloseRequested || $mail instanceof WindowClosed));

    expect($note->buttonKeys()->press('escape'))->toBeTrue()
        ->and($closing())->toEqual([new WindowCloseRequested('note')])
        ->and($note->isOpen())->toBeTrue();

    $note->confirmClose(false);
    expect($note->buttonKeys()->press('escape'))->toBeTrue()
        ->and($closing())->toEqual([new WindowClosed('note')])
        ->and($note->isOpen())->toBeFalse();
});

it('makes the default holder the window default widget, and moves it with the role', function (): void {
    $window = rolesWindow();

    expect($window->native()->getDefaultWidget())->toBe($window->view('m.ok')->native());

    $window->content()->button('other', 'Other')->setRole(ButtonRole::DEFAULT);
    expect($window->native()->getDefaultWidget())->toBe($window->view('m.other')->native())
        ->and(viewMail(session()))->toBe([]);
});

it('clears the window default widget when the holder is removed, in a tab that is not selected too', function (): void {
    $window = rolesWindow();
    $window->view('m.ok')->remove();

    expect($window->native()->getDefaultWidget())->toBeNull();

    $tabs = $window->content()->tabs('tabs');
    $tabs->addPage('one', 'One');
    $tabs->addPage('two', 'Two')->button('ok3', 'OK')->setRole(ButtonRole::DEFAULT);
    $tabs->select('one');
    pumpFor(0.1);
    $tabs->remove();

    expect($window->native()->getDefaultWidget())->toBeNull();
});

it('clicks the holder through the Return shortcut action, the real path', function (): void {
    $window = rolesWindow();
    focusOn($window, 'm.input');

    expect($window->buttonKeys()->returnAction()->activate(0, $window->native(), null))->toBeTrue()
        ->and(roleMail())->toBe(['ButtonClicked:m.ok']);
});

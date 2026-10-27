<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\HumanInput\Modifiers;
use Surface\Contracts\Windows\Input\EditCommand;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\EditCommanded;
use Surface\Contracts\Windows\Mail\View\KeyPressed;
use Surface\Contracts\Windows\Mail\View\KeyReleased;
use Surface\Contracts\Windows\Mail\View\TextCommitted;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;
use Surface\Contracts\Windows\Primitives\ButtonRole;

// GTK 4 cannot make a key event: these tests emit key-pressed, key-released and commit on the
// canvas's own controller and input method context, so they prove the handlers and their answers,
// not GTK's propagation (checked by eye). Keyvals: a 0x61, Left 0xFF51, Up 0xFF52, Home 0xFF50,
// BackSpace 0xFF08, Return 0xFF0D, Escape 0xFF1B, Tab 0xFF09, ISO_Left_Tab 0xFE20, Shift_L 0xFFE1.

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with two text inputs, a focusable canvas `map` and a canvas `editor` that takes Tab; `editor` focused. */
function canvasKeysWindow(): GTKWindow
{
    $main = driver()->open('main', 480, 400);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $column->canvas('map')->minSize(200, 60)->setFocusable();
    $column->canvas('editor')->minSize(200, 60)->setFocusable(true, takesTab: true);
    $main->present();
    pumpFor(0.2);
    $main->view('m.editor')->focus();
    pumpFor(0.05);
    takeMail(session());

    return $main;
}

/** The hardware keycode of a key here: the macOS key code, or the evdev code + 8. */
function keycodeFor(string $key): int
{
    $macos = PHP_OS_FAMILY === 'Darwin';

    return match ($key) {
        'a' => keycodeOf('a'),
        'tab' => $macos ? 48 : 15 + 8,
        'left' => $macos ? 123 : 105 + 8,
        'shift_l' => $macos ? 56 : 42 + 8,
    };
}

/** The canvas key mail the session holds, in order, plus any WindowKeyPressed. */
function canvasKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof KeyReleased
        || $mail instanceof TextCommitted || $mail instanceof EditCommanded || $mail instanceof WindowKeyPressed));
}

function emitKey(GTKWindow $main, string $path, int $keyval, int $keycode = 0, int $state = 0): bool
{
    return g_signal_emit_by_name($main->view($path)->keys()->controller(), 'key-pressed', $keyval, $keycode, $state);
}

function emitRelease(GTKWindow $main, string $path, int $keyval, int $keycode = 0, int $state = 0): void
{
    g_signal_emit_by_name($main->view($path)->keys()->controller(), 'key-released', $keyval, $keycode, $state);
}

it('posts the key and its text for a letter, and the release', function (): void {
    $main = canvasKeysWindow();
    $editor = $main->view('m.editor');

    expect(emitKey($main, 'm.editor', 0x61, keycodeFor('a')))->toBeTrue();
    // key-released is a void signal: its emit answers nothing.
    g_signal_emit_by_name($editor->keys()->controller(), 'key-released', 0x61, keycodeFor('a'), 0);
    expect(canvasKeys())->toEqual([
            new KeyPressed('main', 'm.editor', $editor->uuid(), Key::A, 'a', new Modifiers(), false),
            new KeyReleased('main', 'm.editor', $editor->uuid(), Key::A, new Modifiers()),
        ]);
    $main->close();
});

it('posts the text the input method commits', function (): void {
    $main = canvasKeysWindow();
    $editor = $main->view('m.editor');
    g_signal_emit_by_name($editor->keys()->imContext(), 'commit', 'é');

    expect(canvasKeys())->toEqual([new TextCommitted('main', 'm.editor', $editor->uuid(), 'é')]);
    $main->close();
});

it('posts the platform\'s edit command for a bound key', function (int $keyval, int $state, EditCommand $command): void {
    $main = canvasKeysWindow();

    expect(emitKey($main, 'm.editor', $keyval, 0, $state))->toBeTrue();
    $commands = array_values(array_filter(canvasKeys(), fn (object $mail): bool => $mail instanceof EditCommanded));
    expect($commands)->toHaveCount(1)
        ->and($commands[0]->command)->toBe($command)
        ->and($commands[0]->path)->toBe('m.editor');
    $main->close();
})->with(function (): array {
    $macos = PHP_OS_FAMILY === 'Darwin';

    return [
        'Left' => [0xFF51, 0, EditCommand::MoveLeft],
        'Shift+Left' => [0xFF51, GDK_SHIFT_MASK, EditCommand::SelectLeft],
        'the word move' => [0xFF51, $macos ? GDK_ALT_MASK : GDK_CONTROL_MASK, EditCommand::MoveWordLeft],
        'Home' => [0xFF50, 0, EditCommand::MoveToLineStart],
        'the line move' => $macos ? [0xFF51, GDK_META_MASK, EditCommand::MoveToLineStart] : [0xFF57, 0, EditCommand::MoveToLineEnd],
        'the document move' => $macos ? [0xFF52, GDK_META_MASK, EditCommand::MoveToDocumentStart] : [0xFF50, GDK_CONTROL_MASK, EditCommand::MoveToDocumentStart],
        'BackSpace' => [0xFF08, 0, EditCommand::DeleteBackward],
        'the word delete' => [0xFF08, $macos ? GDK_ALT_MASK : GDK_CONTROL_MASK, EditCommand::DeleteWordBackward],
        'Return' => [0xFF0D, 0, EditCommand::InsertNewline],
        'Escape' => [0xFF1B, 0, EditCommand::Cancel],
        'Tab' => [0xFF09, 0, EditCommand::InsertTab],
        'ISO_Left_Tab' => [0xFE20, GDK_SHIFT_MASK, EditCommand::InsertBacktab],
        'select all' => [0x61, $macos ? GDK_META_MASK : GDK_CONTROL_MASK, EditCommand::SelectAll],
        'redo' => [0x7A, ($macos ? GDK_META_MASK : GDK_CONTROL_MASK) | GDK_SHIFT_MASK, EditCommand::Redo],
    ];
});

it('posts KeyPressed before the command and no text for a bound key', function (): void {
    $main = canvasKeysWindow();
    emitKey($main, 'm.editor', 0xFF09, keycodeFor('tab'));

    $mail = canvasKeys();
    expect(array_map(fn (object $mail): string => $mail::class, $mail))->toBe([KeyPressed::class, EditCommanded::class])
        ->and($mail[0]->key)->toBe(Key::TAB)
        ->and($mail[0]->character)->toBe('');
    $main->close();
});

it('posts a lone modifier\'s press with its modifier and its release without, from GTK\'s state before the key', function (): void {
    $main = canvasKeysWindow();
    $editor = $main->view('m.editor');

    expect(emitKey($main, 'm.editor', 0xFFE1, keycodeFor('shift_l')))->toBeTrue();
    emitRelease($main, 'm.editor', 0xFFE1, keycodeFor('shift_l'), GDK_SHIFT_MASK);
    expect(canvasKeys())->toEqual([
        new KeyPressed('main', 'm.editor', $editor->uuid(), Key::LEFT_SHIFT, '', new Modifiers(shift: true), false),
        new KeyReleased('main', 'm.editor', $editor->uuid(), Key::LEFT_SHIFT, new Modifiers()),
    ]);
    $main->close();
});

it('posts a release only for a key whose press it posted, once', function (): void {
    $main = canvasKeysWindow();
    $main->view('m.map')->focus();
    pumpFor(0.05);
    takeMail(session());

    // Tab was let through on the press, so its release is not the canvas's either.
    emitKey($main, 'm.map', 0xFF09, keycodeFor('tab'));
    emitRelease($main, 'm.map', 0xFF09, keycodeFor('tab'));
    emitKey($main, 'm.map', 0x61, keycodeFor('a'));
    emitRelease($main, 'm.map', 0x61, keycodeFor('a'));
    emitRelease($main, 'm.map', 0x61, keycodeFor('a'));

    expect(array_map(fn (object $mail): string => $mail::class, canvasKeys()))->toBe([KeyPressed::class, KeyReleased::class]);
    $main->close();
});

it('Tab leaves a canvas that does not take it, posting no canvas mail', function (): void {
    $main = canvasKeysWindow();
    $main->view('m.map')->focus();
    pumpFor(0.05);
    takeMail(session());

    expect(emitKey($main, 'm.map', 0xFF09, keycodeFor('tab')))->toBeFalse()
        ->and(emitKey($main, 'm.map', 0xFE20, keycodeFor('tab'), GDK_SHIFT_MASK))->toBeFalse()
        ->and(canvasKeys())->toBe([]);
    $main->close();
});

it('declines a key on a canvas that is not focused, posting nothing', function (): void {
    $main = canvasKeysWindow();

    expect(emitKey($main, 'm.map', 0x61, keycodeFor('a')))->toBeFalse()
        ->and(canvasKeys())->toBe([]);
    $main->close();
});

it('a role key goes to its button through the window\'s role controller, not to the focused canvas', function (): void {
    $main = driver()->open('main', 480, 320);
    $main->column('m')->label('title', 'Main');
    $child = driver()->open('child', 300, 160, null, $main);
    $column = $child->column('c');
    $column->canvas('pad')->minSize(100, 40)->setFocusable();
    $column->button('cancel', 'Cancel')->setRole(ButtonRole::CANCEL);
    $child->present();
    pumpFor(0.3);
    $child->view('c.pad')->focus();
    pumpFor(0.05);
    takeMail(session());

    expect($child->buttonKeys()->press('escape'))->toBeTrue();
    pumpFor(0.4);
    $mail = takeMail(session());
    expect(array_values(array_filter($mail, fn (object $mail): bool => $mail instanceof ButtonClicked)))->toHaveCount(1)
        ->and(array_filter($mail, fn (object $mail): bool => $mail instanceof KeyPressed || $mail instanceof EditCommanded))->toBe([]);
    $main->close();
});

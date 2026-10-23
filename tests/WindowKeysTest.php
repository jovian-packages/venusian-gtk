<?php

declare(strict_types=1);

use Surface\Contracts\HumanInput\Key;
use Surface\Contracts\Windows\Mail\WindowKeyPressed;

// GTK 4 cannot make a key event: these tests emit key-pressed on the window's own bubble-phase
// controller, so they prove the handler and its answer, not GTK's propagation (checked by eye).

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The window key mail the session holds, in order. */
function windowKeys(): array
{
    return array_values(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof WindowKeyPressed));
}

/** The F5 key's hardware keycode here: the macOS key code, or the evdev code + 8. */
function f5Keycode(): int
{
    return PHP_OS_FAMILY === 'Darwin' ? 96 : 63 + 8;
}

it('listens on the window in the bubble phase', function (): void {
    $main = driver()->open('main', 320, 200);

    expect($main->windowKeys()->controller()->getPropagationPhase())->toBe(GtkPropagationPhase::BUBBLE);
    $main->close();
});

it('posts a key no widget took, with its physical key and no text for a function key', function (): void {
    $main = driver()->open('main', 320, 200);
    $handled = g_signal_emit_by_name($main->windowKeys()->controller(), 'key-pressed', 0xFFC2, f5Keycode(), GDK_CONTROL_MASK);
    $mail = windowKeys();

    expect($handled)->toBeTrue()
        ->and($mail)->toHaveCount(1)
        ->and($mail[0]->window)->toBe('main')
        ->and($mail[0]->key)->toBe(Key::F5)
        ->and($mail[0]->character)->toBe('')
        ->and($mail[0]->modifiers->ctrl)->toBeTrue()
        ->and($mail[0]->repeat)->toBeFalse();
    $main->close();
});

it('gives a printable key its text', function (): void {
    $main = driver()->open('main', 320, 200);
    g_signal_emit_by_name($main->windowKeys()->controller(), 'key-pressed', 0x61, keycodeOf('a'), 0);

    expect(windowKeys()[0]->character)->toBe('a')
        ->and(windowKeys())->toBe([]);
    $main->close();
});

it('posts nothing for a modifier pressed alone, as AppKit sends none', function (int $keyval): void {
    $main = driver()->open('main', 320, 200);
    $handled = g_signal_emit_by_name($main->windowKeys()->controller(), 'key-pressed', $keyval, 0, 0);

    expect($handled)->toBeFalse()
        ->and(windowKeys())->toBe([]);
    $main->close();
})->with(['Shift_L' => 0xFFE1, 'Control_R' => 0xFFE4, 'Caps_Lock' => 0xFFE5, 'Meta_L' => 0xFFE7, 'Alt_L' => 0xFFE9, 'Super_L' => 0xFFEB, 'ISO_Level3_Shift' => 0xFE03]);

it('lets Tab and Shift-Tab through to move focus, posting nothing', function (int $keyval): void {
    $main = driver()->open('main', 320, 200);
    $handled = g_signal_emit_by_name($main->windowKeys()->controller(), 'key-pressed', $keyval, 0, 0);

    expect($handled)->toBeFalse()
        ->and(windowKeys())->toBe([]);
    $main->close();
})->with(['Tab' => 0xFF09, 'Shift-Tab' => 0xFE20]);

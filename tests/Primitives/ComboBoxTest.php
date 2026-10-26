<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKComboBox;
use Jovian\Toolkits\GTK\Windows\GTKMacTextKeys;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;

const COMBO_RETURN = 0xff0d;

const COMBO_ESCAPE = 0xff1b;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

afterEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** A shown window with a combo and a text input, the combo focused, the mail drained. */
function comboWindow(): array
{
    $window = driver()->open('main', 360, 220);
    $main = $window->column('m');
    $combo = $main->comboBox('path', '/tmp', ['/Users', '/Volumes']);
    $other = $main->textInput('other', 'x');
    $window->present();
    requireActive($window);
    $combo->focus();
    pumpFor(0.1);
    takeMail(session());

    return [$window, $combo, $other];
}

/** A key through the combo's capture-phase key controller, as GTK would call it; whether it stopped the key. */
function comboKey(GTKComboBox $combo, int $keyval): bool
{
    return g_signal_emit_by_name($combo->keys(), 'key-pressed', $keyval, 0, 0);
}

it('is a linked box of an entry and a menu button listing the options, and posts nothing for code', function (): void {
    [$window, $combo] = comboWindow();

    $combo->setValue('/Volumes/x');
    $combo->setOptions(['/a', '/b', '/c']);

    expect($combo->native())->toBeInstanceOf(GtkBox::class)
        ->and($combo->native()->hasCssClass('linked'))->toBeTrue()
        ->and($combo->entry()->getBuffer()->getText())->toBe('/Volumes/x')
        ->and($combo->optionsButton())->toBeInstanceOf(GtkMenuButton::class)
        ->and($combo->optionRows())->toBe(['/a', '/b', '/c'])
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('posts TextChanged for typing', function (): void {
    [$window, $combo] = comboWindow();

    $combo->entry()->getBuffer()->setText('/tmpa', -1);

    expect(takeMail(session()))->toEqual([new TextChanged('main', 'm.path', $combo->uuid(), '/tmpa')])
        ->and($combo->value())->toBe('/tmpa');
    $window->close();
});

it('shows completions under the focused field, and drops them on a field without focus', function (): void {
    [$window, $combo, $other] = comboWindow();

    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    expect($combo->completionList()->isShown())->toBeTrue()
        ->and($combo->completionList()->popover()->getMapped())->toBeTrue()
        ->and($combo->completionList()->popover()->getParent())->toBe($combo->entry())
        ->and($combo->completionList()->popover()->getAutohide())->toBeFalse()
        ->and($combo->completionList()->rows())->toBe(['/Users/angel', '/Users/Shared'])
        ->and($combo->completions())->toBe(['/Users/angel', '/Users/Shared'])
        ->and($combo->hasFocus())->toBeTrue();

    $combo->setCompletions([]);
    pumpFor(0.1);
    expect($combo->completionList()->isShown())->toBeFalse();

    $other->focus();
    pumpFor(0.1);
    $combo->setCompletions(['/Users/angel']);
    expect($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('chooses the highlighted completion with Down and Return', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);

    expect(comboKey($combo, GTKMacTextKeys::DOWN))->toBeTrue()
        ->and($combo->completionList()->highlighted())->toBe(0)
        ->and(comboKey($combo, COMBO_RETURN))->toBeTrue();

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), -1, '/Users/angel')])
        ->and($combo->value())->toBe('/Users/angel')
        ->and($combo->entry()->getBuffer()->getText())->toBe('/Users/angel')
        ->and($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([]);
    $window->close();
});

it('lets Return through to activate, which posts TextSubmitted, with no completion highlighted', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    expect(comboKey($combo, COMBO_RETURN))->toBeFalse();
    g_signal_emit_by_name($combo->entry(), 'activate');

    expect(takeMail(session()))->toEqual([new TextSubmitted('main', 'm.path', $combo->uuid(), '/tmp')])
        ->and($combo->completionList()->isShown())->toBeFalse();
    $window->close();
});

it('lets every key through while no completions show', function (): void {
    [$window, $combo] = comboWindow();

    expect(comboKey($combo, GTKMacTextKeys::DOWN))->toBeFalse()
        ->and(comboKey($combo, COMBO_ESCAPE))->toBeFalse();
    $window->close();
});

it('hides on focus loss, with no mail', function (): void {
    [$window, $combo, $other] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    $other->native()->grabFocus();
    pumpFor(0.1);

    expect($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof SelectionChanged || $mail instanceof TextSubmitted))->toBe([]);
    $window->close();
});

it('keeps focus in the entry when a click on the completions reaches for it', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);

    // A click on a row grabs focus for the row's item widget.
    expect($combo->completionList()->view()->getFirstChild()->grabFocus())->toBeFalse();
    pumpFor(0.1);

    expect($combo->hasFocus())->toBeTrue()
        ->and($combo->completionList()->isShown())->toBeTrue()
        ->and(array_filter(takeMail(session()), fn (object $mail): bool => $mail instanceof FocusLost))->toBe([]);
    $window->close();
});

it('replaces shown rows with nothing highlighted and no mail', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel', '/Users/Shared']);
    pumpFor(0.1);
    comboKey($combo, GTKMacTextKeys::DOWN);

    $combo->setCompletions(['/Volumes/a']);
    pumpFor(0.1);

    expect($combo->completionList()->rows())->toBe(['/Volumes/a'])
        ->and($combo->completionList()->highlighted())->toBe(-1)
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('shows one completion one row tall', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpUntil(fn (): bool => $combo->completionList()->content()->getHeight() > 0);
    $scroll = $combo->completionList()->content();

    expect($scroll->getHeight())->toBe($combo->completionList()->view()->measure(GtkOrientation::VERTICAL, $scroll->getWidth())['natural']);
    $window->close();
});

it('shows at most eight rows, and scrolls a highlight past them into view', function (): void {
    [$window, $combo] = comboWindow();
    $rows = array_map(fn (int $i): string => "/Users/u{$i}", range(1, 20));
    $combo->setCompletions($rows);
    pumpUntil(fn (): bool => $combo->completionList()->content()->getHeight() > 0);
    $scroll = $combo->completionList()->content();
    $adjustment = $scroll->getVadjustment();
    $row = ($adjustment->getUpper() - $adjustment->getLower()) / 20;

    expect($scroll->getHeight())->toBe((int) ceil($row * 8));

    for ($i = 0; $i < 12; $i++) {
        comboKey($combo, GTKMacTextKeys::DOWN);
    }
    pumpFor(0.1);

    expect($combo->completionList()->highlighted())->toBe(11)
        ->and($adjustment->getValue() + $adjustment->getPageSize())->toEqualWithDelta(12 * $row, 0.5);
    $window->close();
});

it('chooses the completion a click lands on, and does not highlight the row under the pointer', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(array_map(fn (int $i): string => "/Users/u{$i}", range(1, 20)));
    pumpUntil(fn (): bool => $combo->completionList()->content()->getHeight() > 0);
    $adjustment = $combo->completionList()->content()->getVadjustment();
    $row = ($adjustment->getUpper() - $adjustment->getLower()) / 20;
    $adjustment->setValue($adjustment->getLower() + 5 * $row);
    pumpFor(0.1);

    expect($combo->completionList()->view()->getSingleClickActivate())->toBeFalse();

    g_signal_emit_by_name($combo->completionList()->clicks(), 'released', 1, 10.0, 2.5 * $row);
    pumpFor(0.1);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), -1, '/Users/u8')])
        ->and($combo->completionList()->isShown())->toBeFalse();
    $window->close();
});

it('hides on Escape', function (): void {
    [$window, $combo] = comboWindow();
    $combo->setCompletions(['/Users/angel']);
    pumpFor(0.1);

    expect(comboKey($combo, COMBO_ESCAPE))->toBeTrue()
        ->and($combo->completionList()->isShown())->toBeFalse()
        ->and($combo->completions())->toBe([])
        ->and(takeMail(session()))->toBe([]);
    $window->close();
});

it('opens the options list tall enough for every option it was made with', function (): void {
    $window = driver()->open('main', 360, 220);
    $combo = $window->column('m')->comboBox('path', '/tmp', ['/home/angel', '/tmp', '/']);
    $window->present();
    requireActive($window);
    pumpFor(0.1);

    $combo->optionsButton()->popup();
    pumpUntil(fn (): bool => $combo->optionsButton()->getPopover()->getMapped());
    pumpFor(0.1);
    $adjustment = $combo->optionsList()->getParent()->getVadjustment();

    expect($adjustment->getUpper() - $adjustment->getLower())->toBeGreaterThan(0.0)
        ->and($adjustment->getPageSize())->toBeGreaterThanOrEqual($adjustment->getUpper() - $adjustment->getLower());
    $combo->optionsButton()->popdown();
    // GTK's tooltip hover timer (500 ms) starts on the popover's crossing and outlives a closed window.
    pumpFor(0.6);
    $window->close();
});

it('posts the option index for a choice from the options list', function (): void {
    [$window, $combo] = comboWindow();

    g_signal_emit_by_name($combo->optionsList(), 'activate', 1);
    pumpFor(0.1);

    expect(takeMail(session()))->toEqual([new SelectionChanged('main', 'm.path', $combo->uuid(), 1, '/Volumes')])
        ->and($combo->value())->toBe('/Volumes')
        ->and($combo->entry()->getBuffer()->getText())->toBe('/Volumes');
    $window->close();
});

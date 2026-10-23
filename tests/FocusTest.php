<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\FocusGained;
use Surface\Contracts\Windows\Mail\View\FocusLost;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** The focus mail the session holds, in order, as short strings. */
function focusMail(): array
{
    return array_values(array_filter(array_map(fn (object $mail): ?string => match (true) {
        $mail instanceof FocusGained => "gained:{$mail->path}",
        $mail instanceof FocusLost => "lost:{$mail->path}",
        default => null,
    }, takeMail(session()))));
}

/** A shown window with two text inputs. */
function focusWindow(): GTKWindow
{
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $column->textInput('second');
    $main->present();
    pumpFor(0.3);

    return $main;
}

it('focuses a shown input through GTK and posts nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);

    $focus = $main->native()->getFocus();
    expect($focus === $first->native() || $focus?->getParent() === $first->native())->toBeTrue()
        ->and($first->hasFocus())->toBeTrue()
        ->and($main->view('m.second')->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('focuses the inner view of a text area and a table', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $area = $column->textArea('area');
    $table = $column->table('table', [new TableColumn('name', 'Name')], [['name' => 'a']]);
    $main->present();
    pumpFor(0.3);

    $area->focus();
    pumpFor(0.1);
    expect($main->native()->getFocus())->toBe($area->textView())
        ->and($area->hasFocus())->toBeTrue();

    $table->focus();
    pumpFor(0.1);
    expect($table->hasFocus())->toBeTrue()
        ->and($main->focused())->toBe($table);
    $main->close();
});

it('refuses focus to a canvas until it is focusable', function (): void {
    $main = driver()->open('main', 480, 320);
    $canvas = $main->column('m')->canvas('map');
    $main->present();
    pumpFor(0.3);

    expect(fn () => $canvas->focus())->toThrow(WindowException::class, "'m.map' cannot take focus.");

    $canvas->setFocusable();
    $canvas->focus();
    pumpFor(0.1);
    expect($canvas->hasFocus())->toBeTrue()
        ->and(g_object_get_property($canvas->native(), 'focusable'))->toBeTrue();
    $main->close();
});

it('stops Tab on a focusable canvas, and passes a default one', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $input = $column->textInput('input');
    $plain = $column->canvas('plain');
    $map = $column->canvas('map')->setFocusable();
    $main->present();
    pumpFor(0.3);
    $input->focus();
    pumpFor(0.1);

    // GTK's own Tab move: the window moves focus forward from its focus widget.
    $main->native()->childFocus(GtkDirectionType::TAB_FORWARD);
    pumpFor(0.1);

    expect($map->hasFocus())->toBeTrue()
        ->and($plain->hasFocus())->toBeFalse()
        ->and($main->focused())->toBe($map);
    $main->close();
});

it('keeps Tab on a canvas that takes it, and lets it through otherwise', function (): void {
    $main = driver()->open('main', 480, 320);
    $canvas = $main->column('m')->canvas('editor');
    $canvas->setFocusable(true, takesTab: true);

    expect(g_signal_emit_by_name($canvas->tabKeys(), 'key-pressed', 0xFF09, 0, 0))->toBeTrue()
        ->and(g_signal_emit_by_name($canvas->tabKeys(), 'key-pressed', 0xFE20, 0, 0))->toBeTrue()
        ->and(g_signal_emit_by_name($canvas->tabKeys(), 'key-pressed', 0x61, 0, 0))->toBeFalse()
        ->and($canvas->tabKeys()->getPropagationPhase())->toBe(GtkPropagationPhase::CAPTURE);

    $canvas->setFocusable(true);
    expect(g_signal_emit_by_name($canvas->tabKeys(), 'key-pressed', 0xFF09, 0, 0))->toBeFalse();
    $main->close();
});

it('posts the user\'s focus change once, after a focus() that posted nothing', function (): void {
    $main = focusWindow();
    $main->view('m.first')->focus();
    pumpFor(0.2);
    expect(focusMail())->toBe([]);

    // The user's move: GTK itself moves focus, not focus().
    $main->view('m.second')->native()->grabFocus();
    pumpFor(0.2);

    expect(focusMail())->toBe(['lost:m.first', 'gained:m.second']);
    $main->close();
});

it('focuses the initial primitive on present, posting nothing', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $column->textInput('first');
    $second = $column->textInput('second');
    $main->setInitialFocus($second);
    $main->present();
    pumpFor(0.3);

    expect($second->hasFocus())->toBeTrue()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('posts nothing when code hides or disables the focused input', function (string $change): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);
    $change === 'hide' ? $first->hide() : $first->setEnabled(false);
    pumpFor(0.2);

    expect($first->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('posts nothing when code hides the only input that takes focus', function (): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $only = $column->textInput('only');
    $column->label('note', 'Note');
    $main->present();
    pumpFor(0.3);
    $only->focus();
    pumpFor(0.2);
    takeMail(session());
    $only->hide();
    pumpFor(0.3);

    expect($main->focused())->toBeNull()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('posts nothing when code hides or disables the container of the focused input', function (string $change): void {
    $main = driver()->open('main', 480, 320);
    $column = $main->column('m');
    $first = $column->row('r')->textInput('first');
    $column->textInput('second');
    $main->present();
    pumpFor(0.3);
    $first->focus();
    pumpFor(0.2);
    takeMail(session());
    $row = $main->view('m.r');
    $change === 'hide' ? $row->hide() : $row->setEnabled(false);
    pumpFor(0.3);

    expect($first->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
})->with(['hide', 'disable']);

it('lets go of a focused canvas that stops being focusable, posting nothing', function (): void {
    $main = focusWindow();
    $canvas = $main->view('m')->canvas('map')->setFocusable();
    pumpFor(0.2);
    $canvas->focus();
    pumpFor(0.2);
    takeMail(session());
    $canvas->setFocusable(false);
    pumpFor(0.3);

    expect($canvas->hasFocus())->toBeFalse()
        ->and(focusMail())->toBe([]);
    $main->close();
});

it('refuses an initial focus from another window', function (): void {
    $main = focusWindow();
    $other = driver()->open('other', 200, 100);
    $input = $other->column('o')->textInput('x');

    expect(fn () => $main->setInitialFocus($input))->toThrow(WindowException::class, "'o.x' is not in window 'main'.");
    $other->close();
    $main->close();
});

it('forgets a focused primitive that is removed, posting nothing', function (): void {
    $main = focusWindow();
    $first = $main->view('m.first');
    $first->focus();
    pumpFor(0.2);
    takeMail(session());
    $first->remove();
    pumpFor(0.3);

    expect($main->focused())->not->toBe($first)
        ->and(focusMail())->toBe([]);
    $main->close();
});

<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKTable;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** Every label's text under $widget, depth first: headers, then the bound cells. */
function labelTexts(GtkWidget $widget): array
{
    $texts = $widget instanceof GtkLabel ? [$widget->getText()] : [];
    for ($child = $widget->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
        $texts = [...$texts, ...labelTexts($child)];
    }

    return $texts;
}

it('shows rows, normalises missing cells, and posts RowSelected', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('title', 'Title'), new TableColumn('year', 'Year')], [['title' => 'a', 'year' => '1'], ['title' => 'b']]);
    $table->fill();
    $window->present();
    pumpFor(0.2);
    viewMail(session());
    $table->selectRow(1);
    pumpFor(0.1);

    expect($table)->toBeInstanceOf(GTKTable::class)
        ->and($table->native())->toBeInstanceOf(GtkScrolledWindow::class)
        ->and($table->columnView()->getModel())->toBe($table->selection())
        ->and(labelTexts($table->columnView()))->toContain('Title', 'Year', 'a', '1', 'b')
        ->and($table->rows()[1])->toBe(['title' => 'b', 'year' => ''])
        ->and($table->selectedRow())->toBe(1)
        ->and($table->selection()->getSelected())->toBe(1)
        ->and(viewMail(session()))->toEqual([])            // selectRow from code posts nothing
        ->and(fn () => $table->selectRow(5))->toThrow(WindowException::class);

    $table->selection()->setSelected(0);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new RowSelected('main', 'm.t', $table->uuid(), 0, ['title' => 'a', 'year' => '1'])]);

    $table->appendRow(['title' => 'c', 'year' => '3']);
    pumpFor(0.1);
    expect($table->selection()->getModel()->getNItems())->toBe(3)
        ->and($table->selectedRow())->toBe(0)
        ->and($table->selection()->getSelected())->toBe(0)
        ->and(labelTexts($table->columnView()))->toContain('c', '3')
        ->and(viewMail(session()))->toBe([]);
});

it('splices rows from the first change on: one item for an append, one change per rebuild', function (): void {
    $rows = array_map(fn (int $i): array => ['n' => "r{$i}"], range(0, 499));
    $table = driver()->open('main', 400, 300)->column('m')->table('t', [new TableColumn('n', 'N')], $rows);
    $changes = [];
    g_signal_connect($table->selection()->getModel(), 'items-changed', function (GObject $list, int $position, int $removed, int $added) use (&$changes): void {
        $changes[] = [$position, $removed, $added];
    });

    $table->appendRow(['n' => 'r500']);
    $table->appendRow(['n' => 'r501']);
    $rows[1] = ['n' => 'changed'];
    $table->setRows($rows);
    $table->setRows($rows);

    expect($changes)->toBe([[500, 0, 1], [501, 0, 1], [1, 501, 499]])
        ->and($table->selection()->getModel()->getNItems())->toBe(500);
});

it('paints a table background on the column view the theme paints', function (): void {
    $table = driver()->open('main', 400, 300)->column('m')->table('t', [new TableColumn('n', 'N')]);
    $table->setBackground(Color::rgb(0, 128, 0));

    expect($table->stylesheet())->toContain(".{$table->styleClass()} > columnview { background-color: rgba(0, 128, 0, 1); background-image: none; }")
        ->and($table->stylesheet())->toContain(".{$table->styleClass()} > columnview > listview { background-color: rgba(0, 128, 0, 1); background-image: none; }");
});

it('replaces and clears rows, dropping the selection without mail', function (): void {
    $window = driver()->open('main', 400, 300);
    $table = $window->column('m')->table('t', [new TableColumn('n', 'N')], [['n' => 'x'], ['n' => 'y']]);
    $table->fill();
    $window->present();
    pumpFor(0.2);
    $table->selectRow(1);
    viewMail(session());

    $table->setRows([['n' => 'p'], ['n' => 'q'], ['n' => 'r']]);
    pumpFor(0.1);
    expect($table->selectedRow())->toBeNull()
        ->and($table->selection()->getSelected())->toBe(GTK_INVALID_LIST_POSITION)
        ->and(labelTexts($table->columnView()))->toContain('p', 'q', 'r')->not->toContain('x')
        ->and(viewMail(session()))->toBe([]);

    $table->clearRows();
    expect($table->selection()->getModel()->getNItems())->toBe(0);

    // The user clearing a selection posts a null row.
    $table->setRows([['n' => 'z']])->selectRow(0);
    $table->selection()->setSelected(GTK_INVALID_LIST_POSITION);
    expect(viewMail(session()))->toEqual([new RowSelected('main', 'm.t', $table->uuid(), null, null)])
        ->and($table->selectedRow())->toBeNull();
});

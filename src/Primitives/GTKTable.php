<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GObject;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\RowSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTable;

/**
 * A table: a GtkColumnView inside a GtkScrolledWindow (the primitive's widget), over a
 * GtkSingleSelection of a GtkStringList holding each row's index (spliced from the first
 * changed row on). One column per
 * TableColumn, each with a factory that builds a label per cell and binds it to the cell's
 * text. The selection never picks a row by itself (autoselect off) and can be cleared;
 * notify::selected posts RowSelected with the row and its cells.
 */
class GTKTable extends TKTable implements GTKView
{
    use GTKPrimitive;

    protected \GtkColumnView $column_view;

    protected \GtkSingleSelection $selection;

    protected \GtkStringList $row_indexes;

    /**
     * The rows as the cells show them, by row index then column position.
     * @var list<list<string>>
     */
    protected array $cells = [];

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param list<TableColumn> $columns
     * @param list<array<string, scalar|null>> $rows
     * @throws WindowException When the name, columns or cells are not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $rows)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $rows);
        $this->row_indexes = \GtkStringList::new([]);
        $this->selection = \GtkSingleSelection::new($this->row_indexes);
        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        $this->column_view = \GtkColumnView::new($this->selection);
        foreach ($this->columns as $position => $column) {
            $this->column_view->appendColumn($this->buildColumn($column, $position));
        }
        $scrolled = \GtkScrolledWindow::new();
        $scrolled->setChild($this->column_view);
        $this->adoptNative($scrolled);
        $this->reload();

        $this->connect($this->selection, 'notify::selected', function (): void {
            if ($this->applying) {
                return;
            }
            $selected = $this->selection->getSelected();
            $this->nativeRowSelected($selected === GTK_INVALID_LIST_POSITION ? null : $selected);
            $row = $this->selected_row;
            $this->post(new RowSelected($this->window->name(), $this->path(), $this->uuid, $row, is_null($row) ? null : $this->rows[$row]));
        });
    }

    /**
     * The column view inside the scrolled window.
     * @return \GtkColumnView
     */
    public function columnView(): \GtkColumnView
    {
        return $this->column_view;
    }

    /**
     * The selection model the column view shows.
     * @return \GtkSingleSelection
     */
    public function selection(): \GtkSingleSelection
    {
        return $this->selection;
    }

    /**
     * Background and colour reach the column view and its row list, which the theme paints.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return ['> columnview', '> columnview > listview'];
    }

    /**
     * Rows unchanged from the start stay; everything from the first changed row on is replaced
     * in one splice (one items-changed), so an appended row costs one item and replaced rows
     * are rebound to their new cells.
     *
     * @param list<list<string>> $cells
     * @return void
     */
    protected function applyRows(array $cells): void
    {
        $kept = 0;
        while ($kept < count($cells) && $kept < count($this->cells) && $cells[$kept] === $this->cells[$kept]) {
            $kept++;
        }
        $removed = $this->row_indexes->getNItems() - $kept;
        $added = $kept < count($cells) ? array_map('strval', range($kept, count($cells) - 1)) : [];
        $this->cells = $cells;
        if ($removed === 0 && $added === []) {
            return;
        }
        $this->quietly(fn () => $this->row_indexes->splice($kept, $removed, $added));
    }

    protected function applySelectedRow(?int $row): void
    {
        $this->quietly(fn () => $this->selection->setSelected($row ?? GTK_INVALID_LIST_POSITION));
    }

    protected function buildColumn(TableColumn $column, int $position): \GtkColumnViewColumn
    {
        $factory = \GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', function (GObject $factory, \GtkListItem $item): void {
            $label = \GtkLabel::new(null);
            $label->setXalign(0.0);
            $item->setChild($label);
        });
        $this->connect($factory, 'bind', function (GObject $factory, \GtkListItem $item) use ($position): void {
            $index = $item->getItem();
            $label = $item->getChild();
            if ($index instanceof \GtkStringObject && $label instanceof \GtkLabel) {
                $label->setText($this->cells[(int) $index->getString()][$position] ?? '');
            }
        });

        $native = \GtkColumnViewColumn::new($column->label, $factory);
        $native->setExpand(true);
        $native->setResizable(true);

        return $native;
    }
}

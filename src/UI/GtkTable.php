<?php

namespace Jovian\Toolkits\GTK\UI;

use GObject;
use GtkLabel;
use GtkListItem;
use GtkColumnView;
use GtkStringList;
use GtkStringObject;
use WeakReference;
use GtkScrolledWindow;
use GtkSingleSelection;
use GtkColumnViewColumn;
use GtkSignalListItemFactory;
use Surface\Toolkits\UI\TKTable;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkColumnView in a scrolled window, over a list of row numbers. Rows unchanged from the
 * start stay; from the first that changed they are replaced in one splice, so an appended row
 * costs one row.
 */
class GtkTable extends TKTable
{
    use GtkNative;

    private ?GtkColumnView $view = null;

    private ?GtkSingleSelection $selection = null;

    private ?GtkStringList $numbers = null;

    /** @var list<list<string>> the cells as they are shown */
    private array $cells = [];

    protected function bootstrapNative(): void
    {
        $this->numbers = GtkStringList::new([]);
        $this->selection = GtkSingleSelection::new($this->numbers);
        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        $this->view = GtkColumnView::new($this->selection);

        $scrolled = GtkScrolledWindow::new();
        $scrolled->setChild($this->view);
        $this->adoptNative($scrolled);
        $this->declare('min-width', '160px');
        $this->declare('min-height', '80px');

        foreach ($this->columns() as $position => $column) {
            $this->view->appendColumn($this->buildColumn($column->label, $position));
        }
        $this->write(fn () => $this->applyRows($this->cellRows()));

        $self = WeakReference::create($this);
        $this->connect($this->selection, 'notify::selected', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
    }

    public function hasFocus(): bool
    {
        for ($at = $this->native?->getRoot()?->getFocus(); ! is_null($at); $at = $at->getParent()) {
            if ($at === $this->view) {
                return true;
            }
        }

        return false;
    }

    protected function nativeFocus(): bool
    {
        return $this->view->grabFocus();
    }

    protected function applyRows(array $cells): void
    {
        $kept = 0;
        while ($kept < count($cells) && $kept < count($this->cells) && $cells[$kept] === $this->cells[$kept]) {
            $kept++;
        }
        $removed = $this->numbers->getNItems() - $kept;
        $added = $kept < count($cells) ? array_map(strval(...), range($kept, count($cells) - 1)) : [];
        $this->cells = $cells;
        if ($removed !== 0 || $added !== []) {
            $this->numbers->splice($kept, $removed, $added);
        }
    }

    protected function applySelectedRow(?int $row): void
    {
        $this->selection->setSelected($row ?? GTK_INVALID_LIST_POSITION);
    }

    private function selectionChanged(): void
    {
        if ($this->isRemoved() || $this->isWriting()) {
            return;
        }
        $row = $this->selection->getSelected();
        $this->choseRow($row === GTK_INVALID_LIST_POSITION ? null : $row);
    }

    private function buildColumn(string $title, int $position): GtkColumnViewColumn
    {
        $self = WeakReference::create($this);
        $factory = GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', static function (GObject $factory, GtkListItem $item): void {
            $label = GtkLabel::new(null);
            $label->setXalign(0.0);
            $item->setChild($label);
        });
        $this->connect($factory, 'bind', static function (GObject $factory, GtkListItem $item) use ($self, $position): void {
            $number = $item->getItem();
            $label = $item->getChild();
            if ($number instanceof GtkStringObject && $label instanceof GtkLabel) {
                $label->setText($self->get()?->cells[(int) $number->getString()][$position] ?? '');
            }
        });

        $column = GtkColumnViewColumn::new($title, $factory);
        $column->setExpand(true);
        $column->setResizable(true);

        return $column;
    }
}

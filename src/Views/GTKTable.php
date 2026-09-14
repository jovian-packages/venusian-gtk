<?php

namespace Jovian\Venusian\GTK\Views;

use Jovian\Bindings\Gtk\Gtk\GtkColumnView;
use Jovian\Bindings\Gtk\Gtk\GtkColumnViewColumn;
use Jovian\Bindings\Gtk\Gtk\GtkFixed;
use Jovian\Bindings\Gtk\Gtk\GtkLabel as GtkLabelWidget;
use Jovian\Bindings\Gtk\Gtk\GtkListItem;
use Jovian\Bindings\Gtk\Gtk\GtkSignalListItemFactory;
use Jovian\Bindings\Gtk\Gtk\GtkSingleSelection;
use Jovian\Bindings\Gtk\Gtk\GtkStringList;
use Jovian\Bindings\Gtk\Gtk\GtkWidget;
use Jovian\Bindings\Gtk\Runtime\Bridge;
use Jovian\Bindings\Gtk\Runtime\Registry;
use Surface\Contracts\NativeWindows\Views\Color;
use Surface\NativeWindows\Views\Table;
use Surface\NativeWindows\Windowable;

/**
 * A Surface table over a GtkColumnView. One factory per header: setup
 * mints a GtkLabel child, bind looks up rows[getPosition()][col]. The
 * model is a GtkStringList of row placeholders; selection is a
 * GtkSingleSelection with autoselect off. notify::selected fires for
 * programmatic writes too, so applying keeps selectRow() silent.
 */
class GTKTable extends Table
{
    use TranslatesGtkFrames;

    protected bool $applying = false;

    /** @var list<GtkColumnViewColumn> */
    protected array $column_widgets = [];

    /** @var list<GtkSignalListItemFactory> */
    protected array $factories = [];

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    public function __construct(
        string $name,
        Windowable $window,
        array $columns,
        array $rows,
        public readonly GtkColumnView $native,
        public readonly GtkSingleSelection $selection,
        public readonly GtkStringList $strings,
        protected GtkFixed $content,
    ) {
        parent::__construct($name, $window, $columns, $rows);

        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        $this->installColumns($this->columns);

        Bridge::connect($this->selection->handle, 'notify::selected', function (mixed ...$args): void {
            if (! $this->applying) {
                $this->fireSelected($this->nativeRow());
            }
        });
    }

    protected function widget(): GtkWidget
    {
        return $this->native;
    }

    protected function fixed(): GtkFixed
    {
        return $this->content;
    }

    protected function applyColumns(array $columns): void
    {
        $this->applying = true;
        foreach ($this->column_widgets as $column) {
            $this->native->removeColumn($column);
        }
        $this->column_widgets = [];
        $this->factories = [];
        $this->installColumns($columns);
        $this->applying = false;
    }

    protected function applyRows(array $rows): void
    {
        $this->applying = true;
        $this->strings->splice(0, $this->strings->getNItems(), $this->placeholders($rows));
        $this->applying = false;
    }

    protected function applySelectedRow(int $selected): void
    {
        $this->applying = true;
        $this->selection->setSelected($selected < 0 ? 0xFFFFFFFF : $selected);
        $this->applying = false;
    }

    protected function applyEnabled(bool $enabled): void
    {
        $this->native->setSensitive($enabled);
    }

    protected function applyBackground(Color $color): void {}

    /**
     * @param list<string> $columns
     */
    protected function installColumns(array $columns): void
    {
        foreach (array_values($columns) as $index => $title) {
            $factory = GtkSignalListItemFactory::new();
            $col = $index;
            Bridge::connect($factory->handle, 'setup', function (int $emitter, int $listItem): void {
                $item = Registry::box($listItem);
                if ($item instanceof GtkListItem) {
                    $item->setChild(GtkLabelWidget::new(''));
                }
            });
            Bridge::connect($factory->handle, 'bind', function (int $emitter, int $listItem) use ($col): void {
                $item = Registry::box($listItem);
                if (! $item instanceof GtkListItem) {
                    return;
                }
                $child = $item->getChild();
                $position = $item->getPosition();
                $text = $this->rows[$position][$col] ?? '';
                if ($child instanceof GtkLabelWidget) {
                    $child->setText($text);
                }
            });
            $column = GtkColumnViewColumn::new($title, $factory->handle);
            $this->native->appendColumn($column);
            $this->factories[] = $factory;
            $this->column_widgets[] = $column;
        }
    }

    protected function nativeRow(): int
    {
        $selected = $this->selection->getSelected();
        $count = count($this->rows);
        if ($selected < 0 || $selected >= $count) {
            return -1;
        }

        return $selected;
    }

    /**
     * @param list<list<string>> $rows
     * @return list<string>
     */
    protected function placeholders(array $rows): array
    {
        $placeholders = [];
        foreach (array_keys($rows) as $index) {
            $placeholders[] = (string) $index;
        }

        return $placeholders;
    }
}

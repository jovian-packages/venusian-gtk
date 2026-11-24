<?php

namespace Jovian\Toolkits\GTK\UI;

use GObject;
use GtkLabel;
use GListModel;
use GdkDisplay;
use GtkListItem;
use GtkColumnView;
use GtkStringList;
use WeakReference;
use GtkCssProvider;
use GtkTreeListRow;
use GtkStringObject;
use GtkTreeExpander;
use GtkTreeListModel;
use GtkScrolledWindow;
use GtkSingleSelection;
use GtkColumnViewColumn;
use GtkSignalListItemFactory;
use Surface\Toolkits\UI\TKTree;
use Surface\Contracts\Toolkits\UI\TreeNode;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Surface\Contracts\Toolkits\UI\TableColumn;

/**
 * A GtkColumnView over a GtkTreeListModel of node ids, with one stylesheet for its colours.
 */
class GtkTree extends TKTree
{
    use GtkNative {
        releaseNative as private releaseWidget;
    }

    private ?GtkColumnView $column_view = null;

    private ?GtkSingleSelection $selection = null;

    private ?GtkTreeListModel $tree_model = null;

    private ?GtkStringList $roots = null;

    protected ?GtkCssProvider $color_provider = null;

    /** The stylesheet the colour provider holds, as loaded. */
    protected ?string $color_stylesheet = null;

    /**
     * @var array<string, string> CSS colour => the class that paints a label in it
     */
    protected array $color_classes = [];

    /**
     * The most colour classes any setNodes made: a recycled label may carry any of them.
     */
    protected int $color_class_count = 0;

    /**
     * @var array<int, array{GtkListItem, GtkTreeListRow, int}> per bound list item: the item, its row and the row's notify::expanded handler
     */
    protected array $row_handlers = [];

    protected function bootstrapNative(): void
    {
        $this->roots = GtkStringList::new([]);
        // The model holds the create callback until it is finalized: a weak reference keeps it from holding the tree.
        $self = WeakReference::create($this);
        $this->tree_model = GtkTreeListModel::new($this->roots, false, false, static fn (GObject $item): ?GListModel => $self->get()?->childModel($item));
        $this->selection = GtkSingleSelection::new($this->tree_model);
        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        $this->column_view = GtkColumnView::new($this->selection);
        foreach ($this->columns as $position => $column) {
            $this->column_view->appendColumn($this->buildColumn($column, $position));
        }
        if (count($this->columns) === 1 && $this->columns[0]->label === '') {
            // A column view's first child is its header.
            $this->column_view->getFirstChild()?->setVisible(false);
        }
        $scrolled = GtkScrolledWindow::new();
        $scrolled->setChild($this->column_view);
        $this->adoptNative($scrolled);
        $this->declare('min-width', '160px');
        $this->declare('min-height', '80px');

        $this->connect($this->selection, 'notify::selected', static function () use ($self): void {
            $self->get()?->selectionChanged();
        });
        $this->connect($this->column_view, 'activate', static function (GObject $view, int $position) use ($self): void {
            $self->get()?->activatedRow($position);
        });

        $this->write(fn () => $this->reload());
    }

    /**
     * The model's create callback: a node's children as a list of ids, null for a leaf.
     *
     * @param GObject $item
     * @return GListModel|null
     */
    public function childModel(GObject $item): ?GListModel
    {
        $children = $item instanceof GtkStringObject ? $this->childIds($item->getString()) : [];

        return $children === [] ? null : GtkStringList::new($children);
    }

    public function hasFocus(): bool
    {
        for ($at = $this->native?->getRoot()?->getFocus(); ! is_null($at); $at = $at->getParent()) {
            if ($at === $this->column_view) {
                return true;
            }
        }

        return false;
    }

    protected function nativeFocus(): bool
    {
        return $this->column_view->grabFocus();
    }

    protected function applyNodes(array $nodes): void
    {
        $this->restyleColors($nodes);
        $this->write(function (): void {
            $this->roots->splice(0, $this->roots->getNItems(), $this->childIds(null));
            $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
        });
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->write(fn () => $this->rowOf($id)?->setExpanded($expanded));
    }

    protected function applySelected(?string $id): void
    {
        $position = is_null($id) ? null : $this->rowOf($id)?->getPosition();
        $this->write(function () use ($position): void {
            $this->selection->setSelected($position ?? GTK_INVALID_LIST_POSITION);
            if (! is_null($position)) {
                $this->column_view->scrollTo($position, null, GTK_LIST_SCROLL_NONE, null);
            }
        });
    }

    private function selectedInNative(): ?string
    {
        $row = $this->selection->getSelectedItem();

        return $row instanceof GtkTreeListRow ? self::idOf($row) : null;
    }

    protected function releaseNative(): void
    {
        foreach ($this->row_handlers as [, $row, $handler]) {
            if (g_signal_handler_is_connected($row, $handler)) {
                g_signal_handler_disconnect($row, $handler);
            }
        }
        $this->row_handlers = [];
        $this->dropColors();
        $this->releaseWidget();
    }

    /**
     * The row of a node whose ancestors are all expanded: the root's row, then each child row down the path.
     *
     * @param string $id
     * @return GtkTreeListRow|null null while an ancestor is collapsed
     */
    protected function rowOf(string $id): ?GtkTreeListRow
    {
        $path = $this->pathOf($id);
        $row = $this->tree_model->getChildRow(array_shift($path));
        foreach ($path as $index) {
            $row = $row?->getChildRow($index);
        }

        return $row;
    }

    protected function buildColumn(TableColumn $column, int $position): GtkColumnViewColumn
    {
        $factory = GtkSignalListItemFactory::new();
        $this->connect($factory, 'setup', static function (GObject $factory, GtkListItem $item) use ($position): void {
            $label = GtkLabel::new(null);
            $label->setXalign(0.0);
            if ($position === 0) {
                $expander = GtkTreeExpander::new();
                $expander->setChild($label);
                $item->setChild($expander);

                return;
            }
            $item->setChild($label);
        });
        $self = WeakReference::create($this);
        $this->connect($factory, 'bind', static function (GObject $factory, GtkListItem $item) use ($self, $position): void {
            $self->get()?->bindCell($item, $position);
        });
        if ($position === 0) {
            $this->connect($factory, 'unbind', static function (GObject $factory, GtkListItem $item) use ($self): void {
                $self->get()?->unbindRow($item);
            });
        }

        $native = GtkColumnViewColumn::new($column->label, $factory);
        $native->setExpand(true);
        $native->setResizable(true);

        return $native;
    }

    /**
     * Show the row's node in the cell: its text and colour class, and in the first column the
     * expander's row with its notify::expanded connected.
     *
     * @param GtkListItem $item
     * @param int $position
     * @return void
     */
    protected function bindCell(GtkListItem $item, int $position): void
    {
        $row = $item->getItem();
        $child = $item->getChild();
        if (! $row instanceof GtkTreeListRow) {
            return;
        }

        $label = $child;
        if ($child instanceof GtkTreeExpander) {
            $child->setListRow($row);
            $this->row_handlers[spl_object_id($item)] = [$item, $row, g_signal_connect($row, 'notify::expanded', self::expansionListener(WeakReference::create($this)))];
            $label = $child->getChild();
        }
        $id = self::idOf($row);
        if (! $label instanceof GtkLabel || is_null($id)) {
            return;
        }

        $node = $this->node($id);
        $label->setText($this->cellTexts($node)[$position]);
        for ($n = 0; $n < $this->color_class_count; $n++) {
            $label->removeCssClass("tk-{$this->uuid()}-c{$n}");
        }
        $color = $this->cellColors($node)[$position];
        if (! is_null($color)) {
            $label->addCssClass($this->color_classes[$color->toCss()]);
        }
    }

    protected function unbindRow(GtkListItem $item): void
    {
        $key = spl_object_id($item);
        if (isset($this->row_handlers[$key])) {
            [, $row, $handler] = $this->row_handlers[$key];
            if (g_signal_handler_is_connected($row, $handler)) {
                g_signal_handler_disconnect($row, $handler);
            }
            unset($this->row_handlers[$key]);
        }
        $expander = $item->getChild();
        if ($expander instanceof GtkTreeExpander) {
            $expander->setListRow(null);
        }
    }

    /**
     * The user opened or closed a row: record it, post it, and on opening re-apply the record below it.
     *
     * @param GObject $row
     * @return void
     */
    protected function rowExpanded(GObject $row): void
    {
        if ($this->isWriting() || $this->isRemoved() || ! $row instanceof GtkTreeListRow) {
            return;
        }

        // Collapsing a row tears its descendants' rows down, and each notifies as it goes: a row the
        // model no longer shows for its node is GTK's teardown, not the user's.
        $id = self::idOf($row);
        if (is_null($id) || $this->rowOf($id) !== $row) {
            return;
        }

        $this->openedNode($id, $row->getExpanded());
    }

    protected function selectionChanged(): void
    {
        if (! $this->isRemoved() && ! $this->isWriting()) {
            $this->choseNode($this->selectedInNative());
        }
    }

    protected function activatedRow(int $position): void
    {
        $row = $this->tree_model->getRow($position);
        $id = is_null($row) ? null : self::idOf($row);
        if (! is_null($id)) {
            $this->activatedNode($id);
        }
    }

    /**
     * One class per distinct cell colour in the forest, in one provider scoped to this tree.
     *
     * @param list<TreeNode> $nodes
     * @return void
     */
    protected function restyleColors(array $nodes): void
    {
        $this->dropColors();
        $classes = [];
        $walk = function (array $level) use (&$walk, &$classes): void {
            foreach ($level as $node) {
                foreach ($node->colors as $color) {
                    $classes[$color->toCss()] ??= "tk-{$this->uuid()}-c".count($classes);
                }
                $walk($node->children);
            }
        };
        $walk($nodes);

        $this->color_classes = $classes;
        $this->color_class_count = max($this->color_class_count, count($classes));
        if ($classes === []) {
            return;
        }

        $scope = '.'.$this->styleClass();
        $rules = [];
        foreach ($classes as $css => $class) {
            $rules[] = "{$scope} .{$class} { color: {$css}; }";
        }
        $this->color_stylesheet = implode(' ', $rules);
        $this->color_provider = GtkCssProvider::new();
        $this->color_provider->loadFromString($this->color_stylesheet);
        gtk_style_context_add_provider_for_display(GdkDisplay::getDefault(), $this->color_provider, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
    }

    protected function dropColors(): void
    {
        if (! is_null($this->color_provider)) {
            gtk_style_context_remove_provider_for_display(GdkDisplay::getDefault(), $this->color_provider);
            $this->color_provider = null;
            $this->color_stylesheet = null;
        }
    }

    /**
     * @param GtkTreeListRow $row
     * @return string|null the id of the row's node
     */
    protected static function idOf(GtkTreeListRow $row): ?string
    {
        $item = $row->getItem();

        return $item instanceof GtkStringObject ? $item->getString() : null;
    }

    private static function expansionListener(WeakReference $self): \Closure
    {
        return static function (GObject $row) use ($self): void {
            $self->get()?->rowExpanded($row);
        };
    }
}

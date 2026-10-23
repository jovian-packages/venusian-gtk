<?php

namespace Jovian\Toolkits\GTK\Primitives;

use GdkDisplay;
use GObject;
use GListModel;
use GtkColumnView;
use GtkColumnViewColumn;
use GtkCssProvider;
use GtkLabel;
use GtkListItem;
use GtkScrolledWindow;
use GtkSignalListItemFactory;
use GtkSingleSelection;
use GtkStringList;
use GtkStringObject;
use GtkTreeExpander;
use GtkTreeListModel;
use GtkTreeListRow;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\Contracts\Windows\WindowException;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTree;
use WeakReference;

/**
 * A tree: a GtkColumnView inside a GtkScrolledWindow (the primitive's widget), over a
 * GtkSingleSelection of a GtkTreeListModel. The model's roots and each node's children are
 * GtkStringLists of node ids, a node's list made when its row first opens. One column per
 * TableColumn; the first wraps its label in a GtkTreeExpander. A cell's text colour is a class
 * from one CSS provider per tree, swapped on the recycled label as it is bound.
 *
 * GTK drops a collapsed node's rows, so Surface's expansion record is re-applied below a row
 * that opens. Each bound row's notify::expanded is connected in the first column's bind and
 * disconnected in its unbind. Every write the driver makes runs quietly, so only the user's
 * expand, collapse, selection and activation post.
 */
class GTKTree extends TKTree implements GTKView
{
    use GTKPrimitive;

    protected GtkColumnView $column_view;

    protected GtkSingleSelection $selection;

    protected GtkTreeListModel $tree_model;

    protected GtkStringList $roots;

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

    /** The node the native had selected after the last write or the last change the user made. */
    private ?string $known_selected = null;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param list<TableColumn> $columns
     * @param list<TreeNode> $nodes
     * @throws WindowException When the name, columns or nodes are not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, array $columns, array $nodes)
    {
        parent::__construct($name, $window, $parent, $placement, $columns, $nodes);
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
        $scrolled = GtkScrolledWindow::new();
        $scrolled->setChild($this->column_view);
        $this->adoptNative($scrolled);

        $this->connect($this->selection, 'notify::selected', fn () => $this->selectionChanged());
        $this->connect($this->column_view, 'activate', fn (GObject $view, int $position) => $this->activated($position));

        $this->reload();
    }

    /**
     * The column view inside the scrolled window.
     * @return GtkColumnView
     */
    public function columnView(): GtkColumnView
    {
        return $this->column_view;
    }

    public function focusWidget(): \GtkWidget
    {
        return $this->column_view;
    }

    /**
     * The selection model the column view shows.
     * @return GtkSingleSelection
     */
    public function selection(): GtkSingleSelection
    {
        return $this->selection;
    }

    /**
     * The tree list model under the selection: its items are the visible rows.
     * @return GtkTreeListModel
     */
    public function treeModel(): GtkTreeListModel
    {
        return $this->tree_model;
    }

    /**
     * The stylesheet of the cell colour provider, or null when no cell has a colour.
     * @return string|null
     */
    public function colorStylesheet(): ?string
    {
        return $this->color_stylesheet;
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

    /**
     * Background and colour reach the column view and its row list, which the theme paints.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return ['> columnview', '> columnview > listview'];
    }

    protected function applyNodes(array $nodes): void
    {
        $this->restyleColors($nodes);
        $this->quietly(function (): void {
            $this->roots->splice(0, $this->roots->getNItems(), $this->childIds(null));
            $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
        });
        $this->known_selected = null;
    }

    protected function applyExpanded(string $id, bool $expanded): void
    {
        $this->quietly(fn () => $this->rowOf($id)?->setExpanded($expanded));
        // A collapse drops a selected row below it, quietly: the user's next selection must not look like an echo.
        $this->known_selected = $this->nativeSelected();
    }

    protected function applySelected(?string $id): void
    {
        $position = is_null($id) ? null : $this->rowOf($id)?->getPosition();
        $this->quietly(function () use ($position): void {
            $this->selection->setSelected($position ?? GTK_INVALID_LIST_POSITION);
            if (! is_null($position)) {
                $this->column_view->scrollTo($position, null, GTK_LIST_SCROLL_NONE, null);
            }
        });
        $this->known_selected = $this->nativeSelected();
    }

    protected function nativeSelected(): ?string
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
        $this->connect($factory, 'setup', function (GObject $factory, GtkListItem $item) use ($position): void {
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
        $this->connect($factory, 'bind', fn (GObject $factory, GtkListItem $item) => $this->bindCell($item, $position));
        if ($position === 0) {
            $this->connect($factory, 'unbind', fn (GObject $factory, GtkListItem $item) => $this->unbindRow($item));
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
            $this->row_handlers[spl_object_id($item)] = [$item, $row, g_signal_connect($row, 'notify::expanded', fn (GObject $row) => $this->rowExpanded($row))];
            $label = $child->getChild();
        }
        $id = self::idOf($row);
        if (! $label instanceof GtkLabel || is_null($id)) {
            return;
        }

        $node = $this->node($id);
        $label->setText($this->cellTexts($node)[$position]);
        for ($n = 0; $n < $this->color_class_count; $n++) {
            $label->removeCssClass("tk-{$this->uuid}-c{$n}");
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
        if ($this->applying || ! $row instanceof GtkTreeListRow) {
            return;
        }

        // Collapsing a row tears its descendants' rows down, and each notifies as it goes: a row the
        // model no longer shows for its node is GTK's teardown, not the user's.
        $id = self::idOf($row);
        if (is_null($id) || $this->rowOf($id) !== $row) {
            return;
        }

        $expanded = $row->getExpanded();
        if (! $this->nativeNodeExpanded($id, $expanded)) {
            return;
        }

        $this->post(new NodeExpanded($this->window->name(), $this->path(), $this->uuid, $id, $expanded));
        if ($expanded) {
            $this->quietly(fn () => $this->reopen($id));
        }
    }

    protected function selectionChanged(): void
    {
        if ($this->applying) {
            return;
        }

        $selected = $this->nativeSelected();
        if ($selected === $this->known_selected) {
            return;
        }

        $this->known_selected = $selected;
        $this->post(new NodeSelected($this->window->name(), $this->path(), $this->uuid, $selected));
    }

    protected function activated(int $position): void
    {
        $row = $this->tree_model->getRow($position);
        $id = is_null($row) ? null : self::idOf($row);
        if (! is_null($id)) {
            $this->post(new NodeActivated($this->window->name(), $this->path(), $this->uuid, $id));
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
                    $classes[$color->toCss()] ??= "tk-{$this->uuid}-c".count($classes);
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
}

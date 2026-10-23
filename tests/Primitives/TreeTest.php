<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKTree;
use Surface\Contracts\Windows\Mail\View\NodeActivated;
use Surface\Contracts\Windows\Mail\View\NodeExpanded;
use Surface\Contracts\Windows\Mail\View\NodeSelected;
use Surface\Contracts\Windows\Primitives\TableColumn;
use Surface\Contracts\Windows\Primitives\TreeNode;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    takeMail(session());
});

/** @return list<TreeNode> a(a1(a1x), a2), b, with a2's name in red */
function treeNodes(): array
{
    return [
        new TreeNode('a', ['name' => 'a', 'size' => '3'], [
            new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])]),
            new TreeNode('a2', ['name' => 'a2', 'size' => '7'], colors: ['name' => Color::rgb(255, 0, 0)]),
        ]),
        new TreeNode('b', ['name' => 'b']),
    ];
}

/** A tree filling a presented window, the presenting mail taken. */
function presentedTree(array $nodes = []): GTKTree
{
    $window = driver()->open('main', 400, 300);
    $tree = $window->column('m')->tree('t', [new TableColumn('name', 'Name'), new TableColumn('size', 'Size')], $nodes === [] ? treeNodes() : $nodes);
    $tree->fill();
    $window->present();
    pumpFor(0.2);
    takeMail(session());

    return $tree;
}

/** The node id of the row at a flattened position. */
function rowId(GTKTree $tree, int $position): ?string
{
    return $tree->treeModel()->getRow($position)?->getItem()?->getString();
}

/** The first label under $widget showing $text, depth first. */
function labelShowing(GtkWidget $widget, string $text): ?GtkLabel
{
    if ($widget instanceof GtkLabel && $widget->getText() === $text) {
        return $widget;
    }
    for ($child = $widget->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
        $found = labelShowing($child, $text);
        if (! is_null($found)) {
            return $found;
        }
    }

    return null;
}

it('shows the roots over a tree list model, a column per column, and colours a cell through the tree\'s provider', function (): void {
    $tree = presentedTree();
    $rows = $tree->treeModel()->getNItems();
    $tree->expand('a');
    pumpFor(0.2);
    $a2 = labelShowing($tree->columnView(), 'a2');

    expect($tree)->toBeInstanceOf(GTKTree::class)
        ->and($tree->native())->toBeInstanceOf(GtkScrolledWindow::class)
        ->and($tree->columnView()->getModel())->toBe($tree->selection())
        ->and($tree->selection()->getModel())->toBe($tree->treeModel())
        ->and($rows)->toBe(2)
        ->and(array_map(fn (int $p): ?string => rowId($tree, $p), range(0, 3)))->toBe(['a', 'a1', 'a2', 'b'])
        ->and(labelShowing($tree->columnView(), 'Size'))->not->toBeNull()
        ->and(labelShowing($tree->columnView(), '7'))->not->toBeNull()
        ->and($a2)->not->toBeNull()
        ->and($a2->hasCssClass("tk-{$tree->uuid()}-c0"))->toBeTrue()
        ->and(labelShowing($tree->columnView(), 'a1')->hasCssClass("tk-{$tree->uuid()}-c0"))->toBeFalse()
        ->and($a2->getParent())->toBeInstanceOf(GtkTreeExpander::class)
        ->and(labelShowing($tree->columnView(), '7')->getParent())->not->toBeInstanceOf(GtkTreeExpander::class)
        ->and($tree->colorStylesheet())->toBe(".tk-{$tree->uuid()} .tk-{$tree->uuid()}-c0 { color: rgba(255, 0, 0, 1); }");
});

it('posts nothing for expand, selectNode and setNodes, and the native agrees', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    $tree->selectNode('a1x');
    $selected = $tree->selection()->getSelectedItem()->getItem()->getString();
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a1', ['name' => 'a1'], [new TreeNode('a1x', ['name' => 'a1x'])])])]);
    pumpFor(0.2);

    expect(viewMail(session()))->toBe([])
        ->and($selected)->toBe('a1x')
        ->and($tree->selectedNode())->toBe('a1x')
        ->and($tree->treeModel()->getRow(0)->getExpanded())->toBeTrue()
        ->and($tree->treeModel()->getRow(1)->getExpanded())->toBeTrue()
        ->and($tree->treeModel()->getNItems())->toBe(3);

    $tree->expand('a', false);
    $tree->selectNode(null);
    pumpFor(0.1);
    expect(viewMail(session()))->toBe([])
        ->and($tree->treeModel()->getRow(0)->getExpanded())->toBeFalse()
        ->and($tree->selection()->getSelected())->toBe(GTK_INVALID_LIST_POSITION);
});

it('posts NodeExpanded for the user\'s expand and collapse, and reopens what was expanded below', function (): void {
    $tree = presentedTree();
    $rows = $tree->treeModel();
    $rows->getRow(0)->setExpanded(true);
    pumpFor(0.1);
    $rows->getRow(1)->setExpanded(true);
    pumpFor(0.1);
    $rows->getRow(0)->setExpanded(false);
    pumpFor(0.1);
    $rows->getRow(0)->setExpanded(true);
    pumpFor(0.1);
    $rows->getRow(4)->setExpanded(true);
    pumpFor(0.1);

    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a1', true),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and(rowId($tree, 4))->toBe('b')
        ->and($rows->getRow(1)->getExpanded())->toBeTrue()
        ->and($tree->isExpanded('a1'))->toBeTrue()
        ->and($rows->getNItems())->toBe(5);

    // Code collapses a1 while a is closed: a1 stays collapsed when the user reopens a.
    $rows->getRow(0)->setExpanded(false);
    pumpFor(0.1);
    $tree->expand('a1', false);
    $rows->getRow(0)->setExpanded(true);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', false),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($rows->getRow(1)->getExpanded())->toBeFalse()
        ->and($tree->isExpanded('a1'))->toBeFalse()
        ->and($rows->getNItems())->toBe(4);
});

it('posts NodeSelected for the user\'s selection, and for a collapse only when the selected node changed', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    pumpFor(0.1);
    $tree->selection()->setSelected(2);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([new NodeSelected('main', 'm.t', $tree->uuid(), 'a2')]);

    $tree->treeModel()->getRow(0)->setExpanded(false);
    pumpFor(0.1);
    $after = $tree->selectedNode();
    $selections = array_values(array_filter(viewMail(session()), fn (object $mail): bool => $mail instanceof NodeSelected));
    expect($selections)->toEqual($after === 'a2' ? [] : [new NodeSelected('main', 'm.t', $tree->uuid(), $after)]);

    $tree->selection()->setSelected(1);
    pumpFor(0.1);
    $tree->treeModel()->getRow(0)->setExpanded(true);
    pumpFor(0.1);
    expect(viewMail(session()))->toEqual([
        new NodeSelected('main', 'm.t', $tree->uuid(), 'b'),
        new NodeExpanded('main', 'm.t', $tree->uuid(), 'a', true),
    ])
        ->and($tree->selectedNode())->toBe('b');
});

it('posts NodeActivated for the column view\'s activate on a row', function (): void {
    $tree = presentedTree();
    g_signal_emit_by_name($tree->columnView(), 'activate', 1);
    pumpFor(0.1);

    expect(viewMail(session()))->toEqual([new NodeActivated('main', 'm.t', $tree->uuid(), 'b')]);
});

it('keeps a selection setNodes moved under a collapsed node, opening its ancestors, posting nothing', function (): void {
    $tree = presentedTree();
    $tree->selectNode('a1');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'], [new TreeNode('a2', ['name' => 'a2'])]), new TreeNode('b', ['name' => 'b'], [new TreeNode('a1', ['name' => 'a1'])])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBe('a1')
        ->and($tree->isExpanded('b'))->toBeTrue()
        ->and(viewMail(session()))->toBe([]);
});

it('posts the user\'s selection of a node that code deselected by collapsing its parent', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    pumpFor(0.1);
    $tree->selection()->setSelected(2);
    $tree->expand('a', false);
    $tree->treeModel()->getRow(0)->setExpanded(true);
    pumpFor(0.1);
    $tree->selection()->setSelected(2);
    pumpFor(0.1);

    expect(array_values(array_filter(viewMail(session()), fn (object $mail): bool => $mail instanceof NodeSelected)))->toEqual([
        new NodeSelected('main', 'm.t', $tree->uuid(), 'a2'),
        new NodeSelected('main', 'm.t', $tree->uuid(), 'a2'),
    ]);
});

it('is freed once removed', function (): void {
    $tree = presentedTree();
    $tree->expand('a');
    pumpFor(0.1);
    $freed = WeakReference::create($tree);
    $tree->remove();
    unset($tree);
    pumpFor(0.1);
    gc_collect_cycles();

    expect($freed->get())->toBeNull();
});

it('drops the selection when setNodes removes the selected node, posting nothing', function (): void {
    $tree = presentedTree();
    $tree->selectNode('b');
    $tree->setNodes([new TreeNode('a', ['name' => 'a'])]);
    pumpFor(0.1);

    expect($tree->selectedNode())->toBeNull()
        ->and($tree->selection()->getSelected())->toBe(GTK_INVALID_LIST_POSITION)
        ->and($tree->treeModel()->getNItems())->toBe(1)
        ->and($tree->colorStylesheet())->toBeNull()
        ->and(viewMail(session()))->toBe([]);
});

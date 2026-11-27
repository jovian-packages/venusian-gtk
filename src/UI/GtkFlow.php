<?php

namespace Jovian\Toolkits\GTK\UI;

use GdkDisplay;
use GtkAlign;
use GtkBox;
use GtkCssProvider;
use GtkFlowBox as NativeFlow;
use GtkFlowBoxChild;
use GtkOrientation;
use GtkSelectionMode;
use GtkWidget;
use WeakReference;
use Surface\Toolkits\UI\TKFlow;
use Surface\Toolkits\UI\TKPrimitive;
use Surface\Contracts\Toolkits\UI\Insets;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkFlowBox in a vertical GtkBox: selection none, not homogeneous, and no limit on the children a line holds
 * (GTK stops a line at 7 unless told otherwise), so it wraps only where the width runs out.
 * Each child sits in a GtkFlowBoxChild of its own, start-aligned so the child keeps its natural
 * size; that cell is hidden with its child, since a shown cell with a hidden child would still
 * count as a place on the line. Reordering takes the cell out and puts it back at its index.
 * Spacing is GTK's column and row spacing. The flow box is start-aligned in the box: a flow box
 * that fills its width shares the line's width out evenly among its columns (and lines up the
 * columns of every line), while a start-aligned one takes only the width its cells need, and
 * wraps at the width it is given. The box carries what the primitive is (fill, align, background,
 * border) and padding as CSS padding, each side its own, inside the background: GtkFlowBox
 * does not lay its children out inside its own CSS padding.
 */
class GtkFlow extends TKFlow
{
    use GtkNative {
        releaseNative as private releaseWidget;
    }

    /** The most children GTK's flow box accepts on a line (the property's maximum, a gint). */
    private const int UNLIMITED = 2147483647;

    /** One rule for every cell: the theme pads a cell, and the child's margin is the space round it. */
    private static ?GtkCssProvider $cells = null;

    private ?NativeFlow $flow = null;

    /** @var array<string, GtkFlowBoxChild> the cell of each child, by its uuid */
    private array $cell_of = [];

    /** @var array<string, int> each child's notify::visible handler, by its uuid */
    private array $visibility = [];

    protected function bootstrapNative(): void
    {
        $flow = NativeFlow::new();
        $flow->setSelectionMode(GtkSelectionMode::NONE);
        $flow->setHomogeneous(false);
        $flow->setMaxChildrenPerLine(self::UNLIMITED);
        $flow->setHalign(GtkAlign::START);
        $flow->setValign(GtkAlign::START);
        $this->flow = $flow;

        $box = GtkBox::new(GtkOrientation::VERTICAL, 0);
        $box->append($flow);
        $this->adoptNative($box);

        if (is_null(self::$cells)) {
            self::$cells = GtkCssProvider::new();
            self::$cells->loadFromString('flowbox > flowboxchild.tk-flow-cell { padding: 0; }');
            gtk_style_context_add_provider_for_display(GdkDisplay::getDefault(), self::$cells, GTK_STYLE_PROVIDER_PRIORITY_APPLICATION);
        }

        $this->applySpacing($this->spacing());
        $this->applyPadding($this->padding());
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->declare('padding', $padding->isZero() ? null : self::cssSides($padding));
    }

    protected function applySpacing(int $spacing): void
    {
        $this->flowNative()->setColumnSpacing($spacing);
        $this->flowNative()->setRowSpacing($spacing);
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $native = $child->native();

        $cell = GtkFlowBoxChild::new();
        $cell->addCssClass('tk-flow-cell');
        $cell->setHalign(GtkAlign::START);
        $cell->setValign(GtkAlign::START);
        $cell->setChild($native);
        $cell->setVisible($native->getVisible());
        $this->flowNative()->append($cell);
        $this->cell_of[$child->uuid()] = $cell;

        $self = WeakReference::create($cell);
        $this->visibility[$child->uuid()] = g_signal_connect($native, 'notify::visible', static function (GtkWidget $widget) use ($self): void {
            $self->get()?->setVisible($widget->getVisible());
        });
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $native = $child->native();
        $cell = $this->cell_of[$child->uuid()];

        if (g_signal_handler_is_connected($native, $this->visibility[$child->uuid()])) {
            g_signal_handler_disconnect($native, $this->visibility[$child->uuid()]);
        }
        unset($this->visibility[$child->uuid()], $this->cell_of[$child->uuid()]);

        $this->flowNative()->remove($cell);
        $cell->setChild(null);
    }

    /**
     * The children are already in their new order: the cell goes back at the child's index.
     */
    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $cell = $this->cell_of[$child->uuid()];
        $this->flowNative()->remove($cell);
        $this->flowNative()->insert($cell, $index);
    }

    protected function releaseNative(): void
    {
        $this->cell_of = [];
        $this->visibility = [];
        $this->flow = null;
        $this->releaseWidget();
    }

    private function flowNative(): NativeFlow
    {
        return $this->flow;
    }
}

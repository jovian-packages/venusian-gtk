<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkLabel;
use GtkWidget;
use GtkNotebook;
use WeakReference;
use Surface\Toolkits\UI\TKTabView;
use Surface\Toolkits\UI\TKPrimitive;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkNotebook. switch-page comes for what code does too; that is inside write().
 */
class GtkTabView extends TKTabView
{
    use GtkNative;

    protected function bootstrapNative(): void
    {
        $book = GtkNotebook::new();
        $book->setScrollable(true);
        $this->adoptNative($book);

        $self = WeakReference::create($this);
        $this->connect($book, 'switch-page', static function (GtkNotebook $book, GtkWidget $page) use ($self): void {
            $self->get()?->chosen($book->pageNum($page));
        });
    }

    protected function attachNative(TKPrimitive $child): void
    {
        $this->bookNative()->appendPage($child->native(), GtkLabel::new($this->title($child->name())));
    }

    protected function detachNative(TKPrimitive $child): void
    {
        $number = $this->bookNative()->pageNum($child->native());
        if ($number >= 0) {
            $this->bookNative()->removePage($number);
        }
    }

    protected function reorderNative(TKPrimitive $child, int $index): void
    {
        $book = $this->bookNative();
        $page = $child->native();
        $book->removePage($book->pageNum($page));
        $book->insertPage($page, GtkLabel::new($this->title($child->name())), $index);
    }

    protected function applyTitle(TKPrimitive $page, string $title): void
    {
        $this->bookNative()->setTabLabelText($page->native(), $title);
    }

    protected function applySelected(TKPrimitive $page): void
    {
        $this->bookNative()->setCurrentPage($this->bookNative()->pageNum($page->native()));
    }

    private function bookNative(): GtkNotebook
    {
        /** @var GtkNotebook */
        return $this->native();
    }
}

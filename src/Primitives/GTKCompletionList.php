<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Closure;
use GdkRectangle;
use GObject;
use GtkGestureClick;
use GtkListItem;
use GtkListView;
use GtkOrientation;
use GtkPolicyType;
use GtkPopover;
use GtkPositionType;
use GtkScrolledWindow;
use GtkSignalListItemFactory;
use GtkSingleSelection;
use GtkStringList;
use GtkStringObject;
use GtkWidget;

/**
 * Rows of strings in a list view: a combo's completions in a popover under its entry, and its
 * options in the menu button's popover. The completions' popover has no autohide (an autohiding
 * popover grabs the keyboard, and typing goes on in the entry), no arrow, and points at the whole
 * entry from below, as wide as it. The combo's key controller moves the highlight and chooses. A
 * click on a row is a click gesture on the view, not its single-click activate, which also
 * highlights the row under a resting pointer as the rows scroll under it. A row activated from the
 * keyboard (the options list) chooses too. Its handlers hold nothing of the combo: $chosen is static.
 */
final class GTKCompletionList
{
    private const int MAX_ROWS = 8;

    private readonly GtkStringList $strings;

    private readonly GtkGestureClick $clicks;

    private readonly GtkSingleSelection $selection;

    private readonly GtkListView $view;

    private readonly GtkScrolledWindow $scroll;

    private ?GtkPopover $popover = null;

    /** @var list<string> */
    private array $rows = [];

    /** The width the rows were last set for; -1 for the list's own. */
    private int $width = -1;

    /**
     * @param Closure(int): void $chosen called with the row a click chose
     */
    public function __construct(Closure $chosen)
    {
        $this->strings = GtkStringList::new([]);
        $this->selection = GtkSingleSelection::new($this->strings);
        $this->selection->setAutoselect(false);
        $this->selection->setCanUnselect(true);
        $factory = GtkSignalListItemFactory::new();
        g_signal_connect($factory, 'setup', static function (GObject $factory, GtkListItem $item): void {
            $label = \GtkLabel::new(null);
            $label->setXalign(0.0);
            $item->setChild($label);
        });
        g_signal_connect($factory, 'bind', static function (GObject $factory, GtkListItem $item): void {
            $string = $item->getItem();
            $label = $item->getChild();
            if ($string instanceof GtkStringObject && $label instanceof \GtkLabel) {
                $label->setText($string->getString());
            }
        });
        $this->view = GtkListView::new($this->selection, $factory);
        g_signal_connect($this->view, 'activate', static fn (GtkListView $view, int $position) => $chosen($position));
        // The rows share the list's height evenly: the row is where the click lands in it, scrolled.
        $strings = $this->strings;
        $this->clicks = GtkGestureClick::new();
        g_signal_connect($this->clicks, 'released', static function (GtkGestureClick $clicks, int $presses, float $x, float $y) use ($strings, $chosen): void {
            $scroll = $clicks->getWidget()?->getParent();
            $count = $strings->getNItems();
            if ($presses !== 1 || ! $scroll instanceof GtkScrolledWindow || $count === 0) {
                return;
            }
            $adjustment = $scroll->getVadjustment();
            $row = (int) floor(($adjustment->getValue() - $adjustment->getLower() + $y) / (($adjustment->getUpper() - $adjustment->getLower()) / $count));
            if ($row >= 0 && $row < $count) {
                $chosen($row);
            }
        });
        $this->view->addController($this->clicks);
        $this->scroll = GtkScrolledWindow::new();
        $this->scroll->setChild($this->view);
    }

    /** The list's view, inside its scrolled window: a popover's child. */
    public function content(): GtkWidget
    {
        return $this->scroll;
    }

    public function view(): GtkListView
    {
        return $this->view;
    }

    /** The click gesture on the view that chooses a row. */
    public function clicks(): GtkGestureClick
    {
        return $this->clicks;
    }

    /**
     * Write the rows with nothing highlighted, and size the list to them, at most eight rows tall.
     *
     * @param list<string> $rows
     */
    public function setRows(array $rows, int $width = -1): void
    {
        $this->strings->splice(0, $this->strings->getNItems(), $rows);
        $this->rows = $rows;
        $this->selection->setSelected(GTK_INVALID_LIST_POSITION);
        // A field not laid out yet has no width: the list takes its own.
        $this->width = $width > 0 ? $width : -1;
        $this->fit();
    }

    /**
     * Size the list to its rows, at most eight rows tall. A list not in a window yet measures its
     * rows short: a popover that shows it calls this again.
     */
    public function fit(): void
    {
        $natural = $this->view->measure(GtkOrientation::VERTICAL, $this->width)['natural'];
        $count = max(1, $this->strings->getNItems());
        // A vertical scroll bar holds the list at least its own minimum tall: rows that fit get none.
        $this->scroll->setPolicy(GtkPolicyType::NEVER, $count > self::MAX_ROWS ? GtkPolicyType::AUTOMATIC : GtkPolicyType::NEVER);
        $this->scroll->setSizeRequest($this->width, (int) ceil($count > self::MAX_ROWS ? $natural / $count * self::MAX_ROWS : $natural));
    }

    /**
     * Show $rows in a popover under $field, as wide as it.
     *
     * @param list<string> $rows
     */
    public function show(GtkWidget $field, array $rows): void
    {
        $popover = $this->popover ??= $this->makePopover();
        if ($popover->getParent() !== $field) {
            $this->detach();
            $popover->setParent($field);
        }
        $this->setRows($rows, $field->getWidth());
        $popover->setPointingTo(new GdkRectangle(0, 0, $field->getWidth(), $field->getHeight()));
        $popover->popup();
    }

    public function hide(): void
    {
        $this->popover?->popdown();
        $this->rows = [];
    }

    public function isShown(): bool
    {
        return $this->popover?->getVisible() === true;
    }

    /** The completions' popover, made on first show. */
    public function popover(): ?GtkPopover
    {
        return $this->popover;
    }

    /** @return list<string> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** The highlighted row; -1 for none. */
    public function highlighted(): int
    {
        $row = $this->selection->getSelected();

        return $row === GTK_INVALID_LIST_POSITION ? -1 : $row;
    }

    /** Move the highlight by $delta rows, clamped to the rows. */
    public function move(int $delta): void
    {
        if ($this->rows === []) {
            return;
        }
        $row = max(0, min(count($this->rows) - 1, $this->highlighted() + $delta));
        $this->selection->setSelected($row);
        // The rows share the list's height evenly: scroll just far enough to show this one.
        $adjustment = $this->scroll->getVadjustment();
        $height = ($adjustment->getUpper() - $adjustment->getLower()) / count($this->rows);
        $top = $adjustment->getLower() + $row * $height;
        if ($top < $adjustment->getValue()) {
            $adjustment->setValue($top);
        } elseif ($top + $height > $adjustment->getValue() + $adjustment->getPageSize()) {
            $adjustment->setValue($top + $height - $adjustment->getPageSize());
        }
    }

    /** The field is gone: the popover comes off it. */
    public function close(): void
    {
        $this->hide();
        $this->detach();
    }

    private function detach(): void
    {
        if (! is_null($this->popover?->getParent())) {
            $this->popover->unparent();
        }
    }

    private function makePopover(): GtkPopover
    {
        $popover = GtkPopover::new();
        $popover->setAutohide(false);
        $popover->setHasArrow(false);
        $popover->setPosition(GtkPositionType::BOTTOM);
        $popover->setChild($this->scroll);
        // A click on a row would take focus from the entry: the rows cannot hold it.
        g_object_set_property($this->view, 'can-focus', false);

        return $popover;
    }
}

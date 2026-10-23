<?php

namespace Jovian\Toolkits\GTK\Primitives;

use Closure;
use Jovian\Toolkits\GTK\Contracts\Primitives\GTKView;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKPrimitive;
use Jovian\Toolkits\GTK\Primitives\Concerns\GTKTextStyle;
use Jovian\Toolkits\GTK\Windows\GTKMacTextKeys;
use Jovian\Toolkits\GTK\Windows\GTKWindow;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\ViewScrolled;
use Surface\Contracts\Windows\Primitives\Placement;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\ScrollPosition;
use Surface\Contracts\Windows\Styling\TextRangeStyle;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;
use Surface\Windows\Primitives\TKPrimitiveGroup;
use Surface\Windows\Primitives\TKTextArea;

/**
 * A multi-line editor: a GtkTextView inside a GtkScrolledWindow, which is the primitive's
 * widget (and carries its style class). GTK wraps each user edit in a user action, inside
 * which the buffer may change more than once (a paste over a selection deletes, then
 * inserts): one TextChanged goes out when the outermost action ends, with the final text.
 * A change outside any action posts on its own.
 *
 * Line backgrounds are paragraph-background tags, range colours foreground/background tags:
 * one tag per role and colour, kept in the buffer's table. GTK counts code points, as the
 * contract does. Scrolls are the scrolled window's adjustments' value-changed.
 *
 * GTK lays the text out on later frames, not inside the call that changed it, and moves the
 * adjustments as it does (it keeps the first visible line in place while line heights change,
 * and clamps to the new content). So a hook that changes something native (text, wrapping,
 * style, tags; the same input again changes nothing and is skipped) leaves the area settling.
 * The settle ends once the view is mapped and sized and its adjustments have held still over
 * SETTLE_CHECKS checks a frame apart, or SETTLE_CAP_MS after it began, whichever is first.
 * A scroll asked for while settling is held and made when the settle ends, latest wins.
 * Value changes GTK makes during a settle post nothing. A user scroll inside one does: a check
 * that finds an adjustment's value moved while its upper and page size held (a wheel, a
 * scroll bar, a key), or a page size changed (a resize), marks it, and the settle's end posts
 * one ViewScrolled with the position then.
 */
class GTKTextArea extends TKTextArea implements GTKView
{
    use GTKPrimitive;
    use GTKTextStyle;

    /** How often a settling area reads its adjustments: about a frame. */
    public const int SETTLE_MS = 20;

    /** Checks in a row that must find the adjustments unchanged before the layout counts as settled. */
    public const int SETTLE_CHECKS = 2;

    /** The longest a settle lasts, from its start (or from the view mapping, if it started unmapped). */
    public const int SETTLE_CAP_MS = 500;

    protected \GtkTextView $text_view;

    protected \GtkScrolledWindow $scrolled;

    private ?\GtkEventControllerKey $mac_keys = null;

    /**
     * Nesting depth of the buffer's user actions, and whether the text changed inside them.
     */
    protected int $user_action_depth = 0;

    protected bool $changed_in_action = false;

    /** @var array<string, \GtkTextTag> by role and colour */
    protected array $tags = [];

    /** @var array<int, \GtkTextTag> the tags the current line backgrounds applied */
    protected array $line_tags = [];

    /** @var array<int, \GtkTextTag> the tags the current range styles applied */
    protected array $range_tags = [];

    /** The wrapping the view has. */
    private bool $applied_wrap = true;

    /**
     * @var array<int, string>|null the line backgrounds the buffer holds, as CSS colours by line;
     *     null once a user edit has moved or dropped the tags, so the next set re-tags
     */
    private ?array $applied_lines = [];

    /**
     * @var list<array{int, int, int, string|null, string|null}>|null the range styles the buffer
     *     holds; null once a user edit has moved or dropped the tags, so the next set re-tags
     */
    private ?array $applied_ranges = [];

    /**
     * True from a hook that makes GTK lay the text out again until that layout has settled.
     */
    private bool $settling = false;

    private ?int $settle_source = null;

    /** hrtime() when the settle began, or when its view mapped. */
    private int $settle_started = 0;

    /** @var list<float>|null the adjustments at the last check */
    private ?array $settle_seen = null;

    /** Checks in a row that found $settle_seen unchanged. */
    private int $settle_still = 0;

    /** Whether a check in this settle saw the user scroll. */
    private bool $user_scrolled = false;

    /** Whether this settle runs from the view mapping: a first show sizes the view over several frames. */
    private bool $settle_from_map = false;

    /** The last scroll asked for while settling, made once the layout settles. */
    private ?Closure $pending_scroll = null;

    /**
     * @param string $name
     * @param GTKWindow $window
     * @param TKPrimitiveGroup|null $parent
     * @param Placement $placement
     * @param string $value
     * @throws WindowException When the name is not valid.
     */
    public function __construct(string $name, GTKWindow $window, ?TKPrimitiveGroup $parent, Placement $placement, string $value)
    {
        parent::__construct($name, $window, $parent, $placement, $value);
        $this->text_view = \GtkTextView::new();
        $this->text_view->setWrapMode(\GtkWrapMode::WORD_CHAR);
        if (PHP_OS_FAMILY === 'Darwin') {
            // The controller's handler is held by the view, which this primitive holds: a weak reference breaks the cycle.
            $self = \WeakReference::create($this);
            $this->mac_keys = GTKMacTextKeys::attach($this->text_view, static fn (): ?\GtkTextView => $self->get()?->text_view);
        }
        $this->scrolled = \GtkScrolledWindow::new();
        $this->scrolled->setPolicy(\GtkPolicyType::NEVER, \GtkPolicyType::AUTOMATIC);
        $this->scrolled->setChild($this->text_view);
        $this->adoptNative($this->scrolled);
        $this->applyValue($this->value);
        // A new view has no layout yet: its first scroll waits for one.
        $this->settle();

        $buffer = $this->text_view->getBuffer();
        $this->connect($buffer, 'begin-user-action', function (): void {
            $this->user_action_depth++;
        });
        $this->connect($buffer, 'end-user-action', function (): void {
            $this->user_action_depth = max(0, $this->user_action_depth - 1);
            if ($this->user_action_depth === 0 && $this->changed_in_action) {
                $this->changed_in_action = false;
                $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
            }
        });
        $this->connect($buffer, 'changed', function (): void {
            if ($this->applying) {
                return;
            }
            $this->nativeValueChanged($this->bufferText());
            // GTK moved the tags with the edit, or dropped them with the text they covered.
            $this->applied_lines = null;
            $this->applied_ranges = null;
            if ($this->user_action_depth > 0) {
                $this->changed_in_action = true;
                return;
            }
            $this->post(new TextChanged($this->window->name(), $this->path(), $this->uuid, $this->value));
        });
        $this->connect($this->scrolled->getHadjustment(), 'value-changed', fn () => $this->postScrolled());
        $this->connect($this->scrolled->getVadjustment(), 'value-changed', fn () => $this->postScrolled());
        // A settling area that is not on screen stops checking. Mapping it starts the checks
        // and the cap over (GTK lays out only from there); GTK sizing it again (a page size or
        // content height) starts the checks over.
        $this->connect($this->text_view, 'map', function (): void {
            if ($this->settling) {
                $this->settle_started = hrtime(true);
                $this->settle_seen = null;
                $this->settle_from_map = true;
                $this->settle();
            }
        });
        $resized = function (): void {
            if ($this->settling) {
                $this->settle();
            }
        };
        $this->connect($this->scrolled->getHadjustment(), 'changed', $resized);
        $this->connect($this->scrolled->getVadjustment(), 'changed', $resized);
    }

    public function native(): \GtkScrolledWindow
    {
        return $this->scrolled;
    }

    /**
     * The text view inside the scrolled window.
     * @return \GtkTextView
     */
    public function textView(): \GtkTextView
    {
        return $this->text_view;
    }

    public function focusWidget(): \GtkWidget
    {
        return $this->text_view;
    }

    /**
     * The Mac text keys' controller on the text view; null off macOS.
     * @return \GtkEventControllerKey|null
     */
    public function macKeys(): ?\GtkEventControllerKey
    {
        return $this->mac_keys;
    }

    /**
     * Whether GTK is still laying out after one of this area's hooks: until it settles, the
     * area holds ViewScrolled and the last scroll asked for.
     * @return bool
     */
    public function isSettling(): bool
    {
        return $this->settling;
    }

    /**
     * Background and colour reach the text view and its text node, which the theme colours.
     * @return list<string>
     */
    protected function styleTargets(): array
    {
        return ['> textview', '> textview > text'];
    }

    /**
     * setText drops every tag with the old text, so the tag bookkeeping empties with it.
     */
    protected function applyValue(string $value): void
    {
        $buffer = $this->text_view->getBuffer();
        if ($this->bufferText() === $value) {
            return;
        }
        $this->relayout(fn () => $buffer->setText($value, -1));
        $this->line_tags = [];
        $this->range_tags = [];
        $this->applied_lines = [];
        $this->applied_ranges = [];
    }

    protected function applyFont(FontSpec $font): void
    {
        $this->restyleText();
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->restyleText();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->restyleText();
    }

    protected function applyReadOnly(bool $read_only): void
    {
        $this->text_view->setEditable(! $read_only);
        $this->text_view->setCursorVisible(true);
    }

    protected function applyWrap(bool $wrap): void
    {
        if ($wrap === $this->applied_wrap) {
            return;
        }
        $this->applied_wrap = $wrap;
        $this->relayout(function () use ($wrap): void {
            $this->text_view->setWrapMode($wrap ? \GtkWrapMode::WORD_CHAR : \GtkWrapMode::NONE);
            $this->scrolled->setPolicy($wrap ? \GtkPolicyType::NEVER : \GtkPolicyType::AUTOMATIC, \GtkPolicyType::AUTOMATIC);
        });
    }

    protected function applyLineBackgrounds(array $backgrounds): void
    {
        $applied = array_map(fn (Color $color): string => $color->toCss(), $backgrounds);
        if ($applied === $this->applied_lines) {
            return;
        }
        $this->applied_lines = $applied;
        $this->relayout(function () use ($backgrounds): void {
            $buffer = $this->text_view->getBuffer();
            $this->untag($this->line_tags);
            $this->line_tags = [];
            foreach ($backgrounds as $line => $color) {
                $tag = $this->tag('paragraph-background', $color);
                // Past the last line GTK's iter is the buffer's end: the last line's tag runs to it.
                $buffer->applyTag($tag, $this->lineIter($line), $this->lineIter($line + 1));
                $this->line_tags[spl_object_id($tag)] = $tag;
            }
        });
    }

    protected function applyRangeStyles(array $styles): void
    {
        $applied = array_map(fn (TextRangeStyle $style): array => [
            $style->line, $style->start, $style->length, $style->foreground?->toCss(), $style->background?->toCss(),
        ], $styles);
        if ($applied === $this->applied_ranges) {
            return;
        }
        $this->applied_ranges = $applied;
        $this->relayout(function () use ($styles): void {
            $buffer = $this->text_view->getBuffer();
            $this->untag($this->range_tags);
            $this->range_tags = [];
            foreach ($styles as $style) {
                foreach (['foreground' => $style->foreground, 'background' => $style->background] as $property => $color) {
                    if (is_null($color)) {
                        continue;
                    }
                    $tag = $this->tag($property, $color);
                    $buffer->applyTag(
                        $tag,
                        $this->lineOffsetIter($style->line, $style->start),
                        $this->lineOffsetIter($style->line, $style->start + $style->length),
                    );
                    $this->range_tags[spl_object_id($tag)] = $tag;
                }
            }
        });
    }

    protected function applySelectLines(int $first, int $last): void
    {
        $buffer = $this->text_view->getBuffer();
        $this->quietly(fn () => $buffer->selectRange(
            $this->lineIter($first),
            $this->lineOffsetIter($last, $this->lines()->length($last)),
        ));
    }

    /** The buffer's whole text, hidden characters left out. */
    protected function bufferText(): string
    {
        $buffer = $this->text_view->getBuffer();

        return $buffer->getText($buffer->getStartIter(), $buffer->getEndIter(), false) ?? '';
    }

    /** The iter at $line's start; past the last line, GTK's buffer end. */
    protected function lineIter(int $line): \GtkTextIter
    {
        $this->text_view->getBuffer()->getIterAtLine($iter, $line);

        return $iter;
    }

    /** The iter at $offset in $line; past the line's end, GTK's line end. */
    protected function lineOffsetIter(int $line, int $offset): \GtkTextIter
    {
        $this->text_view->getBuffer()->getIterAtLineOffset($iter, $line, $offset);

        return $iter;
    }

    protected function nativeSelectedLines(): array
    {
        $buffer = $this->text_view->getBuffer();
        if (! $buffer->getSelectionBounds($start, $end)) {
            $line = $buffer->getIterAtMark($buffer->getInsert())->getLine();

            return [$line, $line];
        }
        $last = $end->getLine();
        if ($end->getLineOffset() === 0 && $last > $start->getLine()) {
            $last--;
        }

        return [$start->getLine(), $last];
    }

    protected function nativeScrollPosition(): ScrollPosition
    {
        $horizontal = $this->scrolled->getHadjustment();
        $vertical = $this->scrolled->getVadjustment();

        return ScrollPosition::of(
            $horizontal->getValue(), $horizontal->getPageSize(), $horizontal->getUpper(),
            $vertical->getValue(), $vertical->getPageSize(), $vertical->getUpper(),
        );
    }

    protected function applyScrollTo(float $x, float $y): void
    {
        $this->scrollWhenSettled(function () use ($x, $y): void {
            $horizontal = $this->scrolled->getHadjustment();
            $vertical = $this->scrolled->getVadjustment();
            $this->scrollAdjustmentsTo(
                ScrollPosition::offset($x, $horizontal->getPageSize(), $horizontal->getUpper()),
                ScrollPosition::offset($y, $vertical->getPageSize(), $vertical->getUpper()),
            );
        });
    }

    protected function applyScrollToLine(int $line): void
    {
        $this->scrollWhenSettled(function () use ($line): void {
            $buffer = $this->text_view->getBuffer();
            $this->text_view->getLineYrange($this->lineIter($line), $top, $height);
            $vertical = $this->scrolled->getVadjustment();
            $upper = $vertical->getUpper();
            $this->scrollAdjustmentsTo(
                $this->scrolled->getHadjustment()->getValue(),
                ScrollPosition::offset($upper > 0.0 ? $top / $upper : 0.0, $vertical->getPageSize(), $upper),
            );
        });
    }

    protected function postScrolled(): void
    {
        if ($this->applying || $this->settling || $this->removed || ! $this->watching_scroll) {
            return;
        }
        $this->session()->postLatest(
            "view.scrolled.{$this->window->name()}.{$this->path()}",
            new ViewScrolled($this->window->name(), $this->path(), $this->uuid, $this->nativeScrollPosition()),
        );
    }

    protected function releaseNative(): void
    {
        if (! is_null($this->settle_source)) {
            g_source_remove($this->settle_source);
            $this->settle_source = null;
        }
        $this->settling = false;
        $this->settle_from_map = false;
        $this->user_scrolled = false;
        $this->pending_scroll = null;
    }

    /**
     * Rebuild the stylesheet; settle only when it came out different, since an unchanged one
     * leaves GTK nothing to lay out.
     * @return void
     */
    private function restyleText(): void
    {
        $before = $this->stylesheet;
        $this->quietly(fn () => $this->restyle());
        if ($this->stylesheet !== $before) {
            $this->settle();
        }
    }

    /**
     * Write something GTK lays out again (text, wrapping, tags) without posting, and settle.
     *
     * @param Closure(): void $write
     * @return void
     */
    private function relayout(Closure $write): void
    {
        $this->quietly($write);
        $this->settle();
    }

    /**
     * Begin a settle, or restart the checks of the running one; its start, and so its cap, stay.
     * A settle begins from the adjustments as the hook left them, so a user scroll before the
     * first check is seen too.
     * @return void
     */
    private function settle(): void
    {
        if (! $this->settling) {
            $this->settling = true;
            $this->settle_started = hrtime(true);
            $this->settle_seen = $this->adjustments();
            $this->user_scrolled = false;
        }
        $this->settle_still = 0;
        $this->settle_source ??= g_timeout_add(self::SETTLE_MS, fn (): bool => $this->settleCheck());
    }

    /**
     * One check of a settling area. Unmapped or unsized it stops until the map or changed
     * handler starts it again. Otherwise it notes a user scroll since the last check, and ends
     * the settle once the adjustments have held still over SETTLE_CHECKS checks or the cap is
     * reached: the held scroll is made, then a noted user scroll posts.
     * @return bool
     */
    private function settleCheck(): bool
    {
        $seen = $this->adjustments();
        if (is_null($seen)) {
            $this->settle_source = null;
            $this->settle_seen = null;
            $this->settle_still = 0;

            return G_SOURCE_REMOVE;
        }
        if (! is_null($this->settle_seen) && self::userMoved($this->settle_seen, $seen, ! $this->settle_from_map)) {
            $this->user_scrolled = true;
        }
        if ($seen === $this->settle_seen) {
            $this->settle_still++;
        } else {
            $this->settle_seen = $seen;
            $this->settle_still = 0;
        }
        $capped = hrtime(true) - $this->settle_started >= self::SETTLE_CAP_MS * 1_000_000;
        if ($this->settle_still < self::SETTLE_CHECKS && ! $capped) {
            return G_SOURCE_CONTINUE;
        }

        $this->settle_source = null;
        $this->settle_seen = null;
        $this->settle_still = 0;
        $this->settling = false;
        $this->settle_from_map = false;
        $scroll = $this->pending_scroll;
        $this->pending_scroll = null;
        if (! is_null($scroll)) {
            $scroll();
        }
        if ($this->user_scrolled) {
            $this->user_scrolled = false;
            $this->postScrolled();
        }

        return G_SOURCE_REMOVE;
    }

    /**
     * Value, upper and page size of each axis; null while the view is unmapped or has no page.
     * @return list<float>|null
     */
    private function adjustments(): ?array
    {
        $horizontal = $this->scrolled->getHadjustment();
        $vertical = $this->scrolled->getVadjustment();
        if (! $this->text_view->getMapped() || $vertical->getPageSize() <= 0.0) {
            return null;
        }

        return [
            $horizontal->getValue(), $horizontal->getUpper(), $horizontal->getPageSize(),
            $vertical->getValue(), $vertical->getUpper(), $vertical->getPageSize(),
        ];
    }

    /**
     * Whether, between two checks, either axis moved as a user moves it: its value with its
     * upper and page size held (layout moves upper or page with it), or its page size from one
     * it already had (a resize; a first sizing from 0, or the sizing of a first show, is the
     * layout's, so $resizes is false while a settle runs from the view mapping).
     *
     * @param list<float> $was value, upper, page size of each axis
     * @param list<float> $now
     * @param bool $resizes whether a page size change counts
     * @return bool
     */
    private static function userMoved(array $was, array $now, bool $resizes): bool
    {
        foreach ([0, 3] as $axis) {
            [$value, $upper, $page] = [$now[$axis], $now[$axis + 1], $now[$axis + 2]];
            [$was_value, $was_upper, $was_page] = [$was[$axis], $was[$axis + 1], $was[$axis + 2]];
            if ($resizes && $page !== $was_page && $was_page > 0.0) {
                return true;
            }
            if ($value !== $was_value && $upper === $was_upper && $page === $was_page) {
                return true;
            }
        }

        return false;
    }

    /**
     * Make $scroll now when the layout is settled, or hold it (replacing any held one) until it is.
     *
     * @param Closure(): void $scroll
     * @return void
     */
    private function scrollWhenSettled(Closure $scroll): void
    {
        if ($this->settling) {
            $this->pending_scroll = $scroll;

            return;
        }
        $scroll();
    }

    /**
     * Set both adjustments without posting; a target within half a unit of where the view
     * already is leaves it alone.
     */
    private function scrollAdjustmentsTo(float $left, float $top): void
    {
        $horizontal = $this->scrolled->getHadjustment();
        $vertical = $this->scrolled->getVadjustment();
        if (abs($left - $horizontal->getValue()) <= 0.5 && abs($top - $vertical->getValue()) <= 0.5) {
            return;
        }
        $this->quietly(function () use ($left, $top, $horizontal, $vertical): void {
            $horizontal->setValue($left);
            $vertical->setValue($top);
        });
    }

    /**
     * The tag for a role (a GtkTextTag colour property) and colour, made and added once.
     */
    private function tag(string $property, Color $color): \GtkTextTag
    {
        $key = "{$property}|{$color->toCss()}";
        if (! isset($this->tags[$key])) {
            $tag = \GtkTextTag::new(null);
            g_object_set_property($tag, $property, $color->toCss());
            $this->text_view->getBuffer()->getTagTable()->add($tag);
            $this->tags[$key] = $tag;
        }

        return $this->tags[$key];
    }

    /**
     * @param array<int, \GtkTextTag> $tags
     */
    private function untag(array $tags): void
    {
        $buffer = $this->text_view->getBuffer();
        foreach ($tags as $tag) {
            $buffer->removeTag($tag, $buffer->getStartIter(), $buffer->getEndIter());
        }
    }
}

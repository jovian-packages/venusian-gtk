<?php

namespace Jovian\Toolkits\GTK\UI;

use Closure;
use Surface\NutsAndBolts\Color;
use Surface\Toolkits\UI\TKTextArea;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;
use Surface\Contracts\Toolkits\Styling\FontSpec;
use Jovian\Toolkits\GTK\UI\Support\GtkMacTextKeys;
use Surface\Contracts\Toolkits\Styling\FontWeight;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Surface\Contracts\Toolkits\Styling\ScrollPosition;
use Surface\Contracts\Toolkits\Styling\TextRangeStyle;

/**
 * A GtkTextView in a scrolled window. GTK lays text out over several frames, so after each
 * change from code it settles: a scroll from code waits for that, and what moves meanwhile is
 * not the user's.
 */
class GtkTextArea extends TKTextArea
{
    use GtkNative {
        applyBackground as private paintBackground;
        releaseNative as private releaseWidget;
    }
    use GtkText {
        applyFont as private styleFont;
        applyTextColor as private styleColor;
    }

    /** How often a settling area reads its adjustments: about a frame. */
    public const int SETTLE_MS = 20;

    /** Checks in a row that must find the adjustments unchanged before the layout counts as settled. */
    public const int SETTLE_CHECKS = 2;

    /** The longest a settle lasts, from its start (or from the view mapping, if it started unmapped). */
    public const int SETTLE_CAP_MS = 500;

    private ?\GtkTextView $text_view = null;

    private ?\GtkScrolledWindow $scrolled = null;

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
     * @throws ToolkitRuntimeException When the name is not valid.
     */
    protected function bootstrapNative(): void
    {
        $self = \WeakReference::create($this);
        $this->text_view = \GtkTextView::new();
        $this->text_view->setWrapMode(\GtkWrapMode::WORD_CHAR);
        if (PHP_OS_FAMILY === 'Darwin') {
            GtkMacTextKeys::attach($this->text_view, static fn (): ?\GtkWidget => $self->get()?->text_view);
        }
        $this->scrolled = \GtkScrolledWindow::new();
        $this->scrolled->setPolicy(\GtkPolicyType::NEVER, \GtkPolicyType::AUTOMATIC);
        $this->scrolled->setChild($this->text_view);
        $this->adoptNative($this->scrolled);
        $this->scrolled->setSizeRequest(160, 80);
        $this->write(fn () => $this->applyValue($this->value));
        // A new view has no layout yet: its first scroll waits for one.
        $this->settle();

        $buffer = $this->text_view->getBuffer();
        $this->connect($buffer, 'begin-user-action', static function () use ($self): void {
            $area = $self->get();
            if (! is_null($area)) {
                $area->user_action_depth++;
            }
        });
        $this->connect($buffer, 'end-user-action', static function () use ($self): void {
            $self->get()?->actionEnded();
        });
        $this->connect($buffer, 'changed', static function () use ($self): void {
            $self->get()?->bufferChanged();
        });
        $moved = static function () use ($self): void {
            $self->get()?->adjustmentMoved();
        };
        $this->connect($this->scrolled->getHadjustment(), 'value-changed', $moved);
        $this->connect($this->scrolled->getVadjustment(), 'value-changed', $moved);
        // A settling area that is not on screen stops checking. Mapping it starts the checks
        // and the cap over (GTK lays out only from there); GTK sizing it again (a page size or
        // content height) starts the checks over.
        $this->connect($this->text_view, 'map', static function () use ($self): void {
            $area = $self->get();
            if ($area?->settling) {
                $area->settle_started = hrtime(true);
                $area->settle_seen = null;
                $area->settle_from_map = true;
                $area->settle();
            }
        });
        $resized = static function () use ($self): void {
            $area = $self->get();
            if ($area?->settling) {
                $area->settle();
            }
        };
        $this->connect($this->scrolled->getHadjustment(), 'changed', $resized);
        $this->connect($this->scrolled->getVadjustment(), 'changed', $resized);
    }

    public function hasFocus(): bool
    {
        return ! is_null($this->native) && $this->native->getRoot()?->getFocus() === $this->text_view;
    }

    protected function nativeFocus(): bool
    {
        return $this->text_view->grabFocus();
    }

    /** Background and colour reach the text view and its text node, which the theme paints. */
    protected function styleSelector(): string
    {
        $class = ".{$this->styleClass()}";

        return "{$class}, {$class} > textview, {$class} > textview > text";
    }

    private function bufferChanged(): void
    {
        if ($this->isRemoved() || $this->isWriting()) {
            return;
        }
        // GTK moved the tags with the edit, or dropped them with the text they covered.
        $this->applied_lines = null;
        $this->applied_ranges = null;
        if ($this->user_action_depth > 0) {
            $this->changed_in_action = true;

            return;
        }
        $this->typed($this->bufferText());
    }

    /** A paste over a selection is a delete and an insert: one change. */
    private function actionEnded(): void
    {
        $this->user_action_depth = max(0, $this->user_action_depth - 1);
        if ($this->user_action_depth === 0 && $this->changed_in_action) {
            $this->changed_in_action = false;
            $this->typed($this->bufferText());
        }
    }

    private function adjustmentMoved(): void
    {
        if (! $this->settling) {
            $this->scrolled();
        }
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
        $this->styleFont($font);
        $this->applyTabWidth($this->tab_width);
    }

    /** One stop, in pixels, which Pango repeats at the same interval: columns × the font's character width. */
    protected function applyTabWidth(int $columns): void
    {
        $context = $this->text_view->getPangoContext();
        $width = is_null($this->font)
            ? $context->getMetrics(null, null)->getApproximateCharWidth() / PANGO_SCALE
            : $context->getMetrics(self::description($this->font), null)->getApproximateCharWidth() / PANGO_SCALE;
        $tabs = \PangoTabArray::new(1, true);
        $tabs->setTab(0, \PangoTabAlign::LEFT, (int) round($columns * $width));
        $this->relayout(fn () => $this->text_view->setTabs($tabs));
    }

    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->relayout(fn () => g_object_set_property($this->text_view, 'justification', match ($alignment) {
            TextAlignment::LEFT => \GtkJustification::LEFT->value,
            TextAlignment::CENTER => \GtkJustification::CENTER->value,
            TextAlignment::RIGHT => \GtkJustification::RIGHT->value,
        }));
    }

    protected function applyTextColor(?Color $color): void
    {
        $this->styleColor($color);
        $this->settle();
    }

    protected function applyBackground(?Color $color): void
    {
        $this->paintBackground($color);
        $this->settle();
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
                $tag = $this->tag('paragraph-background', $color->toCss());
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
            $style->bold, $style->italic, $style->underline, $style->strikeout,
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
                // weight 700, PANGO_STYLE_ITALIC, PANGO_UNDERLINE_SINGLE: g_object_set_property takes the ints for the enums.
                foreach ([
                    'foreground' => $style->foreground?->toCss(),
                    'background' => $style->background?->toCss(),
                    'weight' => $style->bold ? 700 : null,
                    'style' => $style->italic ? 2 : null,
                    'underline' => $style->underline ? 1 : null,
                    'strikethrough' => $style->strikeout ? true : null,
                ] as $property => $value) {
                    if (is_null($value)) {
                        continue;
                    }
                    $tag = $this->tag($property, $value);
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
        $this->write(fn () => $buffer->selectRange(
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
        $this->releaseWidget();
    }

    /**
     * Write something GTK lays out again (text, wrapping, tags) without posting, and settle.
     *
     * @param Closure(): void $write
     * @return void
     */
    private function relayout(Closure $write): void
    {
        $this->write($write);
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
        $this->settle_source ??= g_timeout_add(self::SETTLE_MS, self::checker(\WeakReference::create($this)));
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
            $this->scrolled();
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
        $this->write(function () use ($left, $top, $horizontal, $vertical): void {
            $horizontal->setValue($left);
            $vertical->setValue($top);
        });
    }

    /**
     * The tag for a GtkTextTag property and value (a CSS colour, a weight, a flag), made and added once.
     */
    private function tag(string $property, string|int|bool $value): \GtkTextTag
    {
        $key = "{$property}|".var_export($value, true);
        if (! isset($this->tags[$key])) {
            $tag = \GtkTextTag::new(null);
            g_object_set_property($tag, $property, $value);
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

    private static function checker(\WeakReference $self): Closure
    {
        return static fn (): bool => $self->get()?->settleCheck() ?? G_SOURCE_REMOVE;
    }

    /**
     * The comma ends the family list, so a family named like a style word stays a family. One
     * is always named: pango_context_get_metrics segfaults on a description with none.
     */
    private static function description(FontSpec $font): \PangoFontDescription
    {
        $family = $font->family ?? ($font->monospace ? 'Monospace' : 'Sans');
        $words = array_filter([
            $font->italic ? 'Italic' : '',
            match ($font->weight) {
                FontWeight::LIGHT => 'Light',
                FontWeight::REGULAR => '',
                FontWeight::MEDIUM => 'Medium',
                FontWeight::SEMIBOLD => 'Semi-Bold',
                FontWeight::BOLD => 'Bold',
                FontWeight::BLACK => 'Black',
            },
            rtrim(rtrim(number_format($font->size, 2, '.', ''), '0'), '.').'px',
        ]);

        return \PangoFontDescription::fromString("{$family}, ".implode(' ', $words));
    }
}

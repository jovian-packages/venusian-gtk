<?php

namespace Jovian\Toolkits\GTK\UI;

use GtkBox;
use GtkAlign;
use WeakReference;
use GtkOrientation;
use GtkImage as NativeImage;
use GtkLabel as NativeLabel;
use GtkButton as NativeButton;
use Surface\Toolkits\UI\TKButton;
use Surface\Contracts\Toolkits\UI\ButtonRole;
use Surface\Contracts\Toolkits\UI\ButtonStyle;
use Surface\Contracts\Toolkits\UI\IconPosition;
use Surface\Contracts\Toolkits\UI\Insets;
use Surface\Contracts\Toolkits\Styling\TextAlignment;
use Jovian\Toolkits\GTK\GtkWindow;
use Jovian\Toolkits\GTK\UI\Concerns\GtkText;
use Jovian\Toolkits\GTK\UI\Concerns\GtkNative;

/**
 * A GtkButton. It takes keyboard focus by default; its clicked signal posts ButtonClicked.
 *
 * Borderless is GTK's frameless button (has-frame false, the theme's flat class); a link is
 * that and the theme's link class, which colours it. Font and colour are CSS on the button,
 * which its label inherits. With an icon the child is a box of a GtkImage and the label, the
 * way GTK 4 puts an image beside a label in a button (it has no single call for both). The
 * DEFAULT role is the window's default widget, which Return activates; GTK has no cancel
 * button for a window, so CANCEL is not shown. Padding is CSS padding on the button node
 * (top right bottom left, in pixels), which replaces the theme's; none removes the declaration.
 */
class GtkButton extends TKButton
{
    use GtkNative {
        releaseNative as private releaseGtkNative;
    }
    use GtkText;

    /** Points between the image and the label. */
    private const int ICON_SPACING = 6;

    private TextAlignment $alignment = TextAlignment::CENTER;

    /** The image and label inside the button while it shows an icon. */
    private ?GtkBox $content = null;

    private ?NativeImage $image = null;

    private ?NativeLabel $content_label = null;

    protected function bootstrapNative(): void
    {
        $this->adoptNative(NativeButton::newWithLabel($this->label()));

        $self = WeakReference::create($this);
        $this->connect($this->native, 'clicked', static function () use ($self): void {
            $self->get()?->clicked();
        });
    }

    /**
     * GTK keeps a destroyed default widget as its window's default: cleared first.
     */
    protected function releaseNative(): void
    {
        $this->leaveDefault();
        $this->releaseGtkNative();
    }

    protected function applyLabel(string $label): void
    {
        if (! is_null($this->content_label)) {
            $this->content_label->setText($label);

            return;
        }

        $this->buttonNative()->setLabel($label);
        // A button may take a new label child: the new one takes the alignment.
        $this->applyAlignment($this->alignment);
    }

    protected function applyStyle(ButtonStyle $style): void
    {
        $button = $this->buttonNative();
        // GTK has no help button: HELP is a framed button, its label shown.
        $button->setHasFrame($style->framed());
        $style === ButtonStyle::LINK ? $button->addCssClass('link') : $button->removeCssClass('link');
    }

    /**
     * The label's own alignment; with an icon, the image and label together sit at that side.
     */
    protected function applyAlignment(TextAlignment $alignment): void
    {
        $this->alignment = $alignment;

        if (! is_null($this->content)) {
            $this->content->setHalign(match ($alignment) {
                TextAlignment::LEFT => GtkAlign::START,
                TextAlignment::CENTER => GtkAlign::CENTER,
                TextAlignment::RIGHT => GtkAlign::END,
            });

            return;
        }

        $label = $this->buttonNative()->getChild();
        if ($label instanceof NativeLabel) {
            $label->setXalign(self::xalign($alignment));
        }
    }

    /**
     * The file when there is one (GTK shows its broken-image icon for one it cannot read), else
     * the icon theme's $symbol; the image goes on the side iconPosition() names.
     */
    protected function applyIcon(?string $file, ?string $symbol): void
    {
        $button = $this->buttonNative();

        if (is_null($file) && is_null($symbol)) {
            if (! is_null($this->content)) {
                $this->content = $this->image = $this->content_label = null;
                $button->setChild(null);
                $button->setLabel($this->label());
                $this->applyAlignment($this->alignment);
            }

            return;
        }

        $image = NativeImage::newFromIconName(is_null($file) ? $symbol : null);
        if (! is_null($file)) {
            $image->setFromFile($file);
        }

        if (is_null($this->content)) {
            $this->content = GtkBox::new(GtkOrientation::HORIZONTAL, self::ICON_SPACING);
            $this->content_label = NativeLabel::new($this->label());
            $this->content->append($this->content_label);
            $button->setChild($this->content);
        } else {
            $this->content->remove($this->image);
        }

        $this->image = $image;
        $this->placeImage();
        $this->applyAlignment($this->alignment);
    }

    protected function applyPadding(Insets $padding): void
    {
        $this->declare('padding', $padding->isZero() ? null : self::cssSides($padding));
    }

    /**
     * The image goes before the label or after it, in the box's reading order.
     */
    protected function applyIconPosition(IconPosition $position): void
    {
        if (! is_null($this->content)) {
            $this->content->remove($this->image);
            $this->placeImage();
        }
    }

    private function placeImage(): void
    {
        $this->iconPosition() === IconPosition::TRAILING
            ? $this->content->append($this->image)
            : $this->content->prepend($this->image);
    }

    /**
     * DEFAULT is the window's default widget (GTK draws it as the suggested button and Return
     * activates it); another role takes it back.
     */
    protected function applyRole(?ButtonRole $role): void
    {
        if ($role === ButtonRole::DEFAULT) {
            $this->gtkWindow()?->native()->setDefaultWidget($this->native());

            return;
        }

        $this->leaveDefault();
    }

    private function leaveDefault(): void
    {
        $window = $this->gtkWindow();
        if (! is_null($window) && ! is_null($this->native) && $window->native()->getDefaultWidget() === $this->native) {
            $window->native()->setDefaultWidget(null);
        }
    }

    private function gtkWindow(): ?GtkWindow
    {
        $window = $this->window();

        return $window instanceof GtkWindow && $window->isOpen() ? $window : null;
    }

    private function buttonNative(): NativeButton
    {
        /** @var NativeButton */
        return $this->native();
    }
}

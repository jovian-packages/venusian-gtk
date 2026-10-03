<?php

declare(strict_types=1);

use Jovian\Toolkits\GTK\Primitives\GTKButton;
use Jovian\Toolkits\GTK\Primitives\GTKCheckbox;
use Jovian\Toolkits\GTK\Primitives\GTKDatepicker;
use Jovian\Toolkits\GTK\Primitives\GTKDropdown;
use Jovian\Toolkits\GTK\Primitives\GTKSlider;
use Jovian\Toolkits\GTK\Primitives\GTKToggle;
use Jovian\Toolkits\GTK\Primitives\GTKToggleButton;
use Jovian\Toolkits\GTK\Primitives\GTKImage;
use Jovian\Toolkits\GTK\Primitives\GTKLabel;
use Jovian\Toolkits\GTK\Primitives\GTKProgressBar;
use Jovian\Toolkits\GTK\Primitives\GTKSeparator;
use Jovian\Toolkits\GTK\Primitives\GTKSpinner;
use Jovian\Toolkits\GTK\Primitives\GTKTextArea;
use Jovian\Toolkits\GTK\Primitives\GTKTextInput;
use Surface\Contracts\Windows\Mail\View\ButtonClicked;
use Surface\Contracts\Windows\Mail\View\DateChanged;
use Surface\Contracts\Windows\Mail\View\SelectionChanged;
use Surface\Contracts\Windows\Mail\View\Toggled;
use Surface\Contracts\Windows\Mail\View\ValueChanged;
use Surface\Contracts\Windows\Mail\View\TextChanged;
use Surface\Contracts\Windows\Mail\View\TextSubmitted;
use Surface\Contracts\Windows\Primitives\ImageScaling;
use Surface\Contracts\Windows\Styling\FontSpec;
use Surface\Contracts\Windows\Styling\FontWeight;
use Surface\Contracts\Windows\Styling\TextAlignment;
use Surface\Contracts\Windows\WindowException;
use Surface\NutsAndBolts\Color;

beforeEach(function (): void {
    driver()->closeAll();
    pumpFor(0.05);
    viewMail(session());
});

it('writes label text, wrap, alignment, font and colour', function (): void {
    $label = driver()->open('main', 300, 200)->column('m')->label('l', 'Hello');

    expect($label)->toBeInstanceOf(GTKLabel::class)
        ->and($label->native())->toBeInstanceOf(\GtkLabel::class)
        ->and($label->native()->getText())->toBe('Hello')
        ->and($label->native()->getXalign())->toBe(0.0);

    $label->setText('Bye')->setWrap(true)->setAlignment(TextAlignment::RIGHT)
        ->setFont(new FontSpec(14.5, FontWeight::BOLD, 'Noto "Sans"'))->setTextColor(Color::hex('#336699'));
    expect($label->native()->getText())->toBe('Bye')
        ->and($label->native()->getWrap())->toBeTrue()
        ->and($label->native()->getXalign())->toBe(1.0)
        ->and($label->stylesheet())->toBe(".{$label->styleClass()} { color: rgba(51, 102, 153, 1); font-size: 14.5px; font-weight: 700; font-family: \"Noto \\22 Sans\\22 \"; }");

    $label->setAlignment(TextAlignment::CENTER)->setTextColor(null);
    expect($label->native()->getXalign())->toBe(0.5)
        ->and($label->stylesheet())->toBe(".{$label->styleClass()} { font-size: 14.5px; font-weight: 700; font-family: \"Noto \\22 Sans\\22 \"; }");
});

it('posts ButtonClicked with the path and uuid', function (): void {
    $window = driver()->open('main', 300, 200);
    $button = $window->column('m')->button('go', 'Go');
    $window->present();
    pumpFor(0.2);
    viewMail(session());
    $button->native()->activate();
    pumpFor(0.4);

    expect($button)->toBeInstanceOf(GTKButton::class)
        ->and(viewMail(session()))->toEqual([new ButtonClicked('main', 'm.go', $button->uuid())])
        ->and($button->label())->toBe('Go');
    $button->setLabel('Run');
    expect($button->native()->getLabel())->toBe('Run');

    // A disabled control posts nothing, whether disabled itself or inside a disabled container.
    $button->disable();
    expect($button->native()->getSensitive())->toBeFalse();
    $button->native()->activate();
    pumpFor(0.4);
    expect(viewMail(session()))->toBe([]);

    $button->enable();
    $window->content()->disable();
    expect($button->native()->isSensitive())->toBeFalse();
    $button->native()->activate();
    pumpFor(0.4);
    expect(viewMail(session()))->toBe([]);
});

it('shows an image file, refuses a missing or unloadable one, and scales', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $image = $column->image('i', __DIR__.'/../fixtures/pixel.png');

    expect($image)->toBeInstanceOf(GTKImage::class)
        ->and($image->native())->toBeInstanceOf(\GtkPicture::class)
        ->and($image->native()->getPaintable())->not->toBeNull()
        ->and($image->native()->getContentFit())->toBe(\GtkContentFit::CONTAIN)
        ->and(fn () => $image->setFile('/nope/pixel.png'))->toThrow(WindowException::class, 'does not exist')
        ->and(fn () => $image->setFile(__DIR__.'/../fixtures/broken.png'))->toThrow(WindowException::class, 'nothing to show')
        ->and($image->file())->toBe(__DIR__.'/../fixtures/pixel.png')
        ->and($image->native()->getPaintable())->not->toBeNull()
        ->and(fn () => $column->image('missing', '/nope/pixel.png'))->toThrow(WindowException::class, 'does not exist')
        ->and($column->view('missing'))->toBeNull();

    foreach ([[ImageScaling::FILL, \GtkContentFit::COVER], [ImageScaling::CENTER, \GtkContentFit::SCALE_DOWN], [ImageScaling::STRETCH, \GtkContentFit::FILL]] as [$scaling, $fit]) {
        $image->setScaling($scaling);
        expect($image->native()->getContentFit())->toBe($fit);
    }

    $image->setFile(null);
    expect($image->native()->getPaintable())->toBeNull()
        ->and($column->image('empty')->native()->getPaintable())->toBeNull();
});

it('draws a horizontal or vertical separator', function (): void {
    $window = driver()->open('main', 300, 200);
    $column = $window->column('m');
    $across = $column->separator('h');
    $row = $column->row('r')->fill();
    $down = $row->separator('v', horizontal: false);
    $window->present();
    pumpFor(0.2);

    expect($across)->toBeInstanceOf(GTKSeparator::class)
        ->and($across->native())->toBeInstanceOf(\GtkSeparator::class)
        ->and($across->size()[0])->toBeGreaterThan($across->size()[1])
        ->and($down->size()[1])->toBeGreaterThan($down->size()[0]);
});

it('starts and stops a spinner', function (): void {
    $spinner = driver()->open('main', 300, 200)->column('m')->spinner('s');

    expect($spinner)->toBeInstanceOf(GTKSpinner::class)
        ->and($spinner->native()->getSpinning())->toBeFalse();
    $spinner->start();
    expect($spinner->native()->getSpinning())->toBeTrue();
    $spinner->stop();
    expect($spinner->native()->getSpinning())->toBeFalse();
});

it('fills a progress bar and pulses it while the fraction is unknown', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $bar = $column->progressBar('p', 0.25);

    expect($bar)->toBeInstanceOf(GTKProgressBar::class)
        ->and($bar->native()->getFraction())->toBe(0.25)
        ->and($bar->isPulsing())->toBeFalse();

    $bar->setFraction(null);
    expect($bar->isPulsing())->toBeTrue();
    $bar->setFraction(0.75);
    expect($bar->isPulsing())->toBeFalse()
        ->and($bar->native()->getFraction())->toBe(0.75);

    $pulsing = $column->progressBar('q', null);
    expect($pulsing->isPulsing())->toBeTrue();
    $pulsing->remove();
    expect($pulsing->isPulsing())->toBeFalse();
});

it('posts TextChanged for typing and TextSubmitted for enter, nothing for code', function (): void {
    $input = driver()->open('main', 300, 200)->column('m')->textInput('name', 'a', placeholder: 'Name', secret: true);

    expect($input)->toBeInstanceOf(GTKTextInput::class)
        ->and($input->native())->toBeInstanceOf(\GtkEntry::class)
        ->and($input->native()->getBuffer()->getText())->toBe('a')
        ->and($input->native()->getPlaceholderText())->toBe('Name')
        ->and($input->native()->getVisibility())->toBeFalse()
        ->and(viewMail(session()))->toBe([]);

    $input->native()->getBuffer()->setText('abc', -1);
    expect($input->value())->toBe('abc')
        ->and(viewMail(session()))->toEqual([new TextChanged('main', 'm.name', $input->uuid(), 'abc')]);

    // Enter emits the entry's activate through its key binding.
    g_signal_emit_by_name($input->native(), 'activate');
    expect(viewMail(session()))->toEqual([new TextSubmitted('main', 'm.name', $input->uuid(), 'abc')]);

    $input->setValue('code')->setPlaceholder(null);
    expect($input->native()->getBuffer()->getText())->toBe('code')
        ->and((string) $input->native()->getPlaceholderText())->toBe('')
        ->and(viewMail(session()))->toBe([]);
});

it('edits a text area inside a scrolled window and posts TextChanged for edits only', function (): void {
    $area = driver()->open('main', 300, 200)->column('m')->textArea('notes', "one\ntwo");

    expect($area)->toBeInstanceOf(GTKTextArea::class)
        ->and($area->native())->toBeInstanceOf(\GtkScrolledWindow::class)
        ->and($area->textView()->getParent())->toBe($area->native())
        ->and($area->textView()->getBuffer()->getText(0, -1))->toBe("one\ntwo")
        ->and(viewMail(session()))->toBe([]);

    // A user edit: GTK wraps it in a user action, inside which a replace deletes, then inserts.
    $buffer = $area->textView()->getBuffer();
    $buffer->beginUserAction();
    $buffer->setText('typed');
    $buffer->endUserAction();
    expect($area->value())->toBe('typed')
        ->and(viewMail(session()))->toEqual([new TextChanged('main', 'm.notes', $area->uuid(), 'typed')]);

    // A change outside any user action posts on its own.
    $buffer->setText('');
    expect(viewMail(session()))->toEqual([new TextChanged('main', 'm.notes', $area->uuid(), '')]);

    $area->setValue('code')->setTextColor(Color::rgb(0, 0, 0))->setBackground(Color::rgb(255, 255, 255));
    expect($area->textView()->getBuffer()->getText(0, -1))->toBe('code')
        ->and($area->stylesheet())->toContain(".{$area->styleClass()} > textview > text { background-color: rgba(255, 255, 255, 1); background-image: none; color: rgba(0, 0, 0, 1); }")
        ->and(viewMail(session()))->toBe([]);
});

it('disconnects every handler when a leaf is removed', function (): void {
    $input = driver()->open('main', 300, 200)->column('m')->textInput('t');
    $buffer = $input->native()->getBuffer();
    $input->remove();
    $buffer->setText('after', -1);

    expect(viewMail(session()))->toBe([])
        ->and($input->native()->getParent())->toBeNull();
});

it('posts Toggled from a checkbox, nothing for code', function (): void {
    $check = driver()->open('main', 300, 200)->column('m')->checkbox('c', 'Remember', checked: true);

    expect($check)->toBeInstanceOf(GTKCheckbox::class)
        ->and($check->native())->toBeInstanceOf(\GtkCheckButton::class)
        ->and($check->native()->getActive())->toBeTrue()
        ->and($check->native()->getLabel())->toBe('Remember');

    $check->native()->setActive(false);
    expect($check->isChecked())->toBeFalse()
        ->and(viewMail(session()))->toEqual([new Toggled('main', 'm.c', $check->uuid(), false)]);

    $check->setChecked(true)->setLabel('Keep');
    expect($check->native()->getActive())->toBeTrue()
        ->and($check->native()->getLabel())->toBe('Keep')
        ->and(viewMail(session()))->toBe([]);
});

it('posts Toggled from a switch, nothing for code', function (): void {
    $toggle = driver()->open('main', 300, 200)->column('m')->toggle('t');

    expect($toggle)->toBeInstanceOf(GTKToggle::class)
        ->and($toggle->native())->toBeInstanceOf(\GtkSwitch::class)
        ->and($toggle->native()->getActive())->toBeFalse();

    $toggle->native()->setActive(true);
    expect($toggle->isOn())->toBeTrue()
        ->and(viewMail(session()))->toEqual([new Toggled('main', 'm.t', $toggle->uuid(), true)]);

    $toggle->setOn(false);
    expect($toggle->native()->getActive())->toBeFalse()
        ->and(viewMail(session()))->toBe([]);
});

it('posts Toggled from a toggle button, nothing for code', function (): void {
    $button = driver()->open('main', 300, 200)->column('m')->toggleButton('b', 'Bold', pressed: false);

    expect($button)->toBeInstanceOf(GTKToggleButton::class)
        ->and($button->native())->toBeInstanceOf(\GtkToggleButton::class)
        ->and($button->native()->getLabel())->toBe('Bold');

    $button->native()->setActive(true);
    expect($button->isPressed())->toBeTrue()
        ->and(viewMail(session()))->toEqual([new Toggled('main', 'm.b', $button->uuid(), true)]);

    $button->setPressed(false)->setLabel('Italic');
    expect($button->native()->getActive())->toBeFalse()
        ->and($button->native()->getLabel())->toBe('Italic')
        ->and(viewMail(session()))->toBe([]);
});

it('posts ValueChanged from a slider and rescales its keyboard step with its range', function (): void {
    $slider = driver()->open('main', 300, 200)->column('m')->slider('s', 0.0, 100.0, 40.0);

    expect($slider)->toBeInstanceOf(GTKSlider::class)
        ->and($slider->native())->toBeInstanceOf(\GtkScale::class)
        ->and($slider->native()->getValue())->toBe(40.0)
        ->and($slider->native()->getDrawValue())->toBeFalse();

    // The user drags to 62.5 (GTK_SCROLL_JUMP = 1).
    g_signal_emit_by_name($slider->native(), 'change-value', 1, 62.5);
    expect($slider->value())->toBe(62.5)
        ->and(viewMail(session()))->toEqual([new ValueChanged('main', 'm.s', $slider->uuid(), 62.5)]);

    // A narrower range clamps the value, with no mail, and an arrow key moves a hundredth of it.
    $slider->setRange(0.0, 1.0);
    expect($slider->value())->toBe(1.0)
        ->and($slider->native()->getValue())->toBe(1.0)
        ->and(viewMail(session()))->toBe([]);
    g_signal_emit_by_name($slider->native(), 'move-slider', 2);
    expect($slider->value())->toBe(0.99)
        ->and(viewMail(session()))->toEqual([new ValueChanged('main', 'm.s', $slider->uuid(), 0.99)]);

    $slider->setValue(0.5);
    expect($slider->native()->getValue())->toBe(0.5)
        ->and(viewMail(session()))->toBe([]);
});

it('posts SelectionChanged from a drop-down, nothing for code', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $drop = $column->dropdown('d', ['red', 'green', 'blue'], 1);

    expect($drop)->toBeInstanceOf(GTKDropdown::class)
        ->and($drop->native())->toBeInstanceOf(\GtkDropDown::class)
        ->and($drop->native()->getSelected())->toBe(1)
        ->and($drop->native()->getSelectedItem()->getString())->toBe('green');

    $drop->native()->setSelected(2);
    expect($drop->selected())->toBe(2)
        ->and(viewMail(session()))->toEqual([new SelectionChanged('main', 'm.d', $drop->uuid(), 2, 'blue')]);

    $drop->setOptions(['one', 'two'])->select(1);
    expect($drop->native()->getSelectedItem()->getString())->toBe('two')
        ->and(viewMail(session()))->toBe([]);

    $drop->setOptions([]);
    expect($drop->selected())->toBe(-1)
        ->and($drop->native()->getSelected())->toBe(GTK_INVALID_LIST_POSITION)
        ->and(viewMail(session()))->toBe([]);

    $empty = $column->dropdown('e', []);
    expect($empty->native()->getSelected())->toBe(GTK_INVALID_LIST_POSITION);
});

it('posts DateChanged from a calendar as midnight of the picked day, nothing for code', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $picker = $column->datepicker('p', new DateTimeImmutable('2026-10-02 15:30:00'));

    expect($picker)->toBeInstanceOf(GTKDatepicker::class)
        ->and($picker->native())->toBeInstanceOf(\GtkCalendar::class)
        ->and($picker->native()->getDate()->getDayOfMonth())->toBe(2)
        ->and($picker->native()->getDate()->getMonth())->toBe(10)
        ->and(viewMail(session()))->toBe([]);

    $picker->native()->selectDay(GDateTime::newLocal(2026, 11, 5, 0, 0, 0.0));
    pumpFor(0.05);
    expect($picker->date())->toEqual(new DateTimeImmutable('2026-11-05 00:00:00'))
        ->and(viewMail(session()))->toEqual([new DateChanged('main', 'm.p', $picker->uuid(), new DateTimeImmutable('2026-11-05 00:00:00'))]);

    $picker->setDate(new DateTimeImmutable('2027-01-31'));
    pumpFor(0.05);
    expect($picker->native()->getDate()->getYear())->toBe(2027)
        ->and($picker->native()->getDate()->getDayOfMonth())->toBe(31)
        ->and(viewMail(session()))->toBe([]);

    $blank = $column->datepicker('blank');
    expect($blank->date())->toBeNull()
        ->and($blank->native()->getDate())->toBeInstanceOf(GDateTime::class);
});

it('follows the calendar when the user pages a month: one DateChanged with the settled day', function (): void {
    $picker = driver()->open('main', 300, 300)->column('m')->datepicker('p', new DateTimeImmutable('2026-01-31'));
    pumpFor(0.05);
    viewMail(session());
    $buttons = [];
    $collect = function (GtkWidget $widget) use (&$collect, &$buttons): void {
        if ($widget instanceof \GtkButton) {
            $buttons[] = $widget;
        }
        for ($child = $widget->getFirstChild(); ! is_null($child); $child = $child->getNextSibling()) {
            $collect($child);
        }
    };
    $collect($picker->native());

    // GtkCalendar's header buttons: previous month, next month, previous year, next year.
    g_signal_emit_by_name($buttons[1], 'clicked');
    pumpFor(0.05);
    expect($picker->date())->toEqual(new DateTimeImmutable('2026-02-28 00:00:00'))
        ->and(viewMail(session()))->toEqual([new DateChanged('main', 'm.p', $picker->uuid(), new DateTimeImmutable('2026-02-28 00:00:00'))]);

    g_signal_emit_by_name($buttons[3], 'clicked');
    pumpFor(0.05);
    expect($picker->date())->toEqual(new DateTimeImmutable('2027-02-28 00:00:00'))
        ->and(viewMail(session()))->toHaveCount(1);
});

it('refuses a year a calendar cannot show and keeps its date', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $picker = $column->datepicker('p', new DateTimeImmutable('2026-10-02'));

    expect(fn () => $picker->setDate(new DateTimeImmutable('0000-06-01')))->toThrow(WindowException::class, '1-9999')
        ->and($picker->date())->toEqual(new DateTimeImmutable('2026-10-02'))
        ->and(fn () => $column->datepicker('q', new DateTimeImmutable('-0044-03-15')))->toThrow(WindowException::class, '1-9999')
        ->and($column->view('q'))->toBeNull();
});

it('colours an entry and a text area on the nodes the theme colours, and escapes a font family', function (): void {
    $column = driver()->open('main', 300, 200)->column('m');
    $input = $column->textInput('i')->setTextColor(Color::rgb(255, 0, 0));
    $area = $column->textArea('a')->setTextColor(Color::rgb(0, 0, 255));
    $label = $column->label('l', 'x')->setFont(new FontSpec(12.0, family: "Bad\nName \"x\""));

    $errors = 0;
    $probe = GtkCssProvider::new();
    g_signal_connect($probe, 'parsing-error', function () use (&$errors): void { $errors++; });
    $probe->loadFromString($label->stylesheet());

    expect($input->native()->getFirstChild()->getColor())->toBe([1.0, 0.0, 0.0, 1.0])
        ->and($area->textView()->getColor())->toBe([0.0, 0.0, 1.0, 1.0])
        ->and($label->stylesheet())->toContain('font-family: "Bad\\a Name \\22 x\\22 ";')
        ->and($errors)->toBe(0);
});

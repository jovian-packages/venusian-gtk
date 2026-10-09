<?php

namespace Jovian\Toolkits\GTK\Input;

/** What a GTK controller signal reported, copied into a SeenEvent. */
enum SeenKind
{
    case Key;
    case Motion;
    case Enter;
    case Leave;
    case Button;
    case Wheel;
    case FocusLost;
}

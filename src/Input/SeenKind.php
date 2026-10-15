<?php

namespace Jovian\Toolkits\GTK\Input;

/** What a GTK controller signal reported, copied into a SeenEvent. */
enum SeenKind
{
    case Key;
    case Text;
    case Motion;
    case Enter;
    case Leave;
    case Button;
    case GrabBroken;
    case Wheel;
    case FocusLost;
}

<?php

namespace Jovian\Toolkits\GTK\Bridge;

use Surface\Bridge\BridgedToolkitSession;
use Surface\Bridge\ToolkitBridgeDriver;
use Jovian\Toolkits\GTK\Contracts\Bridge\GTKBridgeDriver as BridgeContract;

class GTKBridgeDriver extends ToolkitBridgeDriver implements BridgeContract
{

    public function connect(): BridgedToolkitSession
    {
        // TODO: Implement connect() method.
    }
}
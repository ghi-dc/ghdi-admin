<?php

namespace App\Utils\Lod\Identifier;

class LocLdsAgentsIdentifier
extends UriIdentifier
{
    protected $name = 'lcagents';
    protected $prefix = 'lcauth';
    protected $baseUri = 'http://id.loc.gov/rwo/agents/';
}

<?php

namespace App\Utils\Lod\Identifier;

class LocLdsAgentsIdentifier
extends UriIdentifier
{
    protected $name = 'lcauth'; /* maybe use a different prefix, but they seem to match */
    protected $baseUri = 'http://id.loc.gov/rwo/agents/';
}

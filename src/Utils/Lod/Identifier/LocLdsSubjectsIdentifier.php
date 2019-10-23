<?php

namespace App\Utils\Lod\Identifier;

class LocLdsSubjectsIdentifier
extends UriIdentifier
{
    protected $name = 'lcauth'; /* maybe use a different prefix, but they seem to match */
    protected $baseUri = 'http://id.loc.gov/authorities/subjects/';
}

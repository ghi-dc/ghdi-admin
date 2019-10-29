<?php

namespace App\Utils\Lod\Identifier;

class LocLdsSubjectsIdentifier
extends UriIdentifier
{
    protected $name = 'lcsh';
    protected $prefix = 'lcauth';
    protected $baseUri = 'http://id.loc.gov/authorities/subjects/';
}

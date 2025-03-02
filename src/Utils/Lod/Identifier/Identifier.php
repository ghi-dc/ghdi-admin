<?php

namespace App\Utils\Lod\Identifier;

interface Identifier
{
    public function getValue();

    public function setValue($value);

    public function getName();
}

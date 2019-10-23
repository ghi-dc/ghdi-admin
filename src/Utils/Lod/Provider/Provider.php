<?php

namespace App\Utils\Lod\Provider;

use App\Utils\Lod\Identifier\Identifier;

interface Provider
{
   public function lookup(Identifier $identifier);

   public function getName();
}

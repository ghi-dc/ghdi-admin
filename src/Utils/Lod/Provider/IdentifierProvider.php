<?php

namespace App\Utils\Lod\Provider;

interface IdentifierProvider
{
    public function findMatchingIdentifier($identifier, $targetVocabulary);
}

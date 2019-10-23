<?php

namespace App\Utils\Lod\Identifier;

class UriIdentifier
extends AbstractIdentifier
{
    protected $baseUri = null;

    public function toUri()
    {
        return $this->baseUri . $this->getValue();
    }

    public function setValueFromUri($uri)
    {
        if (strpos($uri, $this->baseUri) === 0) {
            $value = substr($uri, strlen($this->baseUri));

            return $this->setValue($value);
        }

        return $this->setValue($uri);
    }

    public function getBaseUri()
    {
        return $this->baseUri;
    }
}

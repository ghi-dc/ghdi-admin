<?php

namespace App\Utils\Lod\Identifier;

class UriIdentifier
extends AbstractIdentifier
{
    protected $baseUri = null;
    protected $baseUriVariants = []; // GNDs come both with http and https

    public function toUri()
    {
        return $this->baseUri . $this->getValue();
    }

    public function setValueFromUri($uri)
    {
        $baseUris = [];
        if (!is_null($this->baseUri)) {
            $baseUris[] = $this->baseUri;
        }
        $baseUris = array_merge($baseUris, $this->baseUriVariants);

        foreach ($baseUris as $baseUri) {
            if (strpos($uri, $baseUri) === 0) {
                $value = substr($uri, strlen($baseUri));

                return $this->setValue($value);
            }
        }

        return $this->setValue($uri);
    }

    public function getBaseUri()
    {
        return $this->baseUri;
    }

    public function getBaseUriVariants()
    {
        return $this->baseUriVariants;
    }

    public function __toString()
    {
        return $this->toUri();
    }
}

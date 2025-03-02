<?php

namespace App\Utils\Lod\Identifier;

class Factory
{
    protected static $classByBaseUri = [];
    protected static $classByName = [];

    public static function register($class)
    {
        $instance = new $class();

        $name = $instance->getName();
        if (!array_key_exists($name, self::$classByName)) {
            self::$classByName[$name] = $class;
        }

        if ($instance instanceof UriIdentifier) {
            self::$classByBaseUri[$instance->getBaseUri()] = $class;
            foreach ($instance->getBaseUriVariants() as $baseUriVariant) {
                self::$classByBaseUri[$baseUriVariant] = $class;
            }
        }
    }

    public static function fromUri($uri)
    {
        foreach (self::$classByBaseUri as $baseUri => $class) {
            if (0 === strpos($uri, $baseUri)) {
                return new $class($uri);
            }
        }
    }

    public static function byName($name)
    {
        if (array_key_exists($name, self::$classByName)) {
            return new self::$classByName[$name]();
        }
    }

    /* this is a Singleton */
    private function __construct() {}
}

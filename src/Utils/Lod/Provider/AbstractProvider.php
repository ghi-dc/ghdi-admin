<?php

namespace App\Utils\Lod\Provider;

abstract class AbstractProvider
implements Provider
{
    protected static function normalizeString($str)
    {
        /**
         * you can run
         *  composer require symfony/polyfill-intl-normalizer
         * to make sure this function is available
         */
        if (!function_exists('normalizer_normalize')) {
            return $str;
        }

        return \normalizer_normalize($str);
    }

    protected $name;

    public function getName()
    {
        return $this->name;
    }
}

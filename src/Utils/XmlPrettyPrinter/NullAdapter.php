<?php

namespace App\Utils\XmlPrettyPrinter;

use App\Utils\Sprintf;

class NullAdapter
{
    var $config = [];

    function __construct($config = null)
    {
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    function prettyPrint()
    {
        return false;
    }
}

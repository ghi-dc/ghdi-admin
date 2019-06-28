<?php

namespace App\Utils\XmlPrettyPrinter;

class XmlPrettyPrinter
{
    var $config = [];
    var $adapter = null;

    public function __construct($config = null)
    {
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
    }

    public function setAdapter($adapter)
    {
        $this->adapter = $adapter;
    }

    public function prettyPrint(\App\Utils\XmlDocument $doc, $options = [])
    {
        if (isset($this->adapter)) {
            $res = $this->adapter->prettyPrint($doc->saveString(), $options);
            if (false !== $res) {
                $doc->loadString($res);
                
                return true;
            }
            
            return false;
        }
        
        return false;
    }
}

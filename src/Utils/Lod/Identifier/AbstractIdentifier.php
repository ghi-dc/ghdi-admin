<?php

namespace App\Utils\Lod\Identifier;

class AbstractIdentifier implements Identifier
{
    protected $name;
    protected $prefix;
    protected $value;

    public function __construct($value = null)
    {
        if (!is_null($value)) {
            if (method_exists($this, 'setValueFromUri')) {
                $this->setValueFromUri($value);
            }
            else {
                $this->setValue($value);
            }
        }
    }

    public function getName()
    {
        return $this->name;
    }

    /**
     * We want different names like lcsh, lccn map to the same prefix lcauth.
     */
    public function getPrefix()
    {
        if (empty($this->prefix)) {
            return $this->getName();
        }

        return $this->prefix;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }

    public function __toString()
    {
        return join(':', [$this->name, $this->value]);
    }
}

<?php

namespace App\Utils\Lod\Identifier;

class AbstractIdentifier
implements Identifier
{
    protected $name;
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

    public function getValue()
    {
        return $this->value;
    }

    public function setValue($value)
    {
        $this->value = $value;

        return $this;
    }
}

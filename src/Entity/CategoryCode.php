<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

use JMS\Serializer\Annotation as Serializer;

/**
 * Pending Schema for CategoryCode.
 *
 * @see https://schema.org/CategoryCode Documentation on Schema.org
 *
 * @Serializer\XmlRoot("CategoryCode")
 * @Serializer\XmlNamespace(uri="http://www.w3.org/XML/1998/namespace", prefix="xml")
 *
 */
class CategoryCode
extends SchemaOrg
{
    /**
     * A CategoryCodeSet that contains this category code.
     * 
     * @Serializer\XmlElement(cdata=false)
     * @Serializer\Type("string")
     */
    protected $inCodeSet;

    public function setCodeSet($codeSet)
    {
        $this->codeSet = $codeSet;
    }

    public function getCodeSet()
    {
        return $this->codeSet;
    }

    /**
     * Sets Getty Thesaurus of Geographic Names Identifier.
     *
     * @param string $tgn
     *
     * @return $this
     */
    public function setTgn($tgn)
    {
        return $this->setIdentifier('tgn', $tgn);

        return $this;
    }

    /**
     * Gets Getty Thesaurus of Geographic Names.
     *
     * @return string
     */
    public function getTgn()
    {
        return $this->getIdentifier('tgn');
    }
}

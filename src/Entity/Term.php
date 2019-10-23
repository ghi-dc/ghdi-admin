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
class Term
extends CategoryCode
{
    /**
     * A CategoryCodeSet that contains this category code.
     *
     * @Serializer\XmlElement(cdata=false)
     * @Serializer\Type("string")
     */
    protected $inCodeSet = 'https://d-nb.info/standards/elementset/gnd#SubjectHeadingSensoStricto';
}

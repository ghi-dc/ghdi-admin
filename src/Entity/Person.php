<?php

namespace App\Entity;

use JMS\Serializer\Annotation as Serializer;

/**
 * A person (alive, dead, undead, or fictional).
 *
 * @see http://schema.org/Person Documentation on Schema.org
 */
#[Serializer\XmlRoot('Person')]
#[Serializer\XmlNamespace(uri: 'http://www.w3.org/XML/1998/namespace', prefix: 'xml')]
class Person extends SchemaOrg
{
    /**
     * @var string date of birth
     */
    #[Serializer\XmlElement(cdata: false)]
    #[Serializer\Type('string')]
    protected $birthDate;

    /**
     * @var string date of death
     */
    #[Serializer\XmlElement(cdata: false)]
    #[Serializer\Type('string')]
    protected $deathDate;

    /**
     * @var string Family name. In the U.S., the last name of an Person. This can be used along with givenName instead of the name property.
     */
    #[Serializer\Type('string')]
    protected $familyName;

    /**
     * @var string gender of the person
     */
    #[Serializer\Type('string')]
    protected $gender;

    /**
     * @var string Given name. In the U.S., the first name of a Person. This can be used along with familyName instead of the name property.
     */
    #[Serializer\Type('string')]
    protected $givenName;

    /**
     * @var Place the place where the person was born
     */
    #[Serializer\Type('App\Entity\Place')]
    protected $birthPlace;

    /**
     * @var Place the place where the person died
     */
    #[Serializer\XmlElement(cdata: false)]
    #[Serializer\Type('string')]
    protected $deathPlace;

    /**
     * @var string
     */
    #[Serializer\Type('string')]
    protected $slug;

    /**
     * Sets birthDate.
     *
     * @param string|null $birthDate
     *
     * @return $this
     */
    public function setBirthDate($birthDate = null)
    {
        $this->birthDate = self::formatDateIncomplete($birthDate);

        return $this;
    }

    /**
     * Gets birthDate.
     *
     * @return string|null
     */
    public function getBirthDate()
    {
        return $this->birthDate;
    }

    /**
     * Sets deathDate.
     *
     * @param string|null $deathDate
     *
     * @return $this
     */
    public function setDeathDate($deathDate = null)
    {
        $this->deathDate = self::formatDateIncomplete($deathDate);

        return $this;
    }

    /**
     * Gets deathDate.
     *
     * @return string|null
     */
    public function getDeathDate()
    {
        return $this->deathDate;
    }

    /**
     * Sets familyName.
     *
     * @param string $familyName
     *
     * @return $this
     */
    public function setFamilyName($familyName)
    {
        $this->familyName = $familyName;

        return $this;
    }

    /**
     * Gets familyName.
     *
     * @return string
     */
    public function getFamilyName()
    {
        return $this->familyName;
    }

    /**
     * Sets gender.
     *
     * @param string $gender
     *
     * @return $this
     */
    public function setGender($gender)
    {
        $this->gender = $gender;

        return $this;
    }

    /**
     * Gets gender.
     *
     * @return string
     */
    public function getGender()
    {
        return $this->gender;
    }

    /**
     * Sets givenName.
     *
     * @param string $givenName
     *
     * @return $this
     */
    public function setGivenName($givenName)
    {
        $this->givenName = $givenName;

        return $this;
    }

    /**
     * Gets givenName.
     *
     * @return string
     */
    public function getGivenName()
    {
        return $this->givenName;
    }

    /**
     * Sets birthPlace.
     *
     * @return $this
     */
    public function setBirthPlace(?Place $birthPlace = null)
    {
        $this->birthPlace = $birthPlace;

        return $this;
    }

    /**
     * Gets birthPlace.
     *
     * @return Place|null
     */
    public function getBirthPlace()
    {
        return $this->birthPlace;
    }

    /**
     * Sets deathPlace.
     *
     * @return $this
     */
    public function setDeathPlace(?Place $deathPlace = null)
    {
        $this->deathPlace = $deathPlace;

        return $this;
    }

    /**
     * Gets deathPlace.
     *
     * @return Place|null
     */
    public function getDeathPlace()
    {
        return $this->deathPlace;
    }

    /**
     * Sets slug.
     *
     * @param string $slug
     *
     * @return $this
     */
    public function setSlug($slug)
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * Gets slug.
     *
     * @return string
     */
    public function getSlug()
    {
        return $this->slug;
    }

    /**
     * Gets Firstname Lastname or Lastname, Firstname depending on $givenNameFirst.
     *
     * @return string
     */
    public function getFullname($givenNameFirst = false)
    {
        $parts = [];
        foreach (['familyName', 'givenName'] as $key) {
            if (!empty($this->$key)) {
                $parts[] = $this->$key;
            }
        }

        if (empty($parts)) {
            return '';
        }

        return $givenNameFirst
            ? implode(' ', array_reverse($parts))
            : implode(', ', $parts);
    }

    /**
     * build <persName>.
     */
    public function teiSerializePersName()
    {
        $tag = '';

        $givenName = $this->getGivenName();
        if (!empty($givenName)) {
            // assume a structured name
            $nameParts = [
                sprintf(
                    '<%s>%s</%s>',
                    'forename',
                    self::xmlSpecialchars($givenName),
                    'forename'
                ),
            ];

            $familyName = $this->getFamilyName();
            if (!empty($familyName)) {
                $nameParts[] = sprintf(
                    '<%s>%s</%s>',
                    'surname',
                    self::xmlSpecialchars($familyName),
                    'surname'
                );
            }

            $tag = sprintf(
                '<persName>%s</persName>',
                join(' ', $nameParts)
            );
        }
        else {
            $fullname = $this->getFullname();
            if (!empty($fullname)) {
                $tag = sprintf('<persName>%s</persName>', $fullname);
            }
            else {
                $fullname = $this->getName();
                if (!empty($fullname)) {
                    $tag = sprintf('<persName>%s</persName>', $fullname);
                }
            }
        }

        return $tag;
    }

    #[Serializer\PreSerialize]
    public function onPreSerialize()
    {
        $name = $this->getName();

        if (empty($name)) {
            // set language independent default
            $this->setName($this->getFullName());
        }
    }
}

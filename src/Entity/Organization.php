<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

use JMS\Serializer\Annotation as Serializer;

/**
 * An organization such as a school, NGO, corporation, club, etc.
 *
 * @see http://schema.org/Organization Documentation on Schema.org
 */
#[Serializer\XmlRoot('Organization')]
#[Serializer\XmlNamespace(uri: 'http://www.w3.org/XML/1998/namespace', prefix: 'xml')]
class Organization
extends SchemaOrg
{
    /**
     * @var string The date that this organization was dissolved.
     */
    #[Serializer\XmlElement(cdata: false)]
    #[Serializer\Type('string')]
    protected $dissolutionDate;

    /**
     * @var string The date that this organization was founded.
     */
    #[Serializer\XmlElement(cdata: false)]
    #[Serializer\Type('string')]
    protected $foundingDate;

    /**
     * @var Place The place where the organization was founded.
     */
    #[Serializer\Type('string')]
    protected $foundingLocation;

    /**
     * @var Place The location where the organization is located.
     */
    #[Serializer\Type('string')]
    protected $location;

    /**
     * @var Organization The organization that preceded this on.
     */
    #[Serializer\Type('string')]
    protected $precedingOrganization;

    /**
     * @var Organization The organization that suceeded this on.
     */
    #[Serializer\Type('string')]
    protected $succeedingOrganization;

    /**
     * Sets dissolutionDate.
     *
     * @param string|null $dissolutionDate
     *
     * @return $this
     */
    public function setDissolutionDate($dissolutionDate = null)
    {
        $this->dissolutionDate = self::formatDateIncomplete($dissolutionDate);

        return $this;
    }

    /**
     * Gets dissolutionDate.
     *
     * @return string|null
     */
    public function getDissolutionDate()
    {
        return $this->dissolutionDate;
    }

    /**
     * Sets foundingDate.
     *
     * @param string|null $foundingDate
     *
     * @return $this
     */
    public function setFoundingDate($foundingDate = null)
    {
        $this->foundingDate = self::formatDateIncomplete($foundingDate);

        return $this;
    }

    /**
     * Gets foundingDate.
     *
     * @return string|null
     */
    public function getFoundingDate()
    {
        return $this->foundingDate;
    }

    /**
     * Sets foundingLocation.
     *
     * @param Place|null $foundingLocation
     *
     * @return $this
     */
    public function setFoundingLocation(?Place $foundingLocation = null)
    {
        $this->foundingLocation = $foundingLocation;

        return $this;
    }

    /**
     * Gets foundingLocation.
     *
     * @return Place|null
     */
    public function getFoundingLocation()
    {
        return $this->foundingLocation;
    }

    /**
     * Sets location.
     *
     * @param Place|null $location
     *
     * @return $this
     */
    public function setLocation(?Place $location = null)
    {
        $this->location = $location;

        return $this;
    }

    /**
     * Gets location.
     *
     * @return Place|null
     */
    public function getLocation()
    {
        return $this->location;
    }

    /**
     * Sets precedingOrganization.
     *
     * @param Organization|null $precedingOrganization
     *
     * @return $this
     */
    public function setPrecedingOrganization(?Organization $precedingOrganization = null)
    {
        $this->precedingOrganization = $precedingOrganization;

        return $this;
    }

    /**
     * Gets precedingOrganization.
     *
     * @return Organization|null
     */
    public function getPrecedingOrganization()
    {
        return $this->precedingOrganization;
    }

    /**
     * Gets succeedingOrganization.
     *
     * @return Organization
     */
    public function getSucceedingOrganization()
    {
        return $this->succeedingOrganization;
    }
}

<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

use JMS\Serializer\Annotation as Serializer;

/**
 * An organization such as a school, NGO, corporation, club, etc.
 *
 * @see http://schema.org/Organization Documentation on Schema.org
 *
 * @Serializer\XmlRoot("Organization")
 * @Serializer\XmlNamespace(uri="http://www.w3.org/XML/1998/namespace", prefix="xml")
 */
class Organization
extends SchemaOrg
{
    /**
     * @var string The date that this organization was dissolved.
     *
     * @Serializer\XmlElement(cdata=false)
     * @Serializer\Type("string")
     */
    protected $dissolutionDate;

    /**
     * @var string The date that this organization was founded.
     *
     * @Serializer\XmlElement(cdata=false)
     * @Serializer\Type("string")
     */
    protected $foundingDate;

    /**
     * @var Place The place where the Organization was founded.
     *
     * @Serializer\Type("string")
     */
    protected $foundingLocation;

    /**
     * @var Organization The organization that preceded this on.
     *
     * @Serializer\Type("string")
     */
    protected $precedingOrganization;

    /**
     * @var Organization The organization that suceeded this on.
     *
     * @Serializer\Type("string")
     */
    protected $succeedingOrganization;

    /**
     * Sets dissolutionDate.
     *
     * @param string $dissolutionDate
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
     * @return string
     */
    public function getDissolutionDate()
    {
        return $this->dissolutionDate;
    }

    /**
     * Sets foundingDate.
     *
     * @param string $foundingDate
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
     * @return string
     */
    public function getFoundingDate()
    {
        return $this->foundingDate;
    }

    /**
     * Sets foundingLocation.
     *
     * @param Place $foundingLocation
     *
     * @return $this
     */
    public function setFoundingLocation(Place $foundingLocation = null)
    {
        $this->foundingLocation = $foundingLocation;

        return $this;
    }

    /**
     * Gets foundingLocation.
     *
     * @return Place
     */
    public function getFoundingLocation()
    {
        return $this->foundingLocation;
    }

    /**
     * Sets precedingOrganization.
     *
     * @param Organization $precedingOrganization
     *
     * @return $this
     */
    public function setPrecedingOrganization(Organization $precedingOrganization = null)
    {
        $this->precedingOrganization = $precedingOrganization;

        return $this;
    }

    /**
     * Gets precedingOrganization.
     *
     * @return Organization
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

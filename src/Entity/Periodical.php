<?php

namespace App\Entity;

/**
 * Periodical.
 *
 * @see https://schema.org/Periodical
 */
class Periodical extends CreativeWork
{
    /**
     * @var string|null The International Standard Serial Number (ISSN) that identifies this serial publication
     */
    protected $issn;

    /**
     * Sets the ISSN of the serial publication.
     *
     * @param string|null $issn
     *
     * @return $this
     */
    public function setIssn($issn = null)
    {
        $this->issn = $issn;

        return $this;
    }

    /**
     * Gets the ISSN of the serial publication.
     *
     * @return string|null
     */
    public function getIssn()
    {
        return $this->issn;
    }
}

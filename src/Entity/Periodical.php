<?php

namespace App\Entity;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Periodical
 *
 * @see https://schema.org/Periodical
 *
 */
class Periodical
extends CreativeWork
{
    /**
     * @var string The International Standard Serial Number (ISSN) that identifies this serial publication
     */
    protected $issn;

    /**
     * Sets the ISSN of the serial publication.
     *
     * @param string $issn
     *
     * @return $this
     */
    public function setIssn($issn)
    {
        $this->issn = $issn;

        return $this;
    }

    /**
     * Gets the ISSN of the serial publication.
     *
     * @return string
     */
    public function getIssn()
    {
        return $this->issn;
    }
}

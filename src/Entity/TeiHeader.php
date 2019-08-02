<?php

namespace App\Entity;

use \FluidXml\FluidXml;
use \FluidXml\FluidNamespace;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entity to edit elements in teiHeader
 *
 * Currently very incomplete
 *
 */
class TeiHeader
implements \JsonSerializable
{
    protected $id;
    protected $title;
    protected $authors = [];
    protected $responsible = [];
    protected $note;
    protected $licence;
    protected $licenceTarget;
    protected $language;
    protected $shelfmark;
    protected $dateCreation;
    protected $classCodes = [];

    /**
     * Sets id.
     *
     * @param string $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Gets id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Sets title.
     *
     * @param string $title
     *
     * @return $this
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Gets title.
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Adds author
     */
    public function addAuthor($author)
    {
        $this->authors[] = $author;

        return $this;
    }

    /**
     * Gets authors
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    /**
     * Adds responsible
     */
    public function addResponsible($name, $role, $nameType = 'persName')
    {
        $this->responsible[] = [ 'role' => $role, $nameType => $name ];

        return $this;
    }

    /**
     * Gets responsible
     */
    public function getResponsible()
    {
        return $this->responsible;
    }

    /**
     * Sets note.
     *
     * @param string $note
     *
     * @return $this
     */
    public function setNote($note)
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Gets note.
     *
     * @return string
     */
    public function getNote()
    {
        return $this->note;
    }

    /**
     * Sets licence.
     *
     * @param string $licence
     *
     * @return $this
     */
    public function setLicence($licence)
    {
        $this->licence = $licence;

        return $this;
    }

    /**
     * Gets licence.
     *
     * @return string
     */
    public function getLicence()
    {
        return $this->licence;
    }

    /**
     * Sets licence target url.
     *
     * @param string $licenceTarget
     *
     * @return $this
     */
    public function setLicenceTarget($licenceTarget)
    {
        $this->licenceTarget = $licenceTarget;

        return $this;
    }

    /**
     * Gets licence target.
     *
     * @return string
     */
    public function getLicenceTarget()
    {
        return $this->licenceTarget;
    }

    /**
     * Sets language.
     *
     * @param string $language
     *
     * @return $this
     */
    public function setLanguage($language)
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Gets language.
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Sets creation date.
     *
     * @param string $dateCreation
     *
     * @return $this
     */
    public function setDateCreation($date)
    {
        $this->dateCreation = $date;

        return $this;
    }

    /**
     * Gets creation date.
     *
     * @return string
     */
    public function getDateCreation()
    {
        return $this->dateCreation;
    }

    /**
     * Sets shelfmark.
     *
     * @param string $shelfmark
     *
     * @return $this
     */
    public function setShelfmark($shelfmark)
    {
        $this->shelfmark = $shelfmark;

        return $this;
    }

    /**
     * Gets shelfmark.
     *
     * @return string
     */
    public function getShelfmark()
    {
        return $this->shelfmark;
    }

    /**
     * Adds classification code
     */
    public function addClassCode($scheme, $code)
    {
        if (!array_key_exists($scheme, $this->classCodes)) {
            $this->classCodes[$scheme] = [];
        }

        $this->classCodes[$scheme][] = $code;
    }

    /**
     * Gets classification codes
     */
    public function getClassCodes($scheme)
    {
        if (array_key_exists($scheme, $this->classCodes)) {
            return $this->classCodes[$scheme];
        }
    }

    /**
     * Sets genre.
     *
     * @param string $genre
     *
     * @return $this
     */
    public function setGenre($genre)
    {
        $this->addClassCode('#genre', $genre);

        return $this;
    }

    /**
     * Gets genre.
     *
     * @return string
     */
    public function getGenre()
    {
        $codes = $this->getClassCodes('#genre');
        if (!empty($codes)) {
            return $codes[0];
        }
    }

    /**
     * Sets setting.
     *
     * In TEI-Simpleprint, this might go into
     *   https://tei-c.org/release/doc/tei-p5-exemplars/html/tei_simplePrint.doc.html#settingDesc
     * Since this is lacking in DTAbf, we use
     *
     * @param string $setting
     *
     * @return $this
     */
    public function setSettingDate($settingDate)
    {
        $this->addClassCode('http://purl.org/dc/elements/1.1/coverage', $settingDate);

        return $this;
    }

    /**
     * Gets settting.
     *
     * @return string
     */
    public function getSettingDate()
    {
        $codes = $this->getClassCodes('http://purl.org/dc/elements/1.1/coverage');

        if (!empty($codes)) {
            return $codes[0];
        }
    }

    public function jsonSerialize()
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'authors' => $this->getAuthors(),
            'responsible' => $this->getResponsible(),
            'dateCreation' => $this->getDateCreation(),
            'language' => $this->getLanguage(),
            'genre' => $this->getGenre(),
            'settingDate' => $this->getSettingDate(),
            'note' => $this->getNote(),
            'shelfmark' => $this->getShelfmark(),
            'licence' => $this->getLicence(),
            'licenceTarget' => $this->getLicenceTarget(),
            'lcsh' => $this->getClassCodes('#lcsh'),
        ];
    }
}

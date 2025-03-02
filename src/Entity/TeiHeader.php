<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use FS\SolrBundle\Doctrine\Annotation as Solr;

/**
 * Entity to edit elements in teiHeader.
 *
 * Currently very incomplete
 */
class TeiHeader implements \JsonSerializable
{
    /**
     * @var string
     *
     * @Solr\Id
     */
    #[Solr\Id]
    protected $id;

    /**
     * @var string the title
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $title;

    /**
     * @var ArrayCollection<int, Person> the author of this content
     *
     * @Solr\Field(type="strings", getter="getFullname")
     */
    #[Solr\Field(type: 'strings', getter: 'getFullname')]
    protected $authors;

    protected $editors = [];
    protected $translator;
    protected $responsible = [];

    /**
     * @var string the licence text
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $licence;

    /**
     * @var string the licence URL
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $licenceTarget;

    /**
     * @var string The source description
     *
     * @Solr\Field(type="text")
     */
    #[Solr\Field(type: 'text')]
    protected $note;

    /**
     * @var string the bibliographic citation
     *
     * @Solr\Field(type="text")
     */
    #[Solr\Field(type: 'text')]
    protected $sourceDescBibl;

    /**
     * @var string the language code (deu or eng)
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $language;

    /**
     * @var string the language code of the original
     */
    private $translatedFrom;

    /**
     * @var string the shelfmark
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $shelfmark;

    /**
     * @var string the indexing date
     *
     * @Solr\Field(type="date")
     */
    #[Solr\Field(type: 'date')]
    protected $dateIndexed;

    /**
     * @var string the creation date
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    protected $dateCreated;

    protected $idno = [];
    protected $classCodes = [];

    /* we duplicate properties from $idno / $classCodes for Solr-annotation */

    /**
     * @var string the slug
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    private $slug;

    /**
     * @var string the genre (introduction|document|image)
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    private $genre;

    protected static function normalizeWhitespace($tei)
    {
        if (is_null($tei)) {
            return $tei;
        }

        return preg_replace('/\R+/', ' ', $tei); // get rid of newlines added e.g. through pretty-printing
    }

    public static function fromXml($fname, $propertiesAsXml = true)
    {
        $teiHelper = new \App\Utils\TeiHelper();
        $article = $teiHelper->analyzeDocument($fname, $propertiesAsXml);

        return self::entityFromObject($article);
    }

    public static function fromXmlString($content, $propertiesAsXml = true)
    {
        $teiHelper = new \App\Utils\TeiHelper();
        $article = $teiHelper->analyzeDocumentString($content, $propertiesAsXml);

        return self::entityFromObject($article);
    }

    private static function entityFromObject($article)
    {
        if (false === $article) {
            return null;
        }

        $entity = new static(); // so we can override in TeiFull, see https://stackoverflow.com/a/10617254

        return self::hydrateEntity($entity, $article);
    }

    protected static function hydrateEntity($entity, $article)
    {
        if (property_exists($article, 'uid')) {
            $entity->setId($article->uid);
        }

        if (isset($article->name)) {
            $entity->setTitle($article->name);
        }

        $entity->setLanguage($article->language);

        foreach (['author', 'editor'] as $key) {
            if (!empty($article->$key)) {
                $method = 'add' . ucfirst($key);

                foreach ($article->$key as $related) {
                    $entity->$method($related);
                }
            }
        }

        $entity->setTranslator($article->translator);

        if (property_exists($article, 'translatedFrom')) {
            $entity->setTranslatedFrom($article->translatedFrom);
        }

        if (property_exists($article, 'slug')) {
            $entity->setDtaDirName($article->slug);
        }

        $entity->setGenre($article->genre);
        if (property_exists($article, 'shelfmark')) {
            $entity->setShelfmark($article->shelfmark);
        }

        if (property_exists($article, 'doi')) {
            $entity->setDoi($article->doi);
        }

        if (property_exists($article, 'abstract')) {
            $entity->setNote($article->abstract);
        }

        if (property_exists($article, 'bibl')) {
            $entity->setSourceDescBibl($article->bibl);
        }

        if (property_exists($article, 'dateCreated')) {
            $entity->setDateCreated($article->dateCreated);
        }

        $entity->setTerms($article->terms);
        $entity->setMeta($article->meta);
        $entity->setLicenceTarget($article->licence);
        $entity->setLicence($article->rights);

        if (method_exists($entity, 'setBody')) {
            $entity->setBody($article->articleBody);
        }

        return $entity;
    }

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
    public function getId($removePrefix = false)
    {
        if ($removePrefix && !empty($this->id)) {
            $parts = explode(':', $this->id, 2);

            return count($parts) > 1 ? $parts[1] : $parts[0];
        }

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
        $this->title = self::normalizeWhitespace($title);

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
     * Adds author.
     */
    public function addAuthor($author)
    {
        if (is_null($this->authors)) {
            $this->authors = new ArrayCollection();
        }

        $this->authors[] = $author;

        return $this;
    }

    /**
     * Gets authors.
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    /**
     * Adds editor.
     */
    public function addEditor($editor)
    {
        $this->editors[] = $editor;

        return $this;
    }

    /**
     * Gets editors.
     */
    public function getEditors()
    {
        return $this->editors;
    }

    /**
     * Sets translator.
     *
     * @param string $translator
     *
     * @return $this
     */
    public function setTranslator($translator)
    {
        $this->translator = self::normalizeWhitespace($translator);

        return $this;
    }

    /**
     * Gets translator.
     *
     * @return string
     */
    public function getTranslator()
    {
        return $this->translator;
    }

    /**
     * Adds responsible.
     */
    public function addResponsible($name, $role, $nameType = 'persName')
    {
        $this->responsible[] = ['role' => $role, $nameType => $name];

        return $this;
    }

    /**
     * Gets responsible.
     */
    public function getResponsible()
    {
        return $this->responsible;
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
     * Sets sourceDescBibl.
     *
     * @param string $sourceDescBibl
     *
     * @return $this
     */
    public function setSourceDescBibl($sourceDescBibl)
    {
        $this->sourceDescBibl = $sourceDescBibl;

        return $this;
    }

    /**
     * Gets sourceDescBibl.
     *
     * @return string
     */
    public function getSourceDescBibl()
    {
        return $this->sourceDescBibl;
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
     * Sets translated from.
     *
     * @param string $language
     *
     * @return $this
     */
    public function setTranslatedFrom($language)
    {
        if (!empty($language)) {
            $this->addClassCode('#translated-from', $language);
        }

        // for solr-annotation
        $this->translatedFrom = $this->getTranslatedFrom();

        return $this;
    }

    /**
     * Gets translated from.
     *
     * @return string
     */
    public function getTranslatedFrom()
    {
        $codes = $this->getClassCodes('#translated-from');
        if (!empty($codes)) {
            return $codes[0];
        }
    }

    /**
     * Sets indexing date.
     *
     * @param string $dateIndexed
     *
     * @return $this
     */
    private function setDateIndexed($dateIndexed)
    {
        /*
         * This following assignment only works if
         * date_indexed_dt is defined as solr.DateRangeField
         *
         *   <fieldType name="pdaterange" class="solr.DateRangeField" />
         *   <field name="date_indexed_dt" type="pdaterange" indexed="true" stored="true" multiValued="false"/>
         *
         * By default, the _dt suffix generates a solr.DatePointField which
         * requires YYYY-MM-DDTHH:mm:ssZ and therefore leads to errors like
         *
         * Error adding field 'date_indexed_dt'='1800'
         *  msg=Invalid Date String:'1800'"
         */

        // remove circa
        $dateIndexed = preg_replace('/(circa|before|after) /', '', $dateIndexed);

        // centuries to range
        if (preg_match('/^(\d+)th century$/', $dateIndexed, $matches)) {
            $dateIndexed = sprintf(
                '[%d01 TO %d00]',
                intval($matches[1]) - 1,
                intval($matches[1])
            );
        }

        // decades like 1950s to range
        if (preg_match('/^(\d+)s$/', $dateIndexed, $matches)) {
            $dateIndexed = sprintf(
                '[%d TO %d]',
                intval($matches[1]),
                intval($matches[1]) + 9
            );
        }

        // year range like 1754 – 1761 to range [1754 TO 1761]
        if (preg_match('/^(\d{4}) – (\d{4})$/', $dateIndexed, $matches)) {
            $dateIndexed = sprintf(
                '[%d TO %d]',
                intval($matches[1]),
                intval($matches[2])
            );
        }

        $this->dateIndexed = $dateIndexed;

        return $this;
    }

    /**
     * Sets creation date.
     *
     * @param string $dateCreated
     *
     * @return $this
     */
    public function setDateCreated($dateCreated)
    {
        $this->dateCreated = $dateCreated;

        $this->setDateIndexed($dateCreated);

        return $this;
    }

    /**
     * Gets creation date.
     *
     * @return string
     */
    public function getDateCreated()
    {
        return $this->dateCreated;
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
     * Adds classification code.
     */
    public function addClassCode($scheme, $code)
    {
        if (!array_key_exists($scheme, $this->classCodes)) {
            $this->classCodes[$scheme] = [];
        }

        $this->classCodes[$scheme][] = $code;
    }

    /**
     * Clears classification codes with $scheme.
     */
    public function clearClassCodes($scheme)
    {
        if (array_key_exists($scheme, $this->classCodes)) {
            unset($this->classCodes[$scheme]);
        }

        return $this;
    }

    /**
     * Gets classification codes.
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
        $this->clearClassCodes('#genre');
        $this->addClassCode('#genre', $genre);

        // for solr-annotation
        $this->genre = $this->getGenre();

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
     * Add term.
     *
     * @param string $term
     *
     * @return $this
     */
    public function addTerm($term)
    {
        $this->addClassCode('#term', $term);

        return $this;
    }

    /**
     * Sets terms.
     *
     * @param array $terms
     *
     * @return $this
     */
    public function setTerms($terms)
    {
        $this->clearClassCodes('#term');

        foreach ($terms as $term) {
            $this->addTerm($term);
        }

        return $this;
    }

    /**
     * Gets terms.
     *
     * @return array
     */
    public function getTerms()
    {
        return $this->getClassCodes('#term');
    }

    /**
     * Add meta.
     *
     * @param string $meta
     *
     * @return $this
     */
    public function addMeta($meta)
    {
        $this->addClassCode('#meta', $meta);

        return $this;
    }

    /**
     * Sets meta.
     *
     * @param array $metaTags
     *
     * @return $this
     */
    public function setMeta($metaTags)
    {
        $this->clearClassCodes('#meta');

        foreach ($metaTags as $meta) {
            $this->addMeta($meta);
        }

        return $this;
    }

    /**
     * Gets meta.
     *
     * @return array
     */
    public function getMeta()
    {
        return $this->getClassCodes('#meta');
    }

    /**
     * Sets idno.
     *
     * @param string $idno
     * @param string $type
     *
     * @return $this
     */
    public function setIdno($idno, $type)
    {
        $this->idno[$type] = $idno;

        return $this;
    }

    /**
     * Gets idno.
     *
     * @return string
     */
    public function getIdno($type)
    {
        if (array_key_exists($type, $this->idno)) {
            return $this->idno[$type];
        }
    }

    public function setDtaDirName($DTADirName)
    {
        $this->slug = $DTADirName;

        return $this->setIdno($DTADirName, 'DTADirName');
    }

    public function getDtaDirName()
    {
        return $this->getIdno('DTADirName');
    }

    /**
     * Sets doi.
     *
     * @param string $doi
     *
     * @return $this
     */
    public function setDoi($doi)
    {
        return $this->setIdno('DOI', $doi);
    }

    /**
     * Gets doi.
     *
     * @return string
     */
    public function getDoi()
    {
        return $this->getIdno('DOI');
    }

    /**
     * Sets temporal coverage.
     *
     * In TEI-Simpleprint, this might go into
     *   https://tei-c.org/release/doc/tei-p5-exemplars/html/tei_simplePrint.doc.html#settingDesc
     * Since this is lacking in DTAbf, we use a class code
     *
     * @param string $temporalCoverage
     *
     * @return $this
     */
    public function setTemporalCoverage($temporalCoverage)
    {
        $this->addClassCode('http://purl.org/dc/elements/1.1/coverage', $temporalCoverage);

        return $this;
    }

    /**
     * Gets settting.
     *
     * @return string
     */
    public function getTemporalCoverage()
    {
        $codes = $this->getClassCodes('http://purl.org/dc/elements/1.1/coverage');

        if (!empty($codes)) {
            return $codes[0];
        }
    }

    public function jsonSerialize(): mixed
    {
        return [
            'id' => $this->getId(),
            'title' => $this->getTitle(),
            'authors' => $this->getAuthors(),
            'translator' => $this->getTranslator(),
            'responsible' => $this->getResponsible(),
            'dateCreated' => $this->getDateCreated(),
            'temporalCoverage' => $this->getTemporalCoverage(),
            'note' => $this->getNote(),
            'sourceDescBibl' => $this->getSourceDescBibl(),
            'language' => $this->getLanguage(),
            'genre' => $this->getGenre(),
            'shelfmark' => $this->getShelfmark(),
            'slug' => $this->getDtaDirName(),
            'licence' => $this->getLicenceTarget(),
            'rights' => $this->getLicence(),
            'doi' => $this->getDoi(),
            'terms' => $this->getTerms(),
            'meta' => $this->getMeta(),
            'lcsh' => $this->getClassCodes('#lcsh'),
        ];
    }
}

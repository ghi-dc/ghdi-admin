<?php

namespace App\Entity;

use FS\SolrBundle\Doctrine\Annotation as Solr;

/**
 * Entity to index TeiHeader and text.
 *
 * @Solr\Document(indexHandler="indexHandler", repository="App\Search\Repository")
 *
 * @Solr\SynchronizationFilter(callback="shouldBeIndexed")
 */
#[Solr\Document(indexHandler: 'indexHandler', repository: "App\Search\Repository")]
#[Solr\SynchronizationFilter(callback: 'shouldBeIndexed')]
class TeiFull extends TeiHeader
{
    /**
     * @var string the textual content
     *
     * @Solr\Field(type="text")
     */
    #[Solr\Field(type: 'text')]
    protected $body;

    /**
     * @var array additional tags for solr indexing
     *
     * @Solr\Field(type="strings", nestedClass="App\Entity\Tag")
     */
    #[Solr\Field(type: 'strings', nestedClass: "App\Entity\Tag")]
    protected $tags = [];

    /**
     * @var string the Volume id for facetting
     *
     * @Solr\Field(type="string")
     */
    #[Solr\Field(type: 'string')]
    private $volumeId;

    /**
     * Sets body.
     *
     * @param string $body
     *
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * Gets body.
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    public function addTag(Tag $tag)
    {
        $this->tags[] = $tag;
    }

    public function getTags()
    {
        return $this->tags;
    }

    public function getTagsByType($type)
    {
        return array_filter(
            $this->tags,
            function ($tag) use ($type) { return $type == $tag->getType(); }
        );
    }

    public function getVolumeId()
    {
        if ('volume' == $this->getGenre()) {
            return $this->volumeId = $this->getId(true);
        }

        $parts = explode('/', $this->getShelfmark());
        if (count($parts) > 1) {
            [$order, $volumeId] = explode(':', $parts[1], 2);

            return $this->volumeId = $volumeId;
        }
    }

    public function setShelfmark($shelfmark)
    {
        $ret = parent::setShelfmark($shelfmark);

        $this->getVolumeId(); // in order to set $this->volumeId for solr

        return $ret;
    }

    public function jsonSerialize(): mixed
    {
        $ret = parent::jsonSerialize();

        $ret['body'] = $this->getBody();

        return $ret;
    }

    // solr-stuff

    /**
     * Solr-core depends on article-language.
     *
     * @return string
     */
    public function indexHandler()
    {
        if (!empty($this->language)) {
            return 'core_' . \App\Utils\Iso639::code3To1($this->language);
        }

        // fallback
        return 'core_de';
    }

    /**
     * TODO.
     *
     * @return bool
     */
    public function shouldBeIndexed()
    {
        return true; // TODO: explicit publishing needed
    }
}

<?php
namespace App\Utils;

use FluentDOM\DOM\Document;
use FluentDOM\Loader\Libxml\Errors;
use FluentDOM\Loader\Options;

/**
 * Override the default Xml-loader in order to preserveWhitespace
 */
class XmlLoader
extends \FluentDOM\Loader\Xml
{
    /**
     * @return string[]
     */
    public function getSupported(): array {
        return ['text/xml-whitespace-preserving'];
    }

    /**
     * Copied from \FluentDOM\Loader\Xml since it calls a private method
     *
     * @see Loadable::load
     * @param string $source
     * @param string $contentType
     * @param array|\Traversable|Options $options
     * @return Document|Result|NULL
     * @throws \FluentDOM\Exceptions\InvalidSource\TypeString
     * @throws \FluentDOM\Exceptions\InvalidSource\TypeFile
     */
    public function load($source, string $contentType, $options = []) {
        if ($this->supports($contentType)) {
            return $this->loadXmlDocument($source, $options);
        }
        return NULL;
    }

    /**
     * @param string $source
     * @param array|\Traversable|Options $options
     * @return Document
     * @throws \FluentDOM\Exceptions\InvalidSource\TypeString
     * @throws \FluentDOM\Exceptions\InvalidSource\TypeFile
     */
    private function loadXmlDocument(string $source, $options): Document {
        return (new Errors())->capture(
            function () use ($source, $options) {
                $document = new Document();
                $document->preserveWhiteSpace = true; // this is the setting we override
                $settings = $this->getOptions($options);
                $settings->isAllowed($sourceType = $settings->getSourceType($source));
                switch ($sourceType) {
                case Options::IS_FILE :
                    $document->load($source, $settings[Options::LIBXML_OPTIONS]);
                    break;
                case Options::IS_STRING :
                default :
                    $document->loadXML($source, $settings[Options::LIBXML_OPTIONS]);
                }
                return $document;
            }
        );
    }
}

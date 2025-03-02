<?php

/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component.
 */

namespace App\Utils;

class XslConverter extends DocumentConverter
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);
    }

    protected function cleanUp($ret)
    {
        if (method_exists($ret, 'cleanUp')) {
            $ret->cleanUp();
        }
    }

    /**
     * Convert documents between two formats.
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document|false
     */
    public function convert(Document $doc)
    {
        $res = null;

        if (isset($this->options['adapter'])) {
            $adapter = $this->options['adapter'];

            $fnameInput = $this->saveToTmp($doc);
            $res = $adapter->transformToXml($fnameInput, $this->getOption('xsl'), $this->getOption('params'));

            unlink($fnameInput);
        }
        else {
            // native XsltProcessor doesn't handle XSLT 2.0
            // load xsl
            libxml_use_internal_errors(true);
            $xsl = new \DOMDocument('1.0', 'UTF-8');
            $success = $xsl->load($this->options['xsl']);
            if (!$success) {
                $this->errors = libxml_get_errors();
                libxml_use_internal_errors(false);

                return false;
            }

            // Create the XSLT processor
            $proc = new \XSLTProcessor();
            $proc->importStylesheet($xsl);

            $dom = new \DOMDocument('1.0', 'UTF-8');
            $success = $dom->load($doc->saveString());

            // Transform
            $res = $proc->transformToDoc($dom);
            if (false === $res) {
                $this->errors = libxml_get_errors();
                libxml_use_internal_errors(false);

                return false;
            }

            libxml_use_internal_errors(false);

            // to string conversion, maybe just set dom
            $res = $res->saveXML();
        }

        $ret = array_key_exists('target', $this->options)
            ? $this->options['target']
            : new XmlDocument();

        $ret->loadString($res);

        $this->cleanUp($ret);

        return $ret;
    }
}

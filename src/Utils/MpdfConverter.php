<?php

/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component.
 */

namespace App\Utils;

class MpdfConverter extends DocumentConverter
{
    public function __construct(array $options = [])
    {
        parent::__construct($options);
    }

    /**
     * Convert documents between two formats.
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document
     */
    public function convert(Document $doc)
    {
        // mpdf
        $pdfGenerator = new PdfGenerator(array_key_exists('config', $this->options) ? $this->options['config'] : []);

        if (array_key_exists('imageVars', $this->options)) {
            foreach ($this->options['imageVars'] as $key => $val) {
                $pdfGenerator->imageVars[$key] = $val;
            }
        }

        $html = (string) $doc;

        $pdfGenerator->writeHTML($html);

        $ret = new BinaryDocument();
        $ret->loadString(@$pdfGenerator->output(null, \Mpdf\Output\Destination::STRING_RETURN));

        return $ret;
    }
}

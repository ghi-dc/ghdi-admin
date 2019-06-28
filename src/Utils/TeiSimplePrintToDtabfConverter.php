<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

class TeiSimplePrintToDtabfConverter
extends DocumentConverter
{
    protected $options;
    protected $errors;

    /**
     * Convert documents between two formats
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document
     */
    public function convert(Document $doc)
    {
        $tei = $doc->saveString();

        // for <hi> - should be solved in xml-processing or tei
        $teiDtabf = str_replace(
            [ 'simple:bold', 'simple:italic', 'simple:strikethrough', 'simple:superscript', 'simple:subscript', 'simple:smallcaps', 'simple:letterspace', 'simple:underline' ],
            [ '#b', '#i', '#s', '#sup', '#sub', '#k', '#g', '#u' ],
            $tei);

        // don't allow role="c1" on ref
        $teiDtabf = preg_replace('/(<ref\s+[^>]*)role="[^"]*"([^>]*>)/', '\1\2', $teiDtabf);

        // remove empty headers or paragraphs - TODO: switch to  https://stackoverflow.com/a/8603358
        while (preg_match($reEmpty = '/<(div|head|p|hi)[^>]*>(\s*)<\/\1>/', $teiDtabf, $matches)) {
            $teiDtabf = preg_replace($reEmpty, $matches[2], $teiDtabf);
        }

        $resDoc = new TeiDocument();
        $resDoc->loadString($teiDtabf);

        return $resDoc;
    }
}
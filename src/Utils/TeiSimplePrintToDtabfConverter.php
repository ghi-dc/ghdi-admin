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
    // TODO: share with XmlDocument - maybe through static method
    protected function unwrapChildren($node)
    {
        $parent = $node->parentNode;

        while ($node->firstChild) {
           $parent->appendChild($node->firstChild);
        }

        $parent->removeChild($node);
    }

    /**
     * Convert documents between two formats
     *
     * Convert documents of the given type to the requested type.
     *
     * @return TeiDtabfDocument
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

        // remove empty headers or paragraphs
        // TODO: switch to https://stackoverflow.com/a/8603358
        while (preg_match($reEmpty = '/<(div|head|p|hi)[^>]*>(\s*)<\/\1>/', $teiDtabf, $matches)) {
            $teiDtabf = preg_replace($reEmpty, $matches[2], $teiDtabf);
        }

        $teiDocument = new TeiDtabfDocument($this->options);
        $teiDocument->loadString($teiDtabf);
        $xml = $teiDocument->getDom();

        // setting xml-model at the beginning
        $pi = $xml->createProcessingInstruction('xml-model',
                                                'href="http://www.deutschestextarchiv.de/basisformat.rng" type="application/xml" schematypens="http://relaxng.org/ns/structure/1.0"');
        $xml->insertBefore($pi, $xml->documentElement);

        // for oxygen framework, set xml:id="dtabf" on root TEI-element
        if (!$xml->documentElement->hasAttribute('xml:id')) {
            $xml->documentElement->setAttribute('xml:id', 'dtabf');
        }

        $header = $xml('/tei:TEI/tei:teiHeader')[0];
        // if we have only <title> and not <title type="main">, add this attribute
        $hasTitleAttrMain = $header('count(./tei:fileDesc/tei:titleStmt/tei:title[@type="main"]) > 0');
        if (!$hasTitleAttrMain) {
            $result = $header('./tei:fileDesc/tei:titleStmt/tei:title[not(@type)]');
            if ($result->length > 0) {
                $result[0]->setAttribute('type', 'main');
            }
        }

        // a non-empty publicationStmt is required
        $hasPublicationStmtChild = $header('count(./tei:fileDesc/tei:publicationStmt/*) > 0');
        if (!$hasPublicationStmtChild) {
            $result = $header('./tei:fileDesc/tei:publicationStmt');
            if ($result->length > 0) {
                $result[0]->appendElement('publisher', '');
            }
        }

        $hasProfileDesc = $header('count(./tei:fileDesc/tei:profileDesc) > 0');
        $profileDesc = $hasProfileDesc
            ? $header('./tei:fileDesc/tei:profileDesc')[0]
            : null;

        if (!empty($this->options['language'])) {
            if (is_null($profileDesc)) {
                $profileDesc = $header->appendElement('profileDesc');
            }

            // set langUsage
            $langUsage = $profileDesc->appendElement('langUsage');

            $languageName = \App\Utils\Iso639::nameByCode3($this->options['language']);
            $langUsage->appendElement('language', $languageName)
                ->setAttribute('ident', $this->options['language']);
        }

        if (!empty($this->options['genre'])) {
            if (is_null($profileDesc)) {
                $profileDesc = $header->appendElement('profileDesc');
            }

            $textClass = $profileDesc->appendElement('textClass')
                ->appendElement('classCode', $this->options['genre'])
                ->setAttribute('scheme', 'http://germanhistorydocs.org/docs/#genre');
        }

        if (!empty($this->options['authors'])) {
            foreach ($this->options['authors'] as $person) {
                $teiDocument->addAuthor($person);
            }
        }

        // set place="foot" as default in body notes
        foreach ($xml('/tei:TEI/tei:text//tei:note') as $note) {
            if (empty($note->getAttributeNode('place'))) {
                $note->setAttribute('place', 'foot');
            }

            // unwrap <p> since we can't set display-inline in mpdf
            $paras = $note('./tei:p');
            for ($i = 0; $i < count($paras); $i++) {
                if ($i > 0) {
                    // add a line break before any following p
                    $paras[$i]->parentNode->appendElement('lb');
                }

                $this->unwrapChildren($paras[$i]);
            }
        }

        $teiDocument->prettify();

        return $teiDocument;
    }
}

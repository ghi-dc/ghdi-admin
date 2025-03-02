<?php

/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 * TODO: Build a separate Component.
 */

namespace App\Utils;

class TeiDtabfDocument extends TeiDocument
{
    public function addAuthor(\App\Entity\Person $person)
    {
        $givenName = $person->getGivenName();
        if (!empty($givenName)) {
            // assume a structured name
            $nameParts = [
                sprintf(
                    '<%s>%s</%s>',
                    'forename',
                    $this->xmlSpecialchars($givenName),
                    'forename'
                ),
            ];

            $familyName = $person->getFamilyName();
            if (!empty($familyName)) {
                $nameParts[] = sprintf(
                    '<%s>%s</%s>',
                    'surname',
                    $this->xmlSpecialchars($familyName),
                    'surname'
                );
            }

            $name = sprintf(
                '<persName>%s</persName>',
                join(' ', $nameParts)
            );
        }
        else {
            $fullname = $person->getFullname();
            if (!empty($fullname)) {
                $name = sprintf('<persName>%s</persName>', $fullname);
            }
            else {
                $fullname = $person->getName();
                if (!empty($fullname)) {
                    $name = sprintf('<persName>%s</persName>', $fullname);
                }
            }
        }

        if (!empty($name)) {
            $fragment = $this->dom->createDocumentFragment();
            $fragment->appendXML('<author>' . $name . '</author>');

            $xpath = $this->getXPath();
            $titleStmt = $xpath->evaluate('//tei:teiHeader/tei:fileDesc/tei:titleStmt');
            if ($titleStmt->length > 0) {
                $titleStmt->item(0)->append($fragment);
            }
        }
    }
}

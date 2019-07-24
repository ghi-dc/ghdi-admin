<?php
/**
 * Mix-in to deal with our specific formatting conventions
 */

namespace App\Utils;

trait TeiFromWordCleaner
{
    public function cleanUp()
    {
        parent::cleanUp();

        if (is_null($this->dom)) {
            return;
        }

        // check if last paragraph starts with Quelle:
        $this->evaluateXpath('(//tei:body//tei:p)[last()]', function ($nodes) {
            if (1 == $nodes->length) {
                $pLast = $nodes->item(0);
                if (preg_match('/^Quelle:/', $pLast->textContent)) {
                    $this->moveCitationToSourceDesc($pLast);
                }
            }
        });

        // pandoc doesn't convert underline to tei, so we use strikethrough instead to mark letterspace
        $this->evaluateXpath('(//tei:body//tei:hi[@rendition="simple:strikethrough"])', function ($nodes) {
            foreach ($nodes as $node) {
                $node->setAttribute('rendition', 'simple:letterspace');
            }
        });


        // change <div type="levelN"> to <div n="N" >
        $this->evaluateXpath('//tei:body//tei:div[@type]', function ($nodes) {
            foreach ($nodes as $node) {
                if (preg_match('/^level(\d+)$/', $node->getAttribute('type'), $matches)) {
                    $node->setAttribute('n', $matches[1]);
                    $node->removeAttribute('type');
                }
            }
        });

        // we now check for a single n=1 and pick up document title and source-desc
        $xpath = $this->getXPath();
        $nodes = $xpath->evaluate("//tei:body/tei:div[@n='1']");
        if (1 == $nodes->length) {
            $mainDiv = $nodes->item(0);

            // check if it starts with a <head>
            $firstChild = $xpath->evaluate("//tei:body/tei:div[@n='1']/*[1]");
            if (1 == $firstChild->length) {
                $firstChild = $firstChild->item(0);
                if ('head' == $firstChild->nodeName) {
                    $this->moveHeadToHeader($firstChild);
                }

                // move following <p> to notesStmt/note[type="remarkDocument"]
                $note = null;
                while ($node = $mainDiv->firstChild) {
                    if ($node instanceof \DOMText) {
                        if ('' === trim($node->textContent)) {
                            // empty, we can safely delete
                            $mainDiv->removeChild($node);

                            continue;
                        }
                    }

                    if ('p' == $node->nodeName) {
                        if (is_null($note)) {
                            $res = $xpath->evaluate($path = '//tei:teiHeader/tei:fileDesc/tei:notesStmt/tei:note');

                            $append = [];
                            while (0 == $res->length) {
                                $last = basename($path);
                                $path = dirname($path);
                                $append[] = $last;
                                $res = $xpath->evaluate($path);
                            }

                            $parent = $res->item(0);

                            foreach (array_reverse($append) as $newNodeName) {
                                list($ns, $localName) = explode(':', $newNodeName, 2);
                                $newNode = $this->dom->createElement($localName);

                                if ('note' == $localName) {
                                    $newNode->setAttribute('type', 'remarkDocument');
                                }

                                if ('notesStmt' == $localName) {
                                    // must be added before sourceDesc
                                    $sourceDesc = $xpath->evaluate('//tei:teiHeader/tei:fileDesc/tei:sourceDesc');
                                    if (0 == $sourceDesc->length) {
                                        die('TODO: add a sourceDesc');
                                    }

                                    $sourceDesc = $sourceDesc->item(0);
                                    $sourceDesc->parentNode->insertBefore($newNode, $sourceDesc);
                                }
                                else {
                                    $parent->appendChild($newNode);
                                }

                                $parent = $newNode;
                            }

                            $note = $parent;
                        }

                        $note->appendChild($node);

                        continue;
                    }

                    if ('div' == $node->nodeName) {
                        // check if we get the quellentext
                        if ($node->hasAttribute('xml:id')
                            && in_array($node->attributes['xml:id']->textContent, [ 'quellentext' ]))
                        {
                            // check if it starts with a <head>
                            $firstChild = $xpath->evaluate("./*[1]", $node);
                            if (1 == $firstChild->length) {
                                $firstChild = $firstChild->item(0);
                                if ('head' == $firstChild->nodeName) {
                                    if ('QUELLENTEXT' == $firstChild->textContent) {
                                        $node->removeChild($firstChild);
                                    }
                                }
                            }

                            $this->unwrapChildren($node);
                        }
                    }

                    // we are done
                    break;
                }

            }

            // remove it
            $this->unwrapChildren($mainDiv);
        }

        $this->prettify();
    }

    protected function moveHeadToHeader($headNode)
    {
        $xpath = $this->getXPath();
        $title = $xpath->evaluate('//tei:teiHeader/tei:fileDesc/tei:titleStmt/tei:title');
        if (0 == $title->length) {
            die('TODO: add a title');
        }
        $title = $title->item(0);

        // TODO: maybe split creator and title
        // TODO: maybe extract year
        while ($headNode->firstChild) {
           $title->appendChild($headNode->firstChild);
        }

        $headNode->parentNode->removeChild($headNode);
    }

    protected function moveCitationToSourceDesc($pNode)
    {
        $xpath = $this->getXPath();
        $sourceDesc = $xpath->evaluate('//tei:teiHeader/tei:fileDesc/tei:sourceDesc');
        if (0 == $sourceDesc->length) {
            die('TODO: add a sourceDesc');
        }

        $sourceDesc = $sourceDesc->item(0);

        $bibl = $this->dom->createElementNS('http://www.tei-c.org/ns/1.0', 'bibl');
        // foreach doesn't work - https://dzone.com/articles/renaming-domnode-php
        while ($pNode->firstChild) {
           $bibl->appendChild($pNode->firstChild);
        }

        $sourceDesc->appendChild($bibl);

        // if there is a <p>Produced by pandoc.</p> in sourceDesc, then remove
        $sourceDescP = $xpath->evaluate('//tei:teiHeader/tei:fileDesc/tei:sourceDesc/tei:p');
        foreach ($sourceDescP as $p) {
            if (preg_match('/^\s*Produced by pandoc\.\s*$/s', $p->textContent)) {
                $p->parentNode->removeChild($p);
            }
        }

        $pNode->parentNode->removeChild($pNode);
    }
}

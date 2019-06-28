<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

class TeiDocument
extends XmlDocument
{
    protected function registerDefaultXpathNamespaces($xpath)
    {
        parent::registerDefaultXpathNamespaces($xpath);
        
        $xpath->registerNamespace('tei', 'http://www.tei-c.org/ns/1.0');
    }
        
    /* see https://www.php.net/manual/en/domtext.splittext.php */
    protected function charOffset($string, $byte_offset, $encoding = 'utf-8')
    {
        $substr = substr($string, 0, $byte_offset);
        return mb_strlen($substr, $encoding ?: mb_internal_encoding());
    }
    
    public function cleanUp()
    {
        // remove empty head / p / div       
        do {
            $found = false;

            $xpath = $this->getXPath();
            $nodes = $xpath->query('//tei:body//*[not(child::*) and not(normalize-space())]');
            foreach ($nodes as $node) {
                if (in_array($node->nodeName, [ 'div', 'head', 'p' ])) {
                    $found = true;
                    $parent = $node->parentNode;
                    $parent->removeChild($node);
                    $this->dom->normalize();
                }
            }
        } while ($found);
                
        // replace [...] with <gap />
        $count = 0;
        do {
            $found = false; // logic for multiple ommissions in one text span is currently  - just redo the whole doc
            
            $xpath = $this->getXPath();
            $nodes = $xpath->query('//tei:body//text()');
            foreach ($nodes as $node) {
                if (preg_match('/(.*?)(\[\s*\.\s*\.\s*\.\s*\])(.*)/', $node->textContent, $matches)) {
                    $found = true;
                    $gapNode = $node;
                    
                    if (($len = strlen($matches[1])) > 0) {
                        // split before
                        $gapNode = $node->splitText($this->charOffset($node->textContent, $len));
                    }
                    
                    if (strlen($matches[3]) > 0) {
                        // split after
                        $gapNode = $gapNode->splitText($this->charOffset($gapNode->textContent, strlen($matches[2])))
                            ->previousSibling;
                    }
                   
                    $node->parentNode->replaceChild($this->dom->createElement('gap'), $gapNode);       
                }
            }
            
        } while ($found);
    }
}

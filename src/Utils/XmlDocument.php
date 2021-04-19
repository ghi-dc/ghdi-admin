<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocument
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/document.php
 *
 * TODO: Build a separate Component
 * TODO: Finalize switch from DOMDocument to FluentDOM
 */

namespace App\Utils;

use FluentDOM\Exceptions\LoadingError\FileNotLoaded;

class XmlDocument
extends Document
{
    protected $mimeType = 'text/xml';
    protected $dom = null;

    /**
     * Construct new document
     */
    public function __construct(array $options = [])
    {
        if (array_key_exists('dom', $options)) {
            $this->dom = $options['dom'];
            unset($options['dom']);
        }

        parent::__construct($options);
    }

    /**
     * Load string or file into \FluentDOM\DOM\Document
     *
     * @param string $source
     * @return \FluentDOM\DOM\Document|false
     */
    private function loadXml($source, $fromFile = false)
    {
        try {
            return \FluentDOM::load($source, 'xml', [
                \FluentDOM\Loader\Options::ALLOW_FILE => $fromFile,
                \FluentDOM\Loader\Options::PRESERVE_WHITESPACE => true,
            ]);
        }
        catch (FileNotLoaded $e) {
            return false;
        }
    }

    protected function getXPath()
    {
        $xpath = new \DOMXPath($this->dom);
        $this->registerDefaultXpathNamespaces($xpath);

        return $xpath;
    }

    protected function registerNamespaces()
    {
    }

    public function loadString($xml)
    {
        $dom = $this->loadXml($xml);
        if (false === $dom) {
            return false;
        }

        $this->dom = $dom;

        $this->registerNamespaces();

        return true;
    }

    public function load($fname)
    {
        $dom = $this->loadXml($fname, true);
        if (false === $dom) {
            return false;
        }

        $this->dom = $dom;

        $this->registerNamespaces();

        return true;
    }

    public function evaluateXpath($expr, $callback)
    {
        $xpath = $this->getXPath();
        $callback($xpath->evaluate($expr));
    }

    protected function unwrapChildren($node)
    {
        $parent = $node->parentNode;

        while ($node->firstChild) {
           $parent->appendChild($node->firstChild);
        }

        $parent->removeChild($node);
    }

    protected function addChildStructure($parent, $structure, $prefix = '')
    {
        foreach ($structure as $tagName => $content) {
            if (is_scalar($content)) {
                $self = $parent->addChild($prefix . $tagName, $content);
            }
            else {
                $atKeys = preg_grep('/^@/', array_keys($content));
                if (!empty($atKeys)) {
                    // simple element with attributes
                    if (in_array('@value', $atKeys)) {
                        $self = $parent->addChild($prefix . $tagName, $content['@value']);
                    }
                    else {
                        $self = $parent->addChild($prefix . $tagName);
                    }

                    foreach ($atKeys as $key) {
                        if ('@value' == $key) {
                            continue;
                        }
                        $self->addAttribute($prefix . ltrim($key, '@'), $content[$key]);
                    }
                }
                else {
                    $self = $parent->addChild($prefix . $tagName);
                    $this->addChildStructure($self, $content, $prefix);
                }
            }
        }
    }

    protected function registerDefaultXpathNamespaces($dom)
    {
    }

    protected function extractTextContent(\SimpleXMLElement $node, $normalizeWhitespace = true)
    {
        $textContent = dom_import_simplexml($node)->textContent;
        if ($normalizeWhitespace) {
            // http://stackoverflow.com/a/33980774
            return preg_replace(['(\s+)u', '(^\s|\s$)u'], [' ', ''], $textContent);
        }

        return $textContent;
    }

    /**
     * Allow the loaders to validate the first part of the provided string.
     *
     * From https://github.com/ThomasWeinert/FluentDOM/blob/master/src/FluentDOM/Loader/Supports.php
     *
     * @param string $haystack
     * @param string $needle
     * @param bool $ignoreWhitespace
     * @return bool
     */
    private function startsWith(string $haystack, string $needle, bool $ignoreWhitespace = TRUE): bool {
      return $ignoreWhitespace
        ? (bool)\preg_match('(^\s*'.\preg_quote($needle, '(').')', $haystack)
        : 0 === \strpos($haystack, $needle);
    }

    /**
     *  @param mixed $source
     */
    public function validate($schemaSource, $schemaType = 'relaxng')
    {
        switch ($schemaType) {
            case 'relaxng':
                $document = new \Brunty\DOMDocument();
                $document->loadXML($this->saveString());

                // The first character in an XML file must be < or whitespace
                // https://stackoverflow.com/a/47803127
                // so assume filename unless $source starts with \s<
                $schemaSourceIsString = $this->startsWith($schemaSource, '<');

                $result = $schemaSourceIsString
                    ? $document->relaxNGValidateSource($schemaSource)
                    : $document->relaxNGValidate($schemaSource);

                if (!$result) {
                    $errors = [];
                    foreach ($document->getValidationWarnings() as $message) {
                        $errors[] = (object)[ 'message' => $message ];
                    }
                    $this->errors = $errors;
                }

                return $result;
                break;

            default:
                throw new \InvalidArgumentException('Invalid schemaType: ' . $schemaType);
        }
    }

    /**
     * Reformatting XML is not trivial:
     *  XML documents are text files that describe complex documents.
     *  Some of the white space (spaces, tabs, line feeds, etc.) in the
     *  XML document belongs to the document it describes (such as the
     *  space between words in a paragraph) and some of it belongs to
     *  the XML document (such as a line break between two XML elements).
     *  Whitespace belonging to the XML file is called insignificant
     *  whitespace. The meaning of the XML would be the same if the
     *  insignificant whitespace were removed. Whitespace belonging to
     *  the document being described is called significant whitespace.
     *  https://www.oxygenxml.com/doc/versions/21.0/ug-editor/topics/format-and-indent-xml.html
     */
    public function prettify()
    {
        /*
        if (class_exists('\tidy')) {
            // inline-element handling doesn't work for xml-mode, converts e.g.
            //  text <gap/> more text
            // to
            //  text
            //      <gap/>more text
            // see https://github.com/htacg/tidy-html5/issues/652
            $configuration = [
                'input-xml' => true,
                'output-xml' => true,
                'preserve-entities' => true,
                'indent' => true,
                'indent-spaces' => 4,
                'input-encoding' => 'utf8',
                'indent-attributes' => false,
                'wrap' => 120,
            ];

            $tidy = new \tidy;
            $tidy->parseString($this->saveString(), $configuration, 'utf8');
            $tidy->cleanRepair();

            $this->loadString((string)$tidy);
        }
        */

        $prettyPrinter = $this->getOption('prettyPrinter');
        if (!is_null($prettyPrinter)) {
            return $prettyPrinter->prettyPrint($this);
        }

        return false;
    }

    public function getDom()
    {
        return $this->dom;
    }

    public function saveString()
    {
        if (is_null($this->dom)) {
            return null;
        }

        return $this->dom->saveXML();
    }
}

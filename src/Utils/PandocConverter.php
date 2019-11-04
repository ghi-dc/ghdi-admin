<?php
/**
 * Methods for Document Conversions.
 * Interfaces inspired by ezcDocumentConverter
 *  https://github.com/zetacomponents/Document/blob/master/src/interfaces/converter.php
 * TODO: Build a separate Component
 */

namespace App\Utils;

class PandocConverter
extends DocumentConverter
{
    protected $path = '';
    protected $mimeToFormat = [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    public function __construct(array $options = [])
    {
        parent::__construct($options);

        if (array_key_exists('path', $this->options)) {
            $this->path = $this->options['path'];
        }
    }

    protected function exec($arguments)
    {
        $cmd = $this->path
             . 'pandoc '
             . join(' ', $arguments);

        $ret = exec($cmd, $lines, $retval);

        return join("\n", $lines);
    }

    protected function cleanUp($ret)
    {
        if (method_exists($ret, 'cleanUp')) {
            $ret->cleanUp();
        }
    }

    /**
     * Convert documents between two formats
     *
     * Convert documents of the given type to the requested type.
     *
     * @return Document
     */
    public function convert(Document $doc)
    {
        $arguments = [];

        $mimeType = $doc->getMimeType();
        if (!empty($mimeType) && array_key_exists($mimeType, $this->mimeToFormat))  {
            $arguments[] = '-f ' . $this->mimeToFormat[$mimeType];
        }

        if (array_key_exists('target', $this->options)) {
            $ret = $this->options['target'];

            if ($ret instanceof \App\Utils\TeiSimplePrintDocument) {
                $arguments[] = '-t tei';
                if (false !== $ret->getOption('standalone')) {
                    $arguments[] = '-s';
                }
            }
            else {
                throw new \InvalidArgumentException('Not handling conversion to ' . $className . ' yet');
            }
        }
        else {
            throw new \InvalidArgumentException('No target given');
        }

        // reading from stdin messes up encoding, so write into tmp
        $tempFileOut = tempnam(sys_get_temp_dir(), 'TMP_');
        $arguments[] = '-o ' . $tempFileOut;

        $tempFileIn = tempnam(sys_get_temp_dir(), 'TMP_');
        $doc->save($tempFileIn);

        $arguments[] = $tempFileIn;

        $this->exec($arguments);

        @unlink($tempFileIn);

        $ret->load($tempFileOut);
        @unlink($tempFileOut);

        $this->cleanUp($ret);

        return $ret;
    }
}
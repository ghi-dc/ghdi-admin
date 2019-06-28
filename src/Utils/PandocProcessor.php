<?php

namespace App\Utils;

/*
 *
 */
class PandocProcessor
{
    protected $path = '';

    var $config = [];

    public function __construct($config = null)
    {
        if (isset($config) && is_array($config)) {
            $this->config = $config;
        }
        if (array_key_exists('path', $this->config)) {
            $this->path = $this->config['path'];
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

    public function convertHtmlFragmentToTeiSimple($htmlFragment)
    {
        $tempFileIn = tempnam(sys_get_temp_dir(), 'TMP_');
        file_put_contents($tempFileIn, $htmlFragment);

        // reading from stdin messes up encoding, so write into tmp
        $tempFileOut = tempnam(sys_get_temp_dir(), 'TMP_');
        $this->exec([ '-f html', '-t tei', '-o ' . $tempFileOut, $tempFileIn ]);
        @unlink($tempFileIn);
        $ret = trim(file_get_contents($tempFileOut)); // remove trailing newline
        @unlink($tempFileOut);

        return $ret;
    }

    public function convertWordToTeiSimple($fileIn, $standalone = false)
    {
        $format = 'docx';
        $ext = pathinfo($fileIn, PATHINFO_EXTENSION);
        if (in_array($ext, [ 'odt' ])) {
            $format = $ext;
        }

        // reading from stdin messes up encoding, so write into tmp
        $tempFileOut = tempnam(sys_get_temp_dir(), 'TMP_');

        $options = [ '-f ' . $format, '-t tei', '-o ' . $tempFileOut ];

        if ($standalone) {
            $options[] = '-s';
        }

        $options[] = $fileIn;

        $this->exec($options);

        $ret = trim(file_get_contents($tempFileOut)); // remove trailing newline
        @unlink($tempFileOut);

        return $ret;
    }
}

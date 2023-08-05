<?php
// src/Service/ImageConversion/ImageConversion.php

namespace App\Service\ImageConversion;

class ImageMagickProvider
{
    protected $conversionMap = [
        'application/pdf' => 'image/*',
        'image/*' => 'image/*',
    ];

    protected $options;

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    public function getConversionMap()
    {
        return $this->conversionMap;
    }

    protected function buildConvertCommand()
    {
        $cmd = 'convert';
        if (!empty($this->options['binary_path'])) {
            // TODO: check if separator is actually needed
            $cmd = $this->options['binary_path'] . '/' . $cmd;
        }

        return $cmd;
    }

    public function convert($fname_src, $fname_dst, $options = [])
    {
        $type_src = $options['src_type'];
        $type_target = $options['target_type'];

        if (in_array($type_src, [ 'application/pdf' ])) {
            $fname_src .= '[0]'; // only first page, maybe use this also for tiff and similar type_target with multiple images
        }

        $arguments = [];

        if (!empty($options['geometry'])) {
            if (preg_match('/((.*)\^)\!$/', $options['geometry'], $matches)) {
                $arguments[] = '-geometry';
                $arguments[] = $matches[1];
                $arguments[] = '-gravity';
                $arguments[] = 'center';
                $arguments[] = '-extent';
                $arguments[] = $matches[2];
            }
            else {
                $arguments[] = '-geometry';
                $arguments[] = $options['geometry'];
            }
        }

        if ($type_src == 'application/pdf') {
            $arguments[] = '-flatten';
        }

        $arguments[] = $fname_src;
        $arguments[] = $fname_dst;

        // prepend command
        array_unshift($arguments, $this->buildConvertCommand());

        $process = new \Symfony\Component\Process\Process($arguments);
        $process->setTimeout(3600); // 60 seconds default might be too short for large images
        $process->run();

        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return true;
    }

    public function getName()
    {
        return 'imagemagick-provider';
    }
}

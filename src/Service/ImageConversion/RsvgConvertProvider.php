<?php
// src/Service/ImageConversion/ImageConversion.php

namespace App\Service\ImageConversion;

/**
 * Call command-line rsvg-convert
 *
 * You can install it through
 *  sudo apt-get install librsvg2-bin
 *
 * On Windows, you can download from
 *  https://github.com/miyako/console-rsvg-convert/releases/tag/2.1.3
 *
 */
class RsvgConvertProvider
{
    protected $conversionMap = [
        'image/svg+xml' => 'image/png',
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
        $cmd = 'rsvg-convert';
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

        $arguments = [];

        $arguments[] = $fname_src;

        if (!empty($options['geometry'])) {
            // 640x (set first dimension)
            // x480 (set second dimension)

            if (!preg_match('/^(\d*)x(\d*)([\^\!\<\>\%]*)$/',
                            $options['geometry'], $matches))
            {
                throw new \InvalidArgumentException('Invalid geometry ' . $options['geometry']);
            }

            if ('' === $matches[1] && '' === $matches[2]) {
                throw new \InvalidArgumentException('Invalid geometry ' . $options['geometry']);
            }

            if ($matches[1] > 0) {
                $arguments[] = sprintf('--width=%d', $matches[1]);
            }

            if ($matches[2] > 0) {
                $arguments[] = sprintf('--height=%d', $matches[1]);
            }
        }

        if (!empty($options['xresolution']) && $options['xresolution'] > 0) {
            $arguments[] =  sprintf('--dpi-x=%d', $options['xresolution']);
        }

        if (!empty($options['yresolution']) && $options['yresolution'] > 0) {
            $arguments[] =  sprintf('--dpi-y=%d', $options['yresolution']);
        }

        $arguments[] = '--output=' . $fname_dst;

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
        return 'rsvg-convert-provider';
    }
}

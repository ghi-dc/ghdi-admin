<?php

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
                $arguments[] = '-geometry ' . $matches[1];
                $arguments[] = '-gravity center';
                $arguments[] = '-extent ' . $matches[2];
            }
            else {
                $arguments[] = '-geometry ' . $options['geometry'];
            }
        }

        if ($type_src == 'application/pdf') {
            $arguments[] = '-flatten';
        }

        /* doesn't work on windows ?!
        $builder = new \Symfony\Component\Process\ProcessBuilder();
        $builder->setPrefix($this->buildConvertCommand());

        $cmd = $builder
            ->setArguments($arguments +
                           array($fname_src,
                                 $fname_dst))
            ->getProcess()
            ->getCommandLine();
        */
        $arguments[] = $fname_src;
        $arguments[] = $fname_dst;
        for ($i = 0; $i < count($arguments); $i++) {
            if (preg_match('/^(\-\S+)\s+(.*)$/', $arguments[$i], $matches)) {
                $arguments[$i] = $matches[1] . ' ' . escapeshellarg($matches[2]);
            }
            else {
                $arguments[$i] = escapeshellarg($arguments[$i]);
            }
        }

        $cmd = $this->buildConvertCommand()
             . (count($arguments) > 0 ? ' ' . implode(' ', $arguments) : '');

        $process = new \Symfony\Component\Process\Process($cmd);
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

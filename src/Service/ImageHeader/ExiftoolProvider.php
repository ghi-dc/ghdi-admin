<?php

namespace App\Service\ImageHeader;

/**
 * TODO: ImageMagickProvider
 * with identify -format '%x,%y' filename.
 */
class ExiftoolProvider extends CommandlineProvider
{
    protected $supportedTypes = ['image/*'];

    private function buildExiftoolCommand()
    {
        $cmd = 'exiftool';

        if (!empty($this->options['binary_path'])) {
            // TODO: check if separator is actually needed
            $cmd = $this->options['binary_path'] . '/' . $cmd;
        }

        return $cmd;
    }

    public function getResolution($fnameSrc, $options = [])
    {
        $arguments = [
            $this->buildExiftoolCommand(),
            '-p',
            '$XResolution,$YResolution',
            $fnameSrc,
        ];

        try {
            $lines = $this->execute($arguments);
        }
        catch (\RuntimeException $e) {
            return null;
        }

        $resolution = [];
        if (preg_match('/^(\d+)\,(\d+)$/', $lines[0], $matches)) {
            $resolution['xresolution'] = $matches[1];
            $resolution['yresolution'] = $matches[1];
        }

        return $resolution;
    }

    public function setResolution($fnameSrc, $options = [])
    {
        if (empty($options['xresolution']) && empty($options['yresolution'])) {
            return true;
        }

        $arguments = [
            $this->buildExiftoolCommand(),
            $fnameSrc,
        ];

        if (!empty($options['xresolution'])) {
            $arguments[] = '-xresolution=' . $options['xresolution'];
        }
        if (!empty($options['yresolution'])) {
            $arguments[] = '-yresolution=' . $options['yresolution'];
        }

        $arguments[] = '-resolutionunit=inches';

        $arguments[] = '-overwrite_original';

        try {
            $lines = $this->execute($arguments);
        }
        catch (\RuntimeException $e) {
            return false;
        }

        return true;
    }

    public function getName()
    {
        return 'exiftool-provider';
    }
}

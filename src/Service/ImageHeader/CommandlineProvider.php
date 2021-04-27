<?php

namespace App\Service\ImageHeader;

abstract class CommandlineProvider
{
    protected $supportedTypes;

    protected $options;

    public function __construct($options = [])
    {
        $this->options = $options;
    }

    public function getSupportedTypes()
    {
        return $this->supportedTypes;
    }

    protected function execute($arguments)
    {
        $process = new \Symfony\Component\Process\Process($arguments);
        $process->setTimeout(3600); // 60 seconds default might be too short for large images
        $process->run();

        // executes after the command finishes
        if (!$process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput());
        }

        return preg_split('/[\r\n]+/', $process->getOutput());
    }

    abstract function getName();
}

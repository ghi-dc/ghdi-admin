<?php

// src/Command/BaseCommand.php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BaseCommand
extends Command
{
    protected $siteKey = null;
    protected $projectDir;
    protected $params;

    public function __construct(string $siteKey,
                                ParameterBagInterface $params,
                                KernelInterface $kernel)
    {
        $this->siteKey = $siteKey;
        $this->projectDir = $kernel->getProjectDir();
        $this->params = $params;

        // you *must* call the parent constructor
        parent::__construct();
    }

    private function buildDtsUrlBase($locale)
    {
        return ('en' != $locale ? $locale . '/' : '')
            . 'api/dts/';
    }

    protected function buildDtsUrlCollections($locale)
    {
        return  $this->buildDtsUrlBase($locale) . 'collections';
    }

    protected function buildDtsUrlDocument($locale)
    {
        return  $this->buildDtsUrlBase($locale) . 'document';
    }
}

<?php

// src/Command/ExistDbCommand.php
namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;


abstract class ExistDbCommand
extends \Symfony\Component\Console\Command\Command
{
    protected $siteKey = null;
    protected $projectDir;
    protected $params;

    /**
     * @var \App\Service\ExistDbClientService
     */
    private $existDbClientService;

    public function __construct(string $siteKey,
                                \App\Service\ExistDbClientService $existDbClientService,
                                ParameterBagInterface $params,
                                KernelInterface $kernel)
    {
        $this->siteKey = $siteKey;

        // you *must* call the parent constructor
        parent::__construct();

        $this->existDbClientService = $existDbClientService;
        $this->projectDir = $kernel->getProjectDir();
        $this->params = $params;
    }

    protected function getExistDbClient()
    {
        $existDbClientService = $this->existDbClientService;
        $existDbOptions = $this->params->get('app.existdb.console.options');
        $existDbClient = $existDbClientService->getClient($existDbOptions['user'], $existDbOptions['password']);

        $existDbBase = $this->params->get('app.existdb.base');
        $existDbClient->setCollection($existDbBase);

        return $existDbClient;
    }
}

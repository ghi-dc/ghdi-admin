<?php

// src/Command/ExistDbCommand.php
namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class ExistDbCommand
extends BaseCommand
{
    /**
     * @var \App\Service\ExistDbClientService
     */
    private $existDbClientService;

    public function __construct(string $siteKey,
                                ParameterBagInterface $params,
                                KernelInterface $kernel,
                                \App\Service\ExistDbClientService $existDbClientService)
    {
        $this->existDbClientService = $existDbClientService;

        // you *must* call the parent constructor
        parent::__construct($siteKey, $params, $kernel);
    }

    protected function getExistDbClient() : \ExistDbRpc\Client
    {
        $existDbClientService = $this->existDbClientService;
        $existDbOptions = $this->params->get('app.existdb.console.options');
        $existDbClient = $existDbClientService->getClient($existDbOptions['user'], $existDbOptions['password']);

        $existDbBase = $this->params->get('app.existdb.base');
        $existDbClient->setCollection($existDbBase);

        return $existDbClient;
    }
}

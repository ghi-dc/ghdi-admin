<?php

// src/Command/ExistDbICommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

abstract class ExistDbCommand
extends ContainerAwareCommand
{
    protected function getExistDbClient()
    {
        $existDbClientService = $this->getContainer()->get(\App\Service\ExistDbClientService::class);
        $existDbOptions = $this->getContainer()->getParameter('app.existdb.console.options');
        $existDbClient = $existDbClientService->getClient($existDbOptions['user'], $existDbOptions['password']);

        $existDbBase = $this->getContainer()->getParameter('app.existdb.base');
        $existDbClient->setCollection($existDbBase);

        return $existDbClient;
    }
}

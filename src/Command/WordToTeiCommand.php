<?php

// src/Command/WordToTeiCommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class WordToTeiCommand
extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('convert:word2tei')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'What file do you want to convert'
            )
            ->setDescription('Convert Word-File (docx or odt) to TEI')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $file = $input->getArgument('file');;
        if (!file_exists($file)) {
            $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                     $file));

            return -1;
        }
        
        $officeDoc = new \App\Utils\BinaryDocument();
        $officeDoc->load($file);

        $teiPrettyPrinter = $this->getContainer()->get('app.tei-prettyprinter');

        // inject TeiFromWordCleaner
        $myTarget = new class([ 'prettyPrinter' => $teiPrettyPrinter ])
        extends \App\Utils\TeiSimplePrintDocument
        {
            use \App\Utils\TeiFromWordCleaner;
        };
        
        $pandocConverter = $this->getContainer()->get(\App\Utils\PandocConverter::class);
        $pandocConverter->setOption('target', $myTarget);
              
        $teiSimpleDoc = $pandocConverter->convert($officeDoc);
        
        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter();
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);
        
        echo (string)$teiDtabfDoc;
    }
}

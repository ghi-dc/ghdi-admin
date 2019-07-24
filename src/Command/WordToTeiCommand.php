<?php

// src/Command/WordToTeiCommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
            ->addOption(
                'validate',
                null,
                InputOption::VALUE_OPTIONAL,
                'validate result against basisformat.rng',
                true
            )
            ->addOption(
                'locale',
                null,
                InputOption::VALUE_OPTIONAL,
                'what locale (en or de)'
            )
            ->addOption(
                'genre',
                null,
                InputOption::VALUE_OPTIONAL,
                'what genre (introduction or document)'
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

        $pandocConverter = $this->getContainer()->get(\App\Utils\PandocConverter::class);

        // inject TeiFromWordCleaner
        $myTarget = new class()
        extends \App\Utils\TeiSimplePrintDocument
        {
            use \App\Utils\TeiFromWordCleaner;
        };

        $pandocConverter->setOption('target', $myTarget);

        $teiSimpleDoc = $pandocConverter->convert($officeDoc);

        $conversionOptions = [
            'prettyPrinter' => $this->getContainer()->get('app.tei-prettyprinter'),
        ];

        if (!empty($input->getOption('locale'))) {
            $conversionOptions['language'] = \App\Utils\Iso639::code1to3($input->getOption('locale'));
        }
        if (!empty($input->getOption('genre'))) {
            $conversionOptions['genre'] = $input->getOption('genre');
        }

        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);

        // validate except for validate=false
        $validate = is_null($input->getOption('validate'))
            || !in_array($input->getOption('validate'), [ '0', 'false' ]);

        if ($validate) {
            $valid = $teiDtabfDoc->validate($this->getContainer()->get('kernel')->getProjectDir() . '/data/schema/basisformat.rng');
            if (!$valid) {
                 $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

                foreach ($teiDtabfDoc->getErrors() as $error) {
                    $errOutput->writeln(sprintf('<error>Validation error: %s</error>',
                                                $error->message));
                }
            }
        }

        echo (string)$teiDtabfDoc;
    }
}

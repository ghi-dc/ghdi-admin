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

class TeiToPdfCommand
extends ContainerAwareCommand
{
    protected $pdfConverter;

    public function __construct(\App\Utils\MpdfConverter $pdfConverter)
    {
        $this->pdfConverter = $pdfConverter;

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('convert:tei2pdf')
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'What file do you want to convert'
            )
            ->setDescription('Convert TEI-File to PDF')
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

        $teiDoc = new \App\Utils\TeiDocument();
        $teiDoc->load($file);

        $xslConverter = $this->getContainer()->get(\App\Utils\XslConverter::class);
        $xslConverter->setOption('xsl', 'data/styles/dta2html.xsl');
        $xslConverter->setOption('target', new \App\Utils\HtmlDocument());

        $htmlDoc = $xslConverter->convert($teiDoc);

        $pdfDoc = $this->pdfConverter->convert($htmlDoc);

        echo (string)$pdfDoc;
    }
}

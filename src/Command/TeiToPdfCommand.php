<?php

// src/Command/TeiToPdfCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Standalone conversion of TEI to PDF.
 */
class TeiToPdfCommand extends Command
{
    protected $pdfConverter;
    /**
     * @var \App\Utils\XslConverter
     */
    private $xslConverter;

    public function __construct(\App\Utils\MpdfConverter $pdfConverter, \App\Utils\XslConverter $xslConverter)
    {
        $this->pdfConverter = $pdfConverter;

        // you *must* call the parent constructor
        parent::__construct();
        $this->xslConverter = $xslConverter;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        if (!file_exists($file)) {
            $output->writeln(sprintf(
                '<error>File does not exist (%s)</error>',
                $file
            ));

            return -1;
        }

        $teiDoc = new \App\Utils\TeiDocument();
        $teiDoc->load($file);

        $xslConverter = $this->xslConverter;
        $xslConverter->setOption('xsl', 'data/styles/dta2html.xsl');
        $xslConverter->setOption('target', new \App\Utils\HtmlDocument());

        $htmlDoc = $xslConverter->convert($teiDoc);

        $pdfDoc = $this->pdfConverter->convert($htmlDoc);

        echo (string) $pdfDoc;

        return 0;
    }
}

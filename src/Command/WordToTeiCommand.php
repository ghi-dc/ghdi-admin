<?php

// src/Command/WordToTeiCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Implement
 *  convert:word2tei
 * for stand-alone conversions of .docx files.
 */
class WordToTeiCommand extends Command
{
    protected $projectDir;
    protected $pandocConverter;
    protected $teiPrettyPrinter;

    public function __construct(
        KernelInterface $kernel,
        \App\Utils\PandocConverter $pandocConverter,
        \App\Utils\XmlPrettyPrinter\XmlPrettyPrinter $teiPrettyPrinter
    ) {
        parent::__construct();

        $this->projectDir = $kernel->getProjectDir();
        $this->pandocConverter = $pandocConverter;
        $this->teiPrettyPrinter = $teiPrettyPrinter;
    }

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
            ->addOption(
                'use-metadata',
                null,
                InputOption::VALUE_NONE,
                'Specify to force reindexing existing resources'
            )
            ->setDescription('Convert Word-File (docx or odt) to TEI')
        ;

        if ('WIN' === strtoupper(substr(PHP_OS, 0, 3))) {
            // the following is currently windows only and needs perl and word installed
            $this
                ->addOption(
                    'underline2strikethrough',
                    null,
                    InputOption::VALUE_NONE,
                    'Replaces underline with strikethrough'
                );
        }
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

        $officeDoc = new \App\Utils\BinaryDocument();

        if ($input->getOption('underline2strikethrough')) {
            // TODO: make perl-path configurable
            $scriptName = $this->projectDir . '/data/bin/word-search-replace.pl';

            $process = new \Symfony\Component\Process\Process(['C:\\Run\\Perl\\perl\\bin\\perl.exe', $scriptName, $file]);
            $process->run();

            // executes after the command finishes
            if (!$process->isSuccessful()) {
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
            }

            $officeDoc->loadString($process->getOutput());
        }
        else {
            $officeDoc->load($file);
        }

        // inject TeiFromWordCleaner
        $myTarget = new class extends \App\Utils\TeiSimplePrintDocument {
            use \App\Utils\TeiFromWordCleaner;
        };

        $this->pandocConverter->setOption('target', $myTarget);

        $teiSimpleDoc = $this->pandocConverter->convert($officeDoc);

        $conversionOptions = [
            'prettyPrinter' => $this->teiPrettyPrinter,
        ];

        if (!empty($input->getOption('locale'))) {
            $conversionOptions['language'] = \App\Utils\Iso639::code1to3($input->getOption('locale'));
        }

        if (!empty($input->getOption('genre'))) {
            $conversionOptions['genre'] = $input->getOption('genre');
        }

        $useMetadata = $input->getOption('use-metadata');

        if ($useMetadata) {
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($file);
            $metadata = $phpWord->getDocInfo();
            if (!empty($metadata->getCreator())) {
                $names = preg_split('/s*;*s/', $metadata->getCreator());
                $authors = [];
                foreach ($names as $name) {
                    $person = new \App\Entity\Person();
                    $parts = explode(', ', $metadata->getCreator(), 2);
                    if (2 == count($parts)) {
                        // Family Name, Given Name
                        $person->setFamilyName($parts[0]);
                        $person->setGivenName($parts[1]);
                    }
                    else {
                        $person->setName($metadata->getCreator());
                    }

                    $authors[] = $person;
                }

                if (!empty($authors)) {
                    $conversionOptions['authors'] = $authors;
                }
            }
        }

        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);

        // validate except for validate=false
        $validate = is_null($input->getOption('validate'))
            || !in_array($input->getOption('validate'), ['0', 'false']);

        if ($validate) {
            $valid = $teiDtabfDoc->validate($this->projectDir . '/data/schema/basisformat.rng');
            if (!$valid) {
                $errOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

                foreach ($teiDtabfDoc->getErrors() as $error) {
                    $errOutput->writeln(sprintf(
                        '<error>Validation error: %s</error>',
                        $error->message
                    ));
                }

                return -2;
            }
        }

        echo $teiDtabfDoc->saveString();

        return 0;
    }
}

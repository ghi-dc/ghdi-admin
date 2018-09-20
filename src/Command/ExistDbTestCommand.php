<?php

// src/Command/ExistDbTestCommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class ExistDbTestCommand
extends ExistDbCommand
{
    protected $demoCollection = '/db/apps/demo/data';

    protected function configure()
    {
        $this
            ->setName('existdb:test')
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'What action do you want to test'
            )
            ->setDescription('Show the config of existdb-client')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        die('TODO: adjust to new client-library');

        $existDbClient = $this->getExistDbClient();

        switch ($action = $input->getArgument('action')) {
            case 'add-binary':
                $filename = $this->getContainer()->get('kernel')->getProjectDir()
                    . '/data/test/bild.jpg';
                if (!file_exists($filename)) {
                    $output->writeln(sprintf('<error>File does not exist (%s)</error>',
                                             $filename));
                    return -2;

                }
                $finfo = finfo_open(FILEINFO_MIME_TYPE); // return mime type ala mimetype extension
                $mimeType = finfo_file($finfo, $filename);
                finfo_close($finfo);

                $query->setBody(file_get_contents($filename));
                $query->setResource('bild.jpg');
                $query->setBinaryContent(true);
                $query->setMimeType($mimeType);
                $query->setCollection($this->collection);
                $response = $query->put();

                return false === $response ? -3 : 0;

                break;

            case 'get-binary':
                $query->setCollection($this->collection);
                $query->setResource('bild.jpg');
                $query->setBinaryContent(true);

                $response = $query->get();
                if (false !== $response) {
                    $filename = $this->getContainer()->get('kernel')->getProjectDir()
                        . '/data/bild_get.jpg';
                    file_put_contents($filename, $response->getRawResult());

                    return 0;
                }

                return -4;
                break;

            case 'get-resource-info':
                $query->setCollection($this->collection);
                $query->setResource('bild.jpg');
                $response = $query->head();
                var_dump($response);

                return 0;
                break;

            case 'get-collection-desc':
                var_dump($existDbClient->getCollectionDesc($this->collection));

                return 0;
                break;

            case 'import':
                $query->setCollection($this->collection);
                foreach (['hamlet.xml', 'macbeth.xml', 'r_and_j.xml'] as $docname) {
                    $query->setResource($docname);
                    $info = $query->head();
                    if (false === $info) {
                        $query->setCollection($this->demoCollection);
                        $query->setBinaryContent(true); // we don't want to wrap/parse the response
                        $response = $query->get();
                        if (false === $response) {
                            $output->writeln(sprintf('<warn>GET %s/%s failed</warn>',
                                             $this->demoCollection, $docname));
                        }
                        continue;

                        $query->setCollection($this->collection);
                        $query->setBody($response->getRawResult());

                        $response = $query->put();
                        if (false !== $response) {
                            $output->writeln(sprintf('<info>PUT %s/%s succeeded</info>',
                                             $this->collection, $docname));
                        }
                        else {
                            $output->writeln(sprintf('<warn>PUT %s/%s failed</warn>',
                                             $this->collection, $docname));
                        }
                    }
                }

                return 0;
                break;

            case 'analyze':
                $existDbClient->setHowMany(0);

                $query = $existDbClient->prepareQuery();
                $query->setBinaryContent(true);

                $xql = <<<EOXQL
                xquery version "3.0";
                declare option exist:serialize "method=xhtml media-type=text/html";
                declare variable \$page-title as xs:string := "Play analysis";
                declare variable \$play-uri as xs:string := "{$this->collection}/hamlet.xml";
                declare function local:word-count(\$elms as element()*) as xs:integer
                {
                    sum(\$elms ! count(tokenize(., "\W+")))
                };
                let \$play-document := doc(\$play-uri)
                let \$play-title := string(\$play-document/PLAY/TITLE)
                let \$speakers := distinct-values(\$play-document//SPEECH/SPEAKER)
                let \$all-lines := \$play-document//SPEECH/LINE
                let \$all-word-count := local:word-count(\$all-lines)

                return
                <html>
                    <head>
                    <meta HTTP-EQUIV="Content-Type" content="text/html; charset=UTF-8"/>
                    <title>{\$page-title}</title>
                    </head>
                    <body>
                    <h1>{\$page-title}: {\$play-title}</h1>
                    <p>Total lines: {count(\$all-lines)}</p>
                    <p>Total words: {\$all-word-count}</p>
                    <p>Total speakers: {count(\$speakers)}</p>
                    <br/>
                    <table border="1">
                    <tr>
                    <th>Speaker</th>
                    <th>Lines</th>
                    <th>Words</th>
                    <th>Perc</th>
                    </tr>
                    {
                    for \$speaker in \$speakers
                        let \$speaker-lines :=
                        \$play-document//SPEECH[SPEAKER eq \$speaker]/LINE
                        let \$speaker-word-count := local:word-count(\$speaker-lines)
                        let \$speaker-word-perc :=
                        (\$speaker-word-count div \$all-word-count) * 100
                        order by \$speaker
                        return
                    <tr>
                    <td>{\$speaker}</td>
                    <td>{count(\$speaker-lines)}</td>
                    <td>{\$speaker-word-count}</td>
                    <td>{format-number(\$speaker-word-perc, "0.00")}%</td>
                    </tr>
                    }
                </table>
                </body>
                </html>
EOXQL;
                $query->setQuery($xql);
                $response = $query->get();
                echo $response->getRawResult();
                return 0;
                break;

            case 'collection':
                $query = $existDbClient->prepareQuery();
                $query->setBinaryContent(true);

                $xql = <<<EOXQL
xquery version "3.0";
<plays>
{
    for \$resource in collection("{$this->collection}")
    return
    <play uri="{base-uri(\$resource)}">
    {
        \$resource/PLAY/TITLE/text()
    }
    </play>
}
</plays>
EOXQL;
                $query->setQuery($xql);
                $response = $query->get();
                echo $response->getRawResult();
                return 0;
                break;

            case 'search':
                $xql = <<<EOXQL
for \$line in
    collection('{$this->collection}')//SPEECH/LINE[contains(., 'fantasy')]
return
<LINE play="{base-uri(\$line)}">{string(\$line)}</LINE>
EOXQL;
                break;


            default:
                // see https://exist-db.org/exist/apps/fundocs/view.html?uri=http://exist-db.org/xquery/system&location=java:org.exist.xquery.functions.system.SystemModule&details=true
                $xql = 'system:get-version()';
        }

        $query->setQuery($xql);

        $response = $query->get();
        if (false === $response) {
            $output->writeln(sprintf('<error>Query failed (%d: %s)</error>',
                                     $query->errorCode(),
                                     $query->errorMessage()));
            return -1;
        }

        $results = $response->getDocument();

        $count = count($results);
        if (1 == $count) {
            $output->writeln(sprintf('<info>%s -> %s</info>',
                                     $xql, $results[$i]['value']));
        }
        else {
            /*
            $output->writeln(sprintf('<error>Invalid number of results: %d</error>',
                                     $count));
            */
            var_dump($results);
        }

        return 0;
    }
}

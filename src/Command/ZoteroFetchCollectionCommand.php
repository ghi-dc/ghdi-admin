<?php

// src/Command/ZoteroFetchCollectionCommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class ZoteroFetchCollectionCommand
extends ExistDbCommand
{
    protected $zoteroApiService;

    public function __construct(string $siteKey,
                                \App\Service\ExistDbClientService $existDbClientService,
                                \App\Service\ZoteroApiService $zoteroApiService,
                                ParameterBagInterface $params,
                                KernelInterface $kernel)
    {
        // you *must* call the parent constructor
        parent::__construct($siteKey, $existDbClientService, $params, $kernel);

        $this->zoteroApiService = $zoteroApiService;

        $this->frontendDataDir = realpath($this->params->get('app.frontend.data_dir'));
        if (empty($this->frontendDataDir)) {
            die(sprintf('app.frontend.data_dir (%s) does not exist',
                        $this->params->get('app.frontend.data_dir')));
        }
    }

    protected function configure()
    {
        $this
            ->setName('zotero:fetch-collection')
            ->addArgument(
                'volume',
                InputArgument::REQUIRED,
                'Which volume do you want to index'
            )
            ->addArgument(
                'key',
                InputArgument::REQUIRED,
                'What collection you want to fetch'
            )
            ->setDescription('Fetch items from Zotero collection')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $volume = $input->getArgument('volume');
        if (empty($volume)) {
            $output->writeln(sprintf('<error>Missing volume</error>'));

            return -1;
        }

        if (!preg_match('/^volume\-\d+$/', $volume)) {
            $output->writeln(sprintf('<error>Invalid volume %s</error>',
                                     $volume));

            return -1;
        }

        $fnameOut = $this->frontendDataDir
            . '/volumes/' . $volume . '/'
            . str_replace('volume-', 'bibliography-', $volume) . '.json';

        $key = $input->getArgument('key');
        if (empty($key)) {
            $output->writeln(sprintf('<error>Missing collection</error>'));

            return -2;
        }

        $api = $this->zoteroApiService->getInstance();

        $request = $api
            ->collections($key);

        try {
            $response = $request->send();
        }
        catch (\GuzzleHttp\Exception\ClientException $e) {
            $output->writeln(sprintf('<error>Error requesting collection %s (%s)</error>',
                                     $key, $e->getResponse()->getStatusCode()));

            /*
            if (404 == $e->getResponse()->getStatusCode()) {
                // deleted
                return false;
            }
            */
            return -1;
        }

        $info = $response->getBody();
        $numItems = $info['meta']['numItems'];

        $start = 0;
        $batchSize = 50;

        $continue = $numItems > 0;
        $data = [];

        while ($continue) {
            // start with new instance since start/limit would get set multiple times in query string
            $request = $this->zoteroApiService->getInstance()
                ->collections($key)
                ->items()
                ->sortBy('creator')
                ->direction('asc')
                ->start($start)
                ->limit($batchSize);

            try {
                $response = $request->send();
            }
            catch (\GuzzleHttp\Exception\ClientException $e) {
                break;
            }

            $headers = $response->getHeaders();

            $start += $batchSize;
            $continue = $start < $headers['Total-Results'][0];

            $items  = $response->getBody();
            foreach ($items as $item) {
                $creativeWork = \App\Entity\CreativeWork::fromZotero($item['data'], $item['meta']);
                $data[] = $creativeWork->jsonSerialize(); // to citproc json
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                // something went wrong
                break;
            }
        }

        if (count($data) > 0) {
            $res = file_put_contents($fnameOut, json_encode([
                    'group-id' => $this->zoteroApiService->getGroupId(),
                    'key' => $key,
                    'data' => $data,
                ], JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE));

            if (false !== $res) {
                return 0;
            }

            $output->writeln(sprintf('<error>Error writing %s</error>',
                                     $fnameOut));

            return -2;
        }

        $output->writeln(sprintf('<info>Empty collection</info>'));

        return -3;
    }
}

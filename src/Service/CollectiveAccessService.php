<?php

// src/Service/CollectiveAccessService.php
namespace App\Service;

class CollectiveAccessService
{
    protected $options;

    public function __construct($options)
    {
        $this->options = $options;

        // See https://github.com/trisoftro/ca-service-wrapper
        if (!defined('__CA_SERVICE_API_USER__')) {
            define('__CA_SERVICE_API_USER__', $this->options['api-user']);
        }

        if (!defined('__CA_SERVICE_API_KEY__')) {
            define('__CA_SERVICE_API_KEY__', $this->options['api-key']);
        }
    }

    public function getSearchService($query, $table = 'ca_objects')
    {
        // https://docs.collectiveaccess.org/wiki/Web_Service_API#Searching
        $client = new \CA\SearchService($this->options['url'], $table, $query);
        // $client->setCredentials(__CA_SERVICE_API_USER__, __CA_SERVICE_API_KEY__); // not needed if we use these specific constants

        return $client;
    }

    public function getItemService($id, $table = 'ca_objects')
    {
        // https://docs.collectiveaccess.org/wiki/Web_Service_API#Getting_item-level_data
        $client = new \CA\ItemService($this->options['url'], $table, 'GET', $id);
        // $client->setCredentials(__CA_SERVICE_API_USER__, __CA_SERVICE_API_KEY__); // not needed if we use these specific constants

        return $client;
    }

    public function getCollections()
    {
        $filter = !empty($this->options['root-collection'])
            ? sprintf('ca_collections:"%s"', $this->options['root-collection'])
            : '*';

        $caSearchService = $this->getSearchService($filter, 'ca_collections');

        $result = $caSearchService->request();
        if (!$result->isOk()) {
            return null;
        }

        $data = $result->getRawData();

        $collections = $data['results'];
        if (count($collections) < $data['total']) {
            die('TODO: some pagination needed');
        }

        if (!empty($this->options['root-collection'])) {
            $skipIdno = $this->options['root-collection'];
            // filter out $skipIdno
            $collections = array_filter($collections, function ($collection) use ($skipIdno) {
                return $skipIdno !== $collection['idno'];
            });
        }

        return $collections;
    }
}
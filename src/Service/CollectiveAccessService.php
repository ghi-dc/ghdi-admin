<?php
// src/Service/CollectiveAccessService.php

namespace App\Service;

/**
 * Access the JSON-based REST web service API of CollectiveAccess
 *
 * The API is documented on
 *  https://docs.collectiveaccess.org/wiki/Web_Service_API
 *
 * The service uses
 *  https://github.com/trisoftro/ca-service-wrapper
 * as REST client
 *
 */
class CollectiveAccessService
{
    protected $options;

    /**
     * @param array $options Pass url, api-user, api-key and possibly root-collection
     */
    public function __construct(array $options)
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

    public function getUrl()
    {
        if (!array_key_exists('url', $this->options)) {
            return;
        }

        return $this->options['url'];
    }

    /**
     * Return a \CA\SearchService instance
     * to the REST-URL with the proper
     * $query and $table set
     *
     * You can then cann
     *  $result = $caSearchService->request();
     *
     * Refer to the Search_Syntax article
     *  https://docs.collectiveaccess.org/wiki/Search_Syntax
     * to learn how to build queries
     *
     * @see https://docs.collectiveaccess.org/wiki/Web_Service_API#Searching
     *
     * @param string $query REST query string
     * @param string $table
     * @return \CA\SearchService
     */
    public function getSearchService($query = '', $table = 'ca_objects') : \CA\SearchService
    {
        $client = new \CA\SearchService($this->options['url'], $table, $query);
        // since we define __CA_SERVICE_API_USER__ and __CA_SERVICE_API_KEY__
        // in the constructor, we don't need to set:
        // $client->setCredentials(__CA_SERVICE_API_USER__, __CA_SERVICE_API_KEY__); // not needed if we use these specific constants

        return $client;
    }

    /**
     * Return a \CA\ItemService instance
     * to the REST-URL with the proper
     * $id and $table set
     *
     * You can then cann
     *  $result = $caItemService->request();
     *
     * @see https://docs.collectiveaccess.org/wiki/Web_Service_API#Getting_item-level_data
     *
     * @param string $id Item identifier
     * @param string $table
     * @return \CA\ItemService
     */
    public function getItemService($id, $table = 'ca_objects') : \CA\ItemService
    {
        $client = new \CA\ItemService($this->options['url'], $table, 'GET', $id);
        // since we define __CA_SERVICE_API_USER__ and __CA_SERVICE_API_KEY__
        // in the constructor, we don't need to set:
        // $client->setCredentials(__CA_SERVICE_API_USER__, __CA_SERVICE_API_KEY__); // not needed if we use these specific constants

        return $client;
    }

    /**
     * Get the list of collections
     *
     * If root-collection is set in the options
     * the result will be filtered accordingly
     *
     */
    public function getCollections()
    {
        $filter = !empty($this->options['root-collection'])
            ? sprintf('idno_sort:"%s"', // was ca_collections:
                      $this->options['root-collection'])
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

        // reduce to collections with idno
        $collections = array_filter($collections, function ($collection) {
            return array_key_exists('idno', $collection);
        });

        if (!empty($this->options['root-collection'])) {
            $skipIdno = $this->options['root-collection'];
            // filter out $skipIdno
            $collections = array_filter($collections, function ($collection) use ($skipIdno) {
                return $skipIdno !== $collection['idno'];
            });
        }

        // sort by idno
        usort($collections, function($a, $b) {
            return strnatcmp($a['idno'], $b['idno']);
        });

        return $collections;
    }

    /**
     * Make use of getItemService to request an object-representation
     *
     * @param string $id Object-representation identifier
     * @return array|null
     */
    public function getObjectRepresentation($id)
    {
        $caItemService = $this->getItemService($id, 'ca_object_representations');

        $result = $caItemService->request();
        if (!$result->isOk()) {
            return null;
        }

        return $result->getRawData();
    }

    /**
     * Images imported from legacy GHDI have their ca_objects.idno_ghdi set
     */
    public function lookupByIdno($resourceId)
    {
        if (empty($this->options['root-collection'])
            || 'ghdi' != $this->options['root-collection']) {
            return;
        }

        if (!preg_match('/^(image|map)\-(\d+)$/', $resourceId, $matches)) {
            return;
        }

        $filter = sprintf('ca_objects.idno_ghdi:%d', $matches[2]);

        $caSearchService = $this->getSearchService($filter, 'ca_objects');

        $result = $caSearchService->request();
        if (!$result->isOk()) {
            return null;
        }

        $data = $result->getRawData();
        if (1 == $data['total']) {
            return $data['results'][0];
        }
    }
}

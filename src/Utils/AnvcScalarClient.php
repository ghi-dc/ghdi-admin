<?php

namespace App\Utils;

/**
 * For scalar namespace prefixes see
 *  https://github.com/anvc/scalar/blob/master/system/application/config/rdf.php
 */
class AnvcScalarClient
{
    protected $config = [];
    protected $additionalProperties = [
        'http://purl.org/dc/terms/creator' => 'dcterms:creator',
        'http://purl.org/dc/terms/date' => 'dcterms:date',
    ];

    public function __construct($config)
    {
        $this->config = $config;

        \Httpful\Httpful::register(\Httpful\Mime::JSON,
                                   new \Httpful\Handlers\JsonHandler([ 'decode_as_array' => true ]));
    }

    public function getBaseurl()
    {
        return $this->config['baseurl'];
    }

    public function getBook()
    {
        return $this->config['book'];
    }

    protected function lookupProperties(&$info, $properties)
    {
        $ret = [];

        foreach ($properties as $uri => $property) {
            if (array_key_exists($uri, $info)
                && in_array($info[$uri][0]['type'], [ 'literal', 'uri' ]))
            {
                $ret[$property] = $info[$uri][0]['value'];
            }
        }

        return $ret;
    }

    protected function callGet($url)
    {
        $response = \Httpful\Request::get($url)
            ->expectsJson()
            ->send();

        if (404 == $response->code) {
            // no login gives a 404
            throw new \Exception('Error: 404 for ' . $url . ' (book might not be public)');
        }

        return $response;
    }

    protected function callPost($url, $params)
    {
        // if $params is an array - maybe adjust to other data
        return \Httpful\Request::post($url)
            ->body($params, \Httpful\Mime::FORM)
            ->expectsJson()
            ->send();
    }

    public function getBookInfo()
    {
        $bookUrl = sprintf('%s%s',
                           $this->config['baseurl'],
                           $this->config['book']);

        try {
            $response = $this->callGet($bookUrl . '/rdf/?rec=0&format=json');
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        if (!array_key_exists($bookUrl, $response->body)) {
            return [];
        }

        $info = $this->lookupProperties($response->body[$bookUrl], [
            'http://purl.org/dc/terms/title' => 'dcterms:title',
            'http://purl.org/dc/terms/created' => 'dcterms:created',
            'http://scalar.usc.edu/2012/01/scalar-ns#urn' => 'scalar:urn',
        ] + $this->additionalProperties);

        return $info;
    }

    public function getPage($slug)
    {
        $pageUrl = sprintf('%s%s/%s',
                           $this->config['baseurl'],
                           $this->config['book'],
                           $slug);

        try {
            $response = $this->callGet($pageUrl . '.rdf?rec=0&format=json');
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        $versionUrl = null;
        foreach ($response->body as $url => $info) {
            if ($url == $pageUrl) {
                foreach ($info['http://purl.org/dc/terms/hasVersion'] as $version) {
                    $versionUrl = $version['value'];
                }
            }
            else if (!is_null($versionUrl) && $versionUrl == $url) {
                $page = [ 'url' => $pageUrl ]
                    + $this->lookupProperties($info, [
                        'http://open.vocab.org/terms/versionnumber' => 'ov:versionnumber',
                        'http://purl.org/dc/terms/title' => 'dcterms:title',
                        'http://purl.org/dc/terms/description' => 'dcterms:description',
                        'http://rdfs.org/sioc/ns#content' => 'sioc:content',
                        'http://simile.mit.edu/2003/10/ontologies/artstor#url' => 'art:url',
                        'http://purl.org/dc/terms/created' => 'dcterms:created',
                        'http://scalar.usc.edu/2012/01/scalar-ns#urn' => 'scalar:urn',
                    ] + $this->additionalProperties);

                return $page;
            }
        }

        return [];
    }

    public function updatePage($page, $type = 'page')
    {
        $bookInfo = $this->getBookInfo();
        if (empty($bookInfo)) {
            return false;
        }

        $apiUrl = sprintf('%s%s/api/update',
                          $this->config['baseurl'], $this->config['book']);

        $params = [
            // general api
            'action' => 'UPDATE',
            'id' => $this->config['id'],
            'api_key' => $this->config['api_key'],

            // this action
            'rdf:type' => 'media' == $type
                ? 'http://scalar.usc.edu/2012/01/scalar-ns#Media'
                : 'http://scalar.usc.edu/2012/01/scalar-ns#Composite',
            'urn:scalar:book' => $bookInfo['scalar:urn'],
            'scalar:urn' => $page['scalar:urn'],

            // actual content
            'dcterms:title' => $page['dcterms:title'],
            'dcterms:description' => !empty($page['dcterms:description']) ? $page['dcterms:description'] : '',
            'sioc:content' => !empty($page['sioc:content']) ? $page['sioc:content'] : '',
        ];

        $additionalProperties = array_values($this->additionalProperties);

        if ('media' == $type) {
            $additionalProperties = array_merge($additionalProperties,
                                                [ 'scalar:metadata:url', 'scalar:metadata:thubmnail' ]);
        }

        foreach ($additionalProperties as $key) {
            if (array_key_exists($key, $page)) {
                $params[$key] = $page[$key];
            }
        }

        try {
            $response = $this->callPost($apiUrl, $params);
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        $urls = array_keys($response->body);
        if (count($urls) > 0) {
            $versionUrl = $urls[0];
            $info = $response->body[$versionUrl];

            $page = [ 'url' => $page['url'] ] + $this->lookupProperties($info, [
                'http://open.vocab.org/terms/versionnumber' => 'ov:versionnumber',
                'http://purl.org/dc/terms/title' => 'dcterms:title',
                'http://purl.org/dc/terms/description' => 'dcterms:description',
                'http://rdfs.org/sioc/ns#content' => 'sioc:content',
                'http://simile.mit.edu/2003/10/ontologies/artstor#url' => 'art:url',
                'http://purl.org/dc/terms/created' => 'dcterms:created',
                'http://scalar.usc.edu/2012/01/scalar-ns#urn' => 'scalar:urn',
            ]);

            return $page;
        }

        return [];
    }

    public function addPage($page, $type = 'page')
    {
        $bookInfo = $this->getBookInfo();
        if (empty($bookInfo)) {
            return false;
        }

        $apiUrl = sprintf('%s%s/api/add',
                          $this->config['baseurl'], $this->config['book']);

        $params = [
            // general api
            'action' => 'ADD',
            'id' => $this->config['id'],
            'api_key' => $this->config['api_key'],

            // this action
            'rdf:type' => 'media' == $type
                ? 'http://scalar.usc.edu/2012/01/scalar-ns#Media'
                : 'http://scalar.usc.edu/2012/01/scalar-ns#Composite',
            'urn:scalar:book' => $bookInfo['scalar:urn'],
            'scalar:child_urn' => $bookInfo['scalar:urn'],
            'scalar:child_type' => 'http://scalar.usc.edu/2012/01/scalar-ns#Book',
            'scalar:child_rel' => 'page',

            'scalar:metadata:slug' => $page['scalar:metadata:slug'],

            // actual content
            'dcterms:title' => $page['dcterms:title'],
            'dcterms:description' => !empty($page['dcterms:description']) ? $page['dcterms:description'] : '',
            'sioc:content' => !empty($page['sioc:content']) ? $page['sioc:content'] : '',
        ];

        $additionalProperties = array_values($this->additionalProperties);

        if ('media' == $type) {
            $additionalProperties = array_merge($additionalProperties,
                                                [ 'scalar:metadata:url', 'scalar:metadata:thubmnail' ]);
        }

        foreach ($additionalProperties as $key) {
            if (array_key_exists($key, $page)) {
                $params[$key] = $page[$key];
            }
        }

        try {
            $response = $this->callPost($apiUrl, $params);
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        if (array_key_exists('error', $response->body)) {
            echo join("\n", array_map(function ($composite) {
                return $composite['value'];
            }, $response->body['error']['message'])) . "\n";

            return false;
        }

        $urls = array_keys($response->body);
        if (count($urls) > 0) {
            $versionUrl = $urls[0];
            $info = $response->body[$versionUrl];

            $page['url'] = sprintf('%s%s/%s',
                                   $this->config['baseurl'], $this->config['book'], $page['scalar:metadata:slug']);

            $page = [ 'url' => $page['url'] ] + $this->lookupProperties($info, [
                'http://open.vocab.org/terms/versionnumber' => 'ov:versionnumber',
                'http://purl.org/dc/terms/title' => 'dcterms:title',
                'http://purl.org/dc/terms/description' => 'dcterms:description',
                'http://rdfs.org/sioc/ns#content' => 'sioc:content',
                'http://simile.mit.edu/2003/10/ontologies/artstor#url' => 'art:url',
                'http://purl.org/dc/terms/created' => 'dcterms:created',
                'http://scalar.usc.edu/2012/01/scalar-ns#urn' => 'scalar:urn',
            ]);

            return $page;
        }

        return [];
    }

    public function relate($urn, $child_urn, $child_rel = 'contained', $options = [])
    {
        $apiUrl = sprintf('%s%s/api/relate',
                          $this->config['baseurl'], $this->config['book']);

        $params = [
            // general api
            'action' => 'RELATE',
            'id' => $this->config['id'],
            'api_key' => $this->config['api_key'],

            // this action
            'scalar:urn' => $urn,
            'scalar:child_urn' => $child_urn,
            'scalar:child_rel' => $child_rel,
        ];

        if ('contained' == $child_rel) {
            $params['scalar:metadata:sort_number'] = array_key_exists('sort_number', $options)
                ? $options['sort_number'] : 0;
        }

        try {
            $response = $this->callPost($apiUrl, $params);
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        return $response->body;
    }

    public function listContent($instance)
    {
        $listUrl = sprintf('%s%s/rdf/instancesof/%s',
                           $this->config['baseurl'],
                           $this->config['book'],
                           $instance);

        try {
            $response = $this->callGet($listUrl . '?rec=0&format=json');
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        // TODO: pick info from $response->body
        return $response->body;
    }

    /**
     * TODO: maybe rename since it is more a listProperties when calling on an individual resource
     */
    public function listRelated($instance, $type = 'path')
    {
        $listUrl = sprintf('%s%s/rdf/node/%s',
                           $this->config['baseurl'],
                           $this->config['book'],
                           $instance);


        try {
            // to determine this url, use http://scalar.usc.edu/tools/apiexplorer/
            $response = $this->callGet($listUrl . '?rec=1&res=' . $type . '&ref=0&format=json');
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        // TODO: pick info from $response->body
        return $response->body;
    }

    /**
     * This call needs patched scalar instance (regular cannot use api_key for upload)
     *
     * Alternative would be copy file and thumbnail directly into media-folder
     *
     */
    public function upload($fname)
    {
        $uploadUrl = sprintf('%s%s/upload',
                             $this->config['baseurl'], $this->config['book']);

        $params = [
            // general api
            'action' => 'add',
            'id' => $this->config['id'],
            'api_key' => $this->config['api_key'],

            // upload specific
            'native' => 1,
            'slug_prepend' => 'media',
        ];

        try {
            $request = \Httpful\Request::post($uploadUrl)
                ->body($params)
                ;
            $request->attach([
                'source_file' => $fname,
            ]);

            $response = $request->send();
        }
        catch (\Exception $e) {
            echo 'Invalid call: ' . $e->getMessage();

            return false;
        }

        // pick info from $response->body
        $ret = json_decode($response->body, true);
        if (false === $ret) {
            // could not be decoded, so return the string
            $ret = $response->body;
        }

        return $ret;
    }
}

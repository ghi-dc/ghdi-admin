<?php

namespace App\Utils;

/**
 *
 */
class TeiRefProcessor
{
    protected $client;
    protected $volume;
    protected $locale;

    public function __construct(\ExistDbRpc\Client $client = null, $volume = null, $locale = null)
    {
        $this->client = $client;
        $this->volume = $volume;
        $this->locale = $locale;
    }

    protected function unparse_url($parsed_url)
    {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        return "$scheme$user$pass$host$port$path$query$fragment";
    }

    /* from drupal: https://stackoverflow.com/a/39682365/2114681 */
    public function is_absolute($url)
    {
        $pattern = "/^(?:ftp|https?|feed):\/\/(?:(?:(?:[\w\.\-\+!$&'\(\)*\+,;=]|%[0-9a-f]{2})+:)*
        (?:[\w\.\-\+%!$&'\(\)*\+,;=]|%[0-9a-f]{2})+@)?(?:
        (?:[a-z0-9\-\.]|%[0-9a-f]{2})+|(?:\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\]))(?::[0-9]+)?(?:[\/|\?]
        (?:[\w#!:\.\?\+=&@$'~*,;\/\(\)\[\]\-]|%[0-9a-f]{2})*)?$/xi";

        return (bool) preg_match($pattern, $url);
    }

    protected function buildRouteVariables($components)
    {
        $route = null;
        $routeParams = [];

        if (array_key_exists('scheme', $components) && 'javascript' == $components['scheme']) {
            if (preg_match('/^bioinfo\((\d+)\)$/', $components['path'], $matches)) {
                return [ 'bio', [ 'id' => $matches[1] ]];
            }

            var_dump($components);
            die('TODO: handle');
        }

        $queryParts = [];

        if (!empty($components['query'])) {
            parse_str($components['query'], $queryParts);
        }

        if (array_key_exists('language', $queryParts)) {
            if ('german' == $queryParts['language']) {
                $routeParams['_locale'] = 'de';
            }
            else if ('english' == $queryParts['language']) {
                $routeParams['_locale'] = 'en';
            }
        }

        switch ($components['path']) {
            case '/section.cfm':
                $route = 'section';
                $routeParams['id'] = $queryParts['section_id'];
                break;

            case '/sub_document.cfm':
                $route = 'document';
                $routeParams['id'] = $queryParts['document_id'];
                break;

            case '/sub_image.cfm':
                $route = 'image';
                $routeParams['id'] = $queryParts['image_id'];
                break;

            case '/map.cfm':
                $route = 'map';
                $routeParams['id'] = $queryParts['map_id'];
                break;

            // shows in section_id:12
            case '/bioinfo.cfm':
                $route = 'bio';
                $routeParams['id'] = $queryParts['bio_id'];
                break;

            // special for section_id:15
            case '/sub_docs.cfm':
                $route = 'subsections-documents';
                $routeParams['id'] = $queryParts['section_id'];
                break;

            // special for section_id:15
            case '/sub_doclist.cfm':
            case '/sub_imglist.cfm':
                $route = 'subsection-documents';
                $routeParams['id'] = $queryParts['section_id'];
                $routeParams['sub_id'] = $queryParts['sub_id'];
                break;

            // special for section_id:9
            case '/facsimile.cfm':
                $route = 'document';
                $routeParams['id'] = $queryParts['document_id'];
                break;

            default:
                if (preg_match('~^/(de|en)~', $components['path'])) {
                    // already in scheme for new site
                    $route = false; // keep link as is
                }
                else {
                    var_dump($components);
                    die('TODO: handle');
                }
        }

        return [ $route, $routeParams ];
    }

    protected function documentAvailable($uri)
    {
        $xql = <<<EOXQL
    declare variable \$uri external;
    fn:doc-available(\$uri)
EOXQL;

        $query = $this->client->prepareQuery($xql);
        $query->bindVariable('uri', $uri);
        $res = $query->execute();
        $available = 'true' === $res->getNextResult();
        $res->release();

        return $available;
    }

    protected function buildTarget($route, $routeParams)
    {
        switch ($route) {
            case 'document':
            case 'image':
            case 'map':
                $target = sprintf('%s-%d', $route, $routeParams['id']);
                if (!is_null($this->client)) {
                    // check if available within this volume
                    $lang =  \App\Utils\Iso639::code1To3($this->locale);
                    $resourcePath = $this->client->getCollection() . '/' . $this->volume . '/' . $target . '.' . $lang . '.xml';

                    if (!$this->documentAvailable($resourcePath)) {
                        return false;
                    }
                }

                return $target;
        }

        return false;
    }

    public function process($xml)
    {
        foreach ($xml('/tei:TEI//tei:ref[@target]') as $ref) {

            $target = $ref['target'];
            if (empty($target)) {
                continue;
            }

            $components = parse_url($target);
            if ((array_key_exists('scheme', $components) && 'javascript' == $components['scheme'])
                || (array_key_exists('host', $components)
                    && (strpos($components['host'], 'germanhistorydocs') !== false
                        || strpos($components['host'], 'ghdi.ghi-dc.org') !== false)
                ))
            {
                list($route, $routeParameters) = $this->buildRouteVariables($components);
                if (!empty($route)) {
                    $adjusted = $this->buildTarget($route, $routeParameters);
                    if (false !== $adjusted) {
                        $ref->setAttribute('target', $adjusted);
                    }
                }
            }
        };
    }
}

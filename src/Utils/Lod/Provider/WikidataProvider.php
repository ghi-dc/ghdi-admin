<?php

namespace App\Utils\Lod\Provider;

use App\Utils\Lod\Identifier\Identifier;
use App\Utils\Lod\Identifier\GeonamesIdentifier;
use App\Utils\Lod\Identifier\GndIdentifier;
use App\Utils\Lod\Identifier\LocLdsNamesIdentifier;
use App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier;
use App\Utils\Lod\Identifier\ViafIdentifier;
use App\Utils\Lod\Identifier\WikidataIdentifier;

class WikidataProvider extends AbstractProvider
{
    static $WIKIDATA_IDENTIFIERS = [
        'P227' => 'gnd',
        'P214' => 'viaf',
        'P244' => 'lcauth',
        'P1667' => 'tgn',
        'P1566' => 'geonames',
    ];

    private static function getSparqlClient()
    {
        return new \EasyRdf\Sparql\Client('https://query.wikidata.org/sparql');
    }

    protected $name = 'dnb';

    public function __construct()
    {
        \App\Utils\Lod\Identifier\Factory::register(GeonamesIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(GndIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(ViafIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(LocLdsNamesIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(LocLdsSubjectsIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(WikidataIdentifier::class);
    }

    protected function executeSparqlQuery($query, $sparqlClient = null)
    {
        if (is_null($sparqlClient)) {
            $sparqlClient = self::getSparqlClient();
        }

        return $sparqlClient->query($query);
    }

    public function lookup(Identifier $identifier)
    {
        throw new \Exception('No implemented yet');
    }

    protected function lookupQidByProperty($pid, $value, $sparqlClient = null)
    {
        $query = sprintf(
            "SELECT ?wd WHERE { ?wd wdt:%s '%s'. }",
            $pid,
            addslashes($value)
        );

        $result = $this->executeSparqlQuery($query, $sparqlClient);

        $ret = [];
        foreach ($result as $row) {
            $uri = (string) $row->wd;

            if (preg_match('~/(Q\d+)$~', $uri, $matches)) {
                $ret[] = $matches[1];
            }
        }

        return $ret;
    }

    public function lookupSameAs(Identifier $identifier)
    {
        $identifiers = [];

        $name = $identifier->getPrefix();
        if ('wikidata' == $name) {
            $qid = $identifier->getValue();
        }
        else {
            $propertiesByName = array_flip(self::$WIKIDATA_IDENTIFIERS);
            if (array_key_exists($name, $propertiesByName)) {
                $pid = $propertiesByName[$name];
                $qids = $this->lookupQidByProperty($pid, $identifier->getValue());
                if (!empty($qids)) {
                    $qid = $qids[0];

                    $identifiers[] = new WikidataIdentifier($qid);
                }
            }
        }

        if (!empty($qid)) {
            $unionParts = [];
            foreach (self::$WIKIDATA_IDENTIFIERS as $pid => $name) {
                $unionParts[] = sprintf(
                    '{ wd:%s wdt:%s ?property. BIND("%s" as ?propertyId) }',
                    $qid,
                    $pid,
                    $pid
                );
            }

            $query = 'SELECT ?property ?propertyId WHERE {'
                . implode(' UNION ', $unionParts)
                . '}';

            $result = $this->executeSparqlQuery($query);

            foreach ($result as $row) {
                $propertyId = (string) $row->propertyId;
                $propertyValue = $row->property;
                if ($propertyValue instanceof \EasyRdf\Literal) {
                    $propertyValue = $propertyValue->getValue();
                }

                if (!empty($propertyValue)) {
                    $name = self::$WIKIDATA_IDENTIFIERS[$propertyId];
                    if ('lcauth' == $name) {
                        // can be lcsh or lcnaf,
                        if (preg_match('/^sh/', $propertyValue)) {
                            $name = 'lcsh';
                        }
                        else if (preg_match('/^n/', $propertyValue)) {
                            $name = 'lcnaf';
                        }
                    }

                    $identifier = \App\Utils\Lod\Identifier\Factory::byName($name);
                    if (!is_null($identifier)) {
                        $identifier->setValue($propertyValue);

                        $identifiers[] = $identifier;
                    }
                }
            }

            return $identifiers;
        }
    }
}

<?php
namespace App\Utils;

trait WikidataTrait
{
    static $WIKIDATA_IDENTIFIERS = [
        'P227' => 'gnd',
        'P214' => 'viaf',
        'P244' => 'lccn',
    ];

    public function getSparqlClient()
    {
        return new \EasyRdf_Sparql_Client('https://query.wikidata.org/sparql');
    }

    protected function executeSparqlQuery($query, $sparqlClient = null)
    {
        if (is_null($sparqlClient)) {
            $sparqlClient = $this->getSparqlClient();
        }

        return $sparqlClient->query($query);
    }

    protected function validateQid($qid)
    {
        return preg_match('/^Q\d+$/', $qid);
    }

    protected function validateProperty($pid)
    {
        return preg_match('/^P\d+$/', $pid);
    }

    public function lookupPropertyByQid($qid, $pid, $sparqlClient = null)
    {
        if (!$this->validateQid($qid)) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid QID'));
        }

        if (!$this->validateProperty($pid)) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid Property'));
        }

        $query = sprintf('SELECT ?property WHERE { wd:%s wdt:%s ?property. }',
                         $qid, $pid);

        $result = $this->executeSparqlQuery($query, $sparqlClient);

        $ret = [];
        foreach ($result as $row) {
            $property = $row->property;
            if ($property instanceOf \EasyRdf_Literal) {
                $property = $property->getValue();
            }

            $ret[] = $property;
        }

        return $ret;
    }

    public function lookupPropertiesByQid($qid, $pids, $sparqlClient = null)
    {
        if (!$this->validateQid($qid)) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid QID'));
        }

        if (empty($pids)) {
            return [];
        }

        $unionParts = [];
        foreach ($pids as $pid) {
            if (!$this->validateProperty($pid)) {
                throw new \InvalidArgumentException(sprintf('%s is not a valid Property'));
            }

            $unionParts[] = sprintf('{ wd:%s wdt:%s ?property. BIND("%s" as ?propertyId) }',
                                    $qid, $pid, $pid);
        }

        $query = 'SELECT ?property ?propertyId WHERE {'
            . implode(' UNION ', $unionParts)
            . '}';


        $result = $this->executeSparqlQuery($query, $sparqlClient);

        $ret = [];
        foreach ($result as $row) {
            $property = $row->property;
            if ($property instanceOf \EasyRdf_Literal) {
                $property = $property->getValue();
            }

            $ret[(string)$row->propertyId] = $property;
        }

        return $ret;
    }

    public function lookupQidByProperty($pid, $value, $sparqlClient = null)
    {
        if (!$this->validateProperty($pid)) {
            throw new \InvalidArgumentException(sprintf('%s is not a valid Property', $pid));
        }

        $query = sprintf("SELECT ?wd WHERE { ?wd wdt:%s '%s'. }",
                         $pid, addslashes($value));

        $result = $this->executeSparqlQuery($query, $sparqlClient);

        $ret = [];
        foreach ($result as $row) {
            $uri = (string)$row->wd;

            if (preg_match('~/(Q\d+)$~', $uri, $matches)) {
                $ret[] = $matches[1];
            }
        }

        return $ret;
    }

    public function lookupGndByQid($qid, $sparqlClient = null)
    {
        return $this->lookupPropertyByQid($qid, 'P227', $sparqlClient);
    }

    public function lookupLccnByQid($qid, $sparqlClient = null)
    {
        return $this->lookupPropertyByQid($qid, 'P244', $sparqlClient);
    }

    public function lookupViafByQid($qid, $sparqlClient = null)
    {
        return $this->lookupPropertyByQid($qid, 'P214', $sparqlClient);
    }

    public function lookupIdentifiersByQid($qid, $sparqlClient = null)
    {
        $res = $this->lookupPropertiesByQid($qid, array_keys(self::$WIKIDATA_IDENTIFIERS), $sparqlClient);

        $identifiers = [];
        if (is_array($res)) {
            foreach ($res as $src => $val) {
                if (array_key_exists($src, self::$WIKIDATA_IDENTIFIERS)) {
                    $identifiers[self::$WIKIDATA_IDENTIFIERS[$src]] = $val;
                }
            }
        }

        return $identifiers;
    }

    public function lookupQidByGnd($value, $sparqlClient = null)
    {
        return $this->lookupQidByProperty('P227', $value, $sparqlClient);
    }
}

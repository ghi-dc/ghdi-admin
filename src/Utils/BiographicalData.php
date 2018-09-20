<?php
namespace App\Utils;

class BiographicalData
extends DnbData
{
    use WikidataTrait;

    function processTriple($triple)
    {
        switch ($triple['p']) {
            case 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type':
                $this->isDifferentiated =
                    !in_array($triple['o'],
                             [
                               'http://d-nb.info/standards/elementset/gnd#UndifferentiatedPerson',
                               ]);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#dateOfBirth':
            case 'dateOfBirth':
                $this->dateOfBirth = $triple['o'];
                break;

            case 'http://d-nb.info/standards/elementset/gnd#placeOfBirth':
            case 'placeOfBirth':
                $placeOfBirth = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfBirth)) {
                    $this->placeOfBirth = $placeOfBirth;
                }
                break;

            case 'http://d-nb.info/standards/elementset/gnd#placeOfActivity':
            case 'placeOfActivity':
                $placeOfActivity = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfActivity)) {
                    $this->placeOfActivity = $placeOfActivity;
                }
                break;

            case 'http://d-nb.info/standards/elementset/gnd#dateOfDeath':
            case 'dateOfDeath':
                $this->dateOfDeath = $triple['o'];
                break;

            case 'http://d-nb.info/standards/elementset/gnd#placeOfDeath':
            case 'placeOfDeath':
                $placeOfDeath = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfDeath))
                    $this->placeOfDeath = $placeOfDeath;
                break;

            case 'http://d-nb.info/standards/elementset/gnd#forename':
            case 'forename':
                $this->forename = self::normalizeString($triple['o']);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#surname':
            case 'surname':
                $this->surname = self::normalizeString($triple['o']);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#preferredNameForThePerson':
            case 'preferredNameForThePerson':
                if (!isset($this->preferredName) && 'literal' == $triple['o_type']) {
                    $this->preferredName = self::normalizeString($triple['o']);
                }
                else if ('bnode' == $triple['o_type']) {
                    $nameRecord = $index[$triple['o']];
                    $this->preferredName = array(self::normalizeString($nameRecord['http://d-nb.info/standards/elementset/gnd#surname'][0]['value']),
                                                self::normalizeString($nameRecord['http://d-nb.info/standards/elementset/gnd#forename'][0]['value']));
                    // var_dump($index[$triple['o']]);
                }
                break;

            case 'http://d-nb.info/standards/elementset/gnd#academicDegree':
            case 'academicDegree':
                $this->academicDegree = self::normalizeString($triple['o']);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#biographicalOrHistoricalInformation':
            case 'biographicalOrHistoricalInformation':
                $this->biographicalInformation = self::normalizeString($triple['o']);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#professionOrOccupation':
            case 'professionOrOccupation':
                // TODO: links to external resource
                break;

            case 'http://d-nb.info/standards/elementset/gnd#variantNameForThePerson':
            case 'variantNameForThePerson':
                // var_dump($triple);
                break;

            default:
                if (!empty($triple['o'])) {
                    // var_dump($triple);
                }
                // var_dump($triple['p']);
        }
    }

    var $gnd;
    var $isDifferentiated = false;
    var $preferredName;
    var $academicDegree;
    var $biographicalInformation;
    var $dateOfBirth;
    var $placeOfBirth;
    var $placeOfActivity;
    var $dateOfDeath;
    var $placeOfDeath;

    static function lookupPersonByGnd($gnd)
    {
        $bio = \App\Utils\BiographicalData::fetchByGnd($gnd);

        if (is_null($bio) || !($bio instanceof BiographicalData) || !$bio->isDifferentiated) {
            return;
        }

        $person = new \App\Entity\Person();
        $person->setGnd($gnd);

        // TODO: use hydrator
        foreach ([
                'surname',
                'forename',
                'dateOfBirth',
                'dateOfDeath',
                'biographicalInformation',
            ] as $src)
        {
            if (!empty($bio->{$src})) {
                switch ($src) {
                    case 'surname':
                        $person->setFamilyName($bio->{$src});
                        break;

                    case 'forename':
                        $person->setGivenName($bio->{$src});
                        break;

                    case 'dateOfBirth':
                        $person->setBirthDate($bio->{$src});
                        break;

                    case 'dateOfDeath':
                        $person->setDeathDate($bio->{$src});
                        break;

                    case 'biographicalInformation':
                        $person->setDisambiguatingDescription('de', $bio->{$src});
                        break;
                }
            }
        }

        // let's see if we find a matching Wikidata-QID
        $qid = $bio->lookupQidByGnd($person->getGnd());
        if (1 == count($qid)) {
            $person->setWikidata($wikidataQid = $qid[0]);

            // lookup additional identifiers
            $identifiers = $bio->lookupIdentifiersByQid($wikidataQid);

            foreach ($identifiers as $name => $val) {
                $current = $person->getIdentifier($name);
                if (empty($current)) {
                    $person->setIdentifier($name, $val);
                }
            }
        }

        return $person;
    }
}

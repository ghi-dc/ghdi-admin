<?php
namespace App\Utils;

class CorporateBodyData
extends DnbData
{
    use WikidataTrait;

    function processTriple($triple)
    {
        switch ($triple['p']) {
            case 'http://www.w3.org/1999/02/22-rdf-syntax-ns#type':
                $this->isDifferentiated = true; // 'http://d-nb.info/standards/elementset/gnd#DifferentiatedPerson' == $triple['o'];
                break;

            case 'http://d-nb.info/standards/elementset/gnd#dateOfEstablishment':
                $this->dateOfEstablishment = $triple['o'];
                break;

            /*
            case 'http://d-nb.info/standards/elementset/gnd#placeOfBirth':
                $placeOfBirth = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfBirth))
                    $this->placeOfBirth = $placeOfBirth;
                break;
            */

            case 'http://d-nb.info/standards/elementset/gnd#placeOfBusiness':
                $placeOfBusiness = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfBusiness)) {
                    $this->placeOfBusiness = $placeOfBusiness;
                }
                break;

            case 'http://d-nb.info/standards/elementset/gnd#dateOfTermination':
                $this->dateOfTermination = $triple['o'];
                break;

            /*
            case 'http://d-nb.info/standards/elementset/gnd#placeOfDeath':
                $placeOfDeath = self::fetchGeographicLocation($triple['o']);
                if (!empty($placeOfDeath))
                    $this->placeOfDeath = $placeOfDeath;
                break;
            */

            case 'http://d-nb.info/standards/elementset/gnd#preferredNameForTheCorporateBody':
                if (!isset($this->preferredName) && 'literal' == $triple['o_type'])
                    $this->preferredName = self::normalizeString($triple['o']);
                /*
                else if ('bnode' == $triple['o_type']) {
                    $nameRecord = $index[$triple['o']];
                    $this->preferredName = array($nameRecord['http://d-nb.info/standards/elementset/gnd#surname'][0]['value'],
                                                $nameRecord['http://d-nb.info/standards/elementset/gnd#forename'][0]['value']);
                    // var_dump($index[$triple['o']]);
                }
                */
                break;

            case 'http://d-nb.info/standards/elementset/gnd#homepage':
                $this->homepage = $triple['o'];
                break;

            case 'http://d-nb.info/standards/elementset/gnd#biographicalOrHistoricalInformation':
                $this->biographicalInformation = self::normalizeString($triple['o']);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#variantNameForTheCorporateBody':
                // var_dump($triple);
                break;

            case 'http://d-nb.info/standards/elementset/gnd#hierarchicalSuperiorOfTheCorporateBody':
            case 'http://d-nb.info/standards/elementset/gnd#precedingCorporateBody':
                break;

            default:
                if (!empty($triple['o'])) {
                    // var_dump($triple);
                }
                // var_dump($triple['p']);
        }
    }

    var $gnd;
    var $isDifferentiated = true;
    var $preferredName;
    var $dateOfEstablishment;
    // var $placeOfBirth;
    var $placeOfBusiness;
    var $dateOfTermination;
    // var $placeOfDeath;
    var $homepage;


    static function lookupOrganizationByGnd($gnd)
    {
        $corporateBody = \App\Utils\CorporateBodyData::fetchByGnd($gnd);

        if (is_null($corporateBody) || !($corporateBody instanceof CorporateBodyData)) {
            return;
        }

        $organization = new \App\Entity\Organization();
        $organization->setGnd($gnd);

        // TODO: use hydrator
        foreach ([
                'preferredName',
                'dateOfEstablishment',
                'dateOfTermination',
                'homepage',
            ] as $src)
        {
            if (!empty($corporateBody->{$src})) {
                switch ($src) {
                    case 'preferredName':
                        $organization->setName($corporateBody->{$src});
                        break;

                    case 'dateOfEstablishment':
                        $organization->setFoundingDate($corporateBody->{$src});
                        break;

                    case 'dateOfEstablishment':
                        $organization->setDissolutionDate($corporateBody->{$src});
                        break;

                    case 'homepage':
                        $organization->setUrl($corporateBody->{$src});
                        break;
                }
            }
        }

        // let's see if we find a matching Wikidata-QID
        $qid = $corporateBody->lookupQidByGnd($organization->getGnd());
        if (1 == count($qid)) {
            $organization->setWikidata($qid[0]);
        }

        return $organization;
    }
}

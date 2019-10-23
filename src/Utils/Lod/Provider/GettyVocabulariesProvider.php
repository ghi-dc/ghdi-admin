<?php

namespace App\Utils\Lod\Provider;

use App\Utils\Lod\Identifier\Identifier;
use App\Utils\Lod\Identifier\TgnIdentifier;

class GettyVocabulariesProvider
extends AbstractProvider
implements PlaceProvider
{
    const ENDPOINT = 'http://vocab.getty.edu/sparql.json';

    protected $name = 'getty';

    public function lookup(Identifier $identifier)
    {
        if ($identifier instanceof TgnIdentifier) {
            return $this->lookupPlaceEntity($identifier);
        }

        throw InvalidArgumentException('Expecting a TgnIdentifier');
    }

    protected function lookupPlaceEntity($identifier)
    {
        $sparql = new \EasyRdf\Sparql\Client(self::ENDPOINT);

        $uri = sprintf('tgn:%d', $tgn = $identifier->getValue());

        // for optional english-label see http://vocab.getty.edu/queries#Places_with_English_or_GVP_Label
        // TODO: get sameAs

        $query = <<<EOT
SELECT ?Subject ?name ?nameEn ?nameDe ?type ?isoalpha3 ?parent ?parentString ?ancestor ?ancestorIsoalpha3 ?latitude ?longitude
{
    BIND({$uri} as ?Subject)

    ?Subject a gvp:Subject;

    gvp:prefLabelGVP/xl:literalForm ?name;

    gvp:placeTypePreferred/gvp:prefLabelGVP/xl:literalForm ?type;

    gvp:parentString ?parentString.

    OPTIONAL {
        ?Subject
            xl:prefLabel [
                xl:literalForm ?nameEn;
                dct:language gvp_lang:en
            ]
    }

    OPTIONAL {
        ?Subject
            xl:prefLabel [
                xl:literalForm ?nameDe;
                dct:language gvp_lang:de
            ]
    }

    OPTIONAL {
        ?Subject
            foaf:focus [
                wgs:lat ?latitude;
                wgs:long ?longitude
            ]
    }.

    OPTIONAL {
        ?Subject xl:altLabel ?altLabel.
        ?altLabel gvp:termKind <http://vocab.getty.edu/term/kind/ISOalpha3>;
            gvp:term ?isoalpha3.
    }.

    OPTIONAL {
        ?Subject
            gvp:broaderPreferred ?parent.
    }.

    OPTIONAL {
        ?Subject gvp:broaderPreferred+ ?ancestor.

        ?ancestor xl:altLabel ?altLabel.

        ?altLabel gvp:termKind <http://vocab.getty.edu/term/kind/ISOalpha3>;
            gvp:term ?ancestorIsoalpha3.
    }
}

EOT;

        $entity = null;

        $result = $sparql->query($query);
        if (count($result) > 0) {
            foreach ($result as $row) {
                $entity = new \App\Entity\Place();
                $entity->setTgn($tgn);

                $geoCoordinates = new \App\Entity\GeoCoordinates();
                $geoCoordinatesSet = false;

                foreach ([
                    'name' => 'name',
                    'nameEn' => 'nameEn',
                    'nameDe' => 'nameDe',
                    'type' => 'additionalType',
                    // 'parentString' => 'parentString',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                ] as $src => $target)
                {
                    if (property_exists($row, $src)) {
                        $property = $row->$src;
                        $method = 'set' . ucfirst($target);

                        switch ($target) {
                            case 'longitude':
                            case 'latitude':
                                $geoCoordinates->$method((string)$property);
                                $geoCoordinatesSet = true;
                                break;

                            default:
                                $entity->$method((string)$property);
                        }
                    }
                }

                if (property_exists($row, 'parent')) {
                    $parentIdentifier = new TgnIdentifier((string)($row->parent));

                    $parent = new \App\Entity\Place();
                    $parent->setTgn($parentIdentifier->getValue());

                    $entity->setContainedInPlace($parent);
                }

                $code = null;
                $isoAlpha2 = null;
                if (property_exists($row, 'isoalpha3')) {
                    $code = (string)($row->isoalpha3);
                }

                if (empty($code) && property_exists($row, 'ancestorIsoalpha3')) {
                    $code = (string)($row->ancestorIsoalpha3);
                }

                if (!empty($code)) {
                    $iso3166 = new \League\ISO3166\ISO3166();
                    try {
                        $data = $iso3166->alpha3($code);
                        if (!empty($data['alpha2'])) {
                            $isoAlpha2 = $data['alpha2'];
                        }
                    }
                    catch (\Exception $e) {
                        ; // ignore
                    }
                }

                if (!empty($isoAlpha2)) {
                    $geoCoordinates->setAddressCountry($isoAlpha2);
                    $geoCoordinatesSet = true;
                }

                if ($geoCoordinatesSet) {
                    $entity->setGeo($geoCoordinates);
                }

                break; // only pickup first $result
            }
        }

        return $entity;
    }
}

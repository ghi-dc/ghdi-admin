<?php
namespace App\Utils;

/*
 * GeographicalDataTgn.php
 *
 * Lookup Geographic Name by TGN-identifier
 *
 * (c) 2017 daniel.burckhardt@sur-gmbh.ch
 *
 * Version: 2017-08-17 dbu
 *
 *
 */

class GeographicalDataTgn
extends GeographicalData
{
    const ENDPOINT = 'http://vocab.getty.edu/sparql.json';

    static function fetchByTgn($tgn)
    {
        $sparql = new \EasyRdf_Sparql_Client(self::ENDPOINT);

        $uri = sprintf('tgn:%d', $tgn);

        // for optional english-label see http://vocab.getty.edu/queries#Places_with_English_or_GVP_Label

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

        $result = $sparql->query($query);
        if (count($result) > 0) {
            foreach ($result as $row) {
                $geo = new GeographicalDataTgn();
                $geo->tgn = $tgn;
                foreach ([
                    'name' => 'preferredName',
                    'nameEn' => 'variantNameEn',
                    'nameDe' => 'variantNameDe',
                    'type' => 'type',
                    'parentString' => 'parentString',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                ] as $src => $target)
                {
                    if (property_exists($row, $src)) {
                        $property = $row->$src;
                        $geo->$target = (string)$property;
                    }
                }

                $code = null;
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
                            $geo->isoAlpha2 = $data['alpha2'];
                        }
                    }
                    catch (\Exception $e) {
                        ; // ignore
                    }
                }

                if (property_exists($row, 'parent')) {
                    $uri = (string)($row->parent);
                    if (preg_match('/(\d+)$/', $uri, $matches)) {
                        $geo->tgnParent = $matches[1];
                    }
                }
                break; // only pickup first $result
            }
        }

        return $geo;
    }

    static function lookupPlaceByTgn($tgn)
    {
        $geo = \App\Utils\GeographicalDataTgn::fetchByTgn($tgn);

        if (is_null($geo)) {
            return;
        }

        $place = new \App\Entity\Place();
        $place->setTgn($tgn);

        $geoCoordinates = new \App\Entity\GeoCoordinates();
        $geoCoordinatesSet = false;

        $place->setGeo($geoCoordinates);

        // TODO: use hydrator
        foreach ([
                'preferredName',
                'variantNameDe',
                'variantNameEn',
                'type',
                'longitude',
                'latitude',
                'isoAlpha2',
                'tgnParent',
            ] as $src)
        {
            if (!empty($geo->{$src})) {
                switch ($src) {
                    case 'preferredName':
                        $place->setName($geo->{$src});
                        break;

                    case 'variantNameDe':
                        $place->setNameDe($geo->{$src});
                        break;

                    case 'variantNameEn':
                        $place->setNameEn($geo->{$src});
                        break;

                    case 'type':
                        $place->setAdditionalType($geo->{$src});
                        break;

                    case 'longitude':
                        $geoCoordinates
                            ->setLongitude($geo->{$src});
                        $geoCoordinatesSet = true;
                        break;

                    case 'latitude':
                        $geoCoordinates
                            ->setLatitude($geo->{$src});
                        $geoCoordinatesSet = true;
                        break;

                    case 'isoAlpha2';
                        $geoCoordinates
                            ->setAddressCountry($geo->{$src});
                        $geoCoordinatesSet = true;
                        break;

                    case 'tgnParent':
                        $parent = new \App\Entity\Place();
                        $parent->setTgn($geo->{$src});

                        $place->setContainedInPlace($parent);
                }
            }
        }

        if ($geoCoordinatesSet) {
            $place->setGeo($geoCoordinates);
        }

        return $place;
    }

    var $tgn;
    var $tgnParent;
}

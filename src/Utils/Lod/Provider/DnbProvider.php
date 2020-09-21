<?php

namespace App\Utils\Lod\Provider;

use App\Utils\Lod\Identifier\Identifier;
use App\Utils\Lod\Identifier\GeonamesIdentifier;
use App\Utils\Lod\Identifier\GndIdentifier;
use App\Utils\Lod\Identifier\LocLdsAgentsIdentifier;
use App\Utils\Lod\Identifier\ViafIdentifier;
use App\Utils\Lod\Identifier\WikidataIdentifier;

class DnbProvider
extends AbstractProvider
implements PersonProvider, OrganizationProvider, PlaceProvider, TermProvider
{
    protected $name = 'dnb';

    public function __construct()
    {
        \App\Utils\Lod\Identifier\Factory::register(GeonamesIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(GndIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(ViafIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(LocLdsAgentsIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(WikidataIdentifier::class);

        \EasyRdf\RdfNamespace::set('gndo', 'https://d-nb.info/standards/elementset/gnd#');
        \EasyRdf\RdfNamespace::set('owl', 'http://www.w3.org/2002/07/owl#');
    }

    public function lookup(Identifier $identifier)
    {
        if (!($identifier instanceof GndIdentifier)) {
            throw new \InvalidArgumentException('Expecting a GndIdentifier');
        }

        return $this->buildEntityFromUri($identifier->toUri());
    }

    protected function buildEntityFromUri($uri)
    {
        // $uri . '/about/lds' would give ttl representation
        // see https://www.dnb.de/SharedDocs/Downloads/DE/Professionell/Metadatendienste/linkedDataZugriff.pdf?__blob=publicationFile&v=2
        $rdfUrl = $uri . '/about/rdf';

        try {
            $graph = \EasyRdf\Graph::newAndLoad($rdfUrl);
        }
        catch (\EasyRdf\Http\Exception $e) {
            throw new \InvalidArgumentException($e->getMessage());
        }

        $resource = $graph->resource($uri);

        switch ($type = $resource->get('rdf:type')) {
            case 'https://d-nb.info/standards/elementset/gnd#DifferentiatedPerson':
                return $this->instantiatePersonFromResource($resource);
                break;

            case 'https://d-nb.info/standards/elementset/gnd#Company':
            case 'https://d-nb.info/standards/elementset/gnd#CorporateBody':
                return $this->instantiateOrganizationFromResource($resource);
                break;

            case 'https://d-nb.info/standards/elementset/gnd#TerritorialCorporateBodyOrAdministrativeUnit':
                return $this->instantiatePlaceFromResource($resource);
                break;

            case 'https://d-nb.info/standards/elementset/gnd#SubjectHeading':
            case 'https://d-nb.info/standards/elementset/gnd#SubjectHeadingSensoStricto':
            case 'https://d-nb.info/standards/elementset/gnd#HistoricSingleEventOrEra';
            case 'https://d-nb.info/standards/elementset/gnd#EthnographicName':
            case 'https://d-nb.info/standards/elementset/gnd#NomenclatureInBiologyOrChemistry':
                return $this->instantiateTermFromResource($resource);
                break;

            default:
                throw new \Exception('No handler for rdf:type ' . $type);
        }
    }

    protected function instantiatePersonFromResource($resource)
    {
        $entity = new \App\Entity\Person();
        $entity->setIdentifier('gnd', (string)$resource->get('gndo:gndIdentifier'));

        $preferredName = $resource->get('gndo:preferredNameEntityForThePerson');
        if (!is_null($preferredName)) {
            $this->setEntityFromResource($entity, $preferredName, [
                'gndo:forename' => 'givenName',
                'gndo:surname' => 'familyName',
            ]);
        }

        $this->setEntityFromResource($entity, $resource, [
            'gndo:dateOfBirth' => 'birthDate',
            'gndo:dateOfDeath' => 'deathDate',
        ]);

        $gender = $resource->get('gndo:gender');
        if (!is_null($gender)) {
            switch ($gender->getUri()) {
                case 'https://d-nb.info/standards/vocab/gnd/gender#female':
                    $this->setEntityValues($entity, [ 'gender' => 'Female' ]);
                    break;

                case 'https://d-nb.info/standards/vocab/gnd/gender#male':
                    $this->setEntityValues($entity, [ 'gender' => 'Male' ]);
                    break;
            }
        }

        foreach ([
            'gndo:placeOfBirth' => 'birthPlace',
            'gndo:placeOfDeath' => 'deathPlace',
            ] as $key => $property)
        {
            $subresource = $resource->get($key);
            if (!is_null($subresource)) {
                if ($subresource instanceof \EasyRdf\Resource) {
                    try {
                        $subentity = $this->buildEntityFromUri($subresource->getUri());
                        if (!is_null($entity)) {
                            $this->setEntityValues($entity, [ $property => $subentity ]);
                        }
                    }
                    catch (\Exception $e) {
                        var_dump($e);
                    }
                }
            }
        }

        $this->setDisambiguatingDescription($entity, $resource, 'gndo:biographicalOrHistoricalInformation');

        $this->processSameAs($entity, $resource);

        return $entity;
    }

    protected function instantiateOrganizationFromResource($resource)
    {
        $entity = new \App\Entity\Organization();
        $entity->setIdentifier('gnd', (string)$resource->get('gndo:gndIdentifier'));

        $this->setEntityFromResource($entity, $resource, [
            'gndo:preferredNameForTheCorporateBody' => 'name',
            'gndo:homepage' => 'url',
            'gndo:dateOfEstablishment' => 'foundingDate',
            'gndo:dateOfTermination' => 'dissolutionDate',
        ]);

        foreach ([
            'gndo:placeOfBusiness' => 'location',
            ] as $key => $property)
        {
            $subresource = $resource->get($key);
            if (!is_null($subresource)) {
                if ($subresource instanceof \EasyRdf\Resource) {
                    try {
                        $subentity = $this->buildEntityFromUri($subresource->getUri());
                        if (!is_null($subentity)) {
                            $this->setEntityValues($entity, [ $property => $subentity ]);
                        }
                    }
                    catch (\Exception $e) {
                        var_dump($e);
                    }
                }
            }
        }

        $this->setDisambiguatingDescription($entity, $resource, 'gndo:biographicalOrHistoricalInformation');

        $this->processSameAs($entity, $resource);

        return $entity;
    }

    protected function instantiatePlaceFromResource($resource)
    {
        $entity = new \App\Entity\Place();
        $entity->setIdentifier('gnd', (string)$resource->get('gndo:gndIdentifier'));

        $this->setEntityFromResource($entity, $resource, [
            'gndo:preferredNameForThePlaceOrGeographicName' => 'name',
        ]);

        $this->setDisambiguatingDescription($entity, $resource, 'gndo:biographicalOrHistoricalInformation');

        $this->processSameAs($entity, $resource);

        return $entity;
    }

    protected function instantiateTermFromResource($resource)
    {
        $entity = new \App\Entity\Term();
        $entity->setIdentifier('gnd', (string)$resource->get('gndo:gndIdentifier'));

        $this->setEntityFromResource($entity, $resource, [
            'gndo:preferredNameForTheSubjectHeading' => 'name',
        ]);

        $this->setDisambiguatingDescription($entity, $resource, 'gndo:definition');

        $this->processSameAs($entity, $resource);

        return $entity;
    }
}

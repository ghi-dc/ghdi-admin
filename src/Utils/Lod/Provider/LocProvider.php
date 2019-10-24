<?php

namespace App\Utils\Lod\Provider;

use App\Utils\Lod\Identifier\Identifier;
use App\Utils\Lod\Identifier\GeonamesIdentifier;
use App\Utils\Lod\Identifier\GndIdentifier;
use App\Utils\Lod\Identifier\LocLdsNamesIdentifier;
use App\Utils\Lod\Identifier\LocLdsSubjectsIdentifier;
use App\Utils\Lod\Identifier\ViafIdentifier;
use App\Utils\Lod\Identifier\WikidataIdentifier;

class LocProvider
extends AbstractProvider
implements TermProvider
{
    protected $name = 'loc';

    public function __construct()
    {
        \App\Utils\Lod\Identifier\Factory::register(GeonamesIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(GndIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(ViafIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(LocLdsNamesIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(LocLdsSubjectsIdentifier::class);
        \App\Utils\Lod\Identifier\Factory::register(WikidataIdentifier::class);

        \EasyRdf\RdfNamespace::set('owl', 'http://www.w3.org/2002/07/owl#');
        \EasyRdf\RdfNamespace::set('skos', 'http://www.w3.org/2004/02/skos/core#');
        \EasyRdf\RdfNamespace::set('madsrdf', 'http://www.loc.gov/mads/rdf/v1#');
    }

    public function lookup(Identifier $identifier)
    {
        if (!($identifier instanceof LocLdsNamesIdentifier or $identifier instanceof LocLdsSubjectsIdentifier)) {
            throw new \InvalidArgumentException('Expecting a LocLdsNamesIdentifier or LocLdsSubjectsIdentifier');
        }

        return $this->buildEntityFromUri($identifier->toUri());
    }

    protected function buildEntityFromUri($uri)
    {
        $rdfUrl = $uri . '.rdf'; // See $uri . '.html' for Alternate Formats (N-Triples / JSON)

        try {
            $graph = \EasyRdf\Graph::newAndLoad($rdfUrl);
        }
        catch (\EasyRdf\Http\Exception $e) {
            throw new \InvalidArgumentException($e->getMessage());
        }

        $resource = $graph->resource($uri);

        $types = array_map(function ($val) { return (string)$val; }, $resource->all('rdf:type'));

        if (in_array('http://www.loc.gov/mads/rdf/v1#PersonalName', $types)) {
            return $this->instantiatePersonFromResource($resource);
        }

        if (in_array('http://www.loc.gov/mads/rdf/v1#CorporateName', $types)) {
            return $this->instantiateOrganizationFromResource($resource);
        }

        if (in_array('http://www.loc.gov/mads/rdf/v1#Geographic', $types)) {
            return $this->instantiatePlaceFromResource($resource);
        }

        if (in_array('http://www.loc.gov/mads/rdf/v1#Topic', $types)) {
            return $this->instantiateTermFromResource($resource);
        }

        throw new \Exception('No handler for rdf:type ' . join(', ', $types));
    }


    protected function setEntityValues($entity, $valueMap)
    {
        foreach ($valueMap as $property => $value) {
            $method = 'set' . ucfirst($property);

            if (method_exists($entity, $method)) {
                $entity->$method($value);
            }
        }
    }

    protected function instantiatePersonFromResource($resource)
    {
        $entity = new \App\Entity\Person();
        $identifier = new LocLdsNamesIdentifier($resource->getUri());
        $entity->setIdentifier($identifier->getName(), $identifier->getValue());

        $label = (string)$resource->get('madsrdf:authoritativeLabel');
        $parts = preg_split('/,\s+/', $label);

        if (count($parts) > 0) {
            $entity->setFamilyName($parts[0]);

            if (count($parts) > 1) {
                $entity->setGivenName($parts[1]);

                if (count($parts) > 2 && preg_match('/^(\d*)\-(\d*)$/', $parts[2], $matches)) {
                    if ($matches[1] > 0) {
                        $entity->setBirthDate($matches[1]);
                    }

                    if ($matches[2] > 0) {
                        $entity->setDeathDate($matches[2]);
                    }
                }
            }
        }

        $this->processSameAs($entity, $resource);
        $this->processSameAs($entity, $resource, 'madsrdf:hasCloseExternalAuthority');

        /**
         * TODO: get more info from related Real World Object
         *
        $uriRwo = $resource->getResource('madsrdf:identifiesRWO');
        if (!is_null($uriRwo)) {
            die('TODO: get additional information from ' . (string)$uriRwo);
        }
        */

        return $entity;
    }

    protected function instantiateOrganizationFromResource($resource)
    {
        $entity = new \App\Entity\Organization();
        $identifier = new LocLdsNamesIdentifier($resource->getUri());
        $entity->setIdentifier($identifier->getName(), $identifier->getValue());

        $this->setEntityFromResource($entity, $resource, [
            'madsrdf:authoritativeLabel' => 'name',
        ]);

        $this->processSameAs($entity, $resource);
        $this->processSameAs($entity, $resource, 'madsrdf:hasCloseExternalAuthority');

        /**
         * TODO: get more info from related Real World Object
         *
        $uriRwo = $resource->getResource('madsrdf:identifiesRWO');
        if (!is_null($uriRwo)) {
            die('TODO: get additional information from ' . (string)$uriRwo);
        }
        */

        return $entity;
    }

    protected function instantiatePlaceFromResource($resource)
    {
        $entity = new \App\Entity\Place();
        $identifier = new LocLdsNamesIdentifier($resource->getUri());
        $entity->setIdentifier($identifier->getName(), $identifier->getValue());

        $this->setEntityFromResource($entity, $resource, [
            'madsrdf:authoritativeLabel' => 'name',
        ]);

        $this->processSameAs($entity, $resource);
        $this->processSameAs($entity, $resource, 'madsrdf:hasCloseExternalAuthority');

        return $entity;
    }

    protected function instantiateTermFromResource($resource)
    {
        $entity = new \App\Entity\Term();
        $identifier = new LocLdsSubjectsIdentifier($resource->getUri());
        $entity->setIdentifier($identifier->getName(), $identifier->getValue());

        $this->setEntityFromResource($entity, $resource, [
            'skos:prefLabel' => 'name',
        ]);

        $this->setDisambiguatingDescription($entity, $resource, 'skos:note');

        $this->processSameAs($entity, $resource);
        $this->processSameAs($entity, $resource, 'skos:closeMatch');

        return $entity;
    }
}

<?php

namespace App\Utils\Lod\Provider;

abstract class AbstractProvider implements Provider
{
    protected static function normalizeString($str)
    {
        /*
         * you can run
         *  composer require symfony/polyfill-intl-normalizer
         * to make sure this function is available
         */
        if (!function_exists('normalizer_normalize')) {
            return $str;
        }

        return \normalizer_normalize($str);
    }

    protected $name;

    public function getName()
    {
        return $this->name;
    }

    /**
     * Shared helper methods.
     */
    protected function processSameAs($entity, $resource, $property = 'owl:sameAs')
    {
        $resources = $resource->all($property);
        if (!is_null($resources)) {
            foreach ($resources as $resource) {
                $identifier = \App\Utils\Lod\Identifier\Factory::fromUri((string) $resource);

                if (!is_null($identifier)) {
                    $entity->setIdentifier($identifier->getName(), $identifier->getValue());
                }
            }
        }
    }

    protected function setDisambiguatingDescription($entity, $resource, $key)
    {
        $descriptions = $resource->all($key);
        if (!empty($descriptions)) {
            foreach ($descriptions as $description) {
                $lang = $description->getLang();
                if (!empty($lang)) {
                    $entity->setDisambiguatingDescription($lang, self::normalizeString($description->getValue()));
                }
            }
        }
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

    protected function setEntityFromResource($entity, $resource, $map)
    {
        foreach ($map as $key => $property) {
            $value = $resource->get($key);

            if (!is_null($value)) {
                $method = 'set' . ucfirst($property);

                if (method_exists($entity, $method)) {
                    $entity->$method(self::normalizeString((string) $value));
                }
            }
        }
    }
}

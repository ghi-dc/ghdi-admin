<?php

namespace App\Service\ImageHeader;

use Symfony\Component\HttpFoundation\File\File;

/**
 * This service provides the two methods
 *  getResolution()
 * and
 *  setResolution()
 * to get information about image files
 *
 * - ExiftoolProvider
 *  calls the exiftool command-line utilities
 *  through symfony/process.
 *
 */
class ImageHeaderService
{
    protected $providers = [];
    protected $options;

    protected $typeMap = [];

    protected $providerCache = [];

    public function __construct($providers = [], $options = [])
    {
        $this->options = $options;

        if (!empty($providers)) {
            if (!is_array($providers)) {
                $providers = [ $providers ];
            }

            $this->addProviders($providers);
        }
    }

    protected static function buildMimeTypeGeneric($mimeType)
    {
        if (preg_match('/^(image)\/[^\/]+$/', $mimeType, $matches)) {
            return $matches[1] . '/*';
        }

        return $mimeType;
    }

    public function addProviders(array $providers)
    {
        foreach ($providers as $provider) {
            $this->addProvider($provider);
        }
    }

    public function addProvider($provider)
    {
        $this->providers[] = $provider;

        foreach ($provider->getSupportedTypes() as $supportedType) {
            if (!array_key_exists($supportedType, $this->typeMap)) {
                $this->typeMap[$supportedType] = [];
            }

            // append
            $this->typeMap[$supportedType][] = $provider;
        }
    }

    protected function findProvider($srcType)
    {
        $cacheKey = implode('|', [ $srcType ]);
        if (array_key_exists($cacheKey, $this->providerCache)) {
            return $this->providerCache[$cacheKey];
        }

        // look-up match
        if (isset($this->typeMap[$srcType])) {
            return $this->providerCache[$cacheKey] = $this->typeMap[$srcType][0];
        }
    }

    protected function findBestProvider($srcType)
    {
        $provider = $this->findProvider($srcType);
        if (isset($provider)) {
            return $provider;
        }

        // look for wild-card
        $srcTypeGeneric = self::buildMimeTypeGeneric($srcType);
        if ($srcTypeGeneric != $srcType) {
            $provider = $this->findProvider($srcTypeGeneric);
            if (isset($provider)) {
                return $provider;
            }
        }

        // generic src
        $provider = $this->findProvider('*/*');
        if (isset($provider)) {
            return $provider;
        }

        return null;
    }

    public function getResolution(File $file, $options = [])
    {
        if (!isset($options['src_type'])) {
            $options['src_type'] = $file->getMimeType();
        }

        $srcType = $options['src_type'];

        $provider = $this->findBestProvider($srcType);
        if (!isset($provider)) {
            throw new \RuntimeException("No provider found for getting header for " . $srcType);
        }

        return $provider->getResolution((string)$file);
    }

    public function setResolution(File $file, $options)
    {
        if (!isset($options['src_type'])) {
            $options['src_type'] = $file->getMimeType();
        }

        $srcType = $options['src_type'];

        $provider = $this->findBestProvider($srcType);
        if (!isset($provider)) {
            throw new \RuntimeException("No provider found for getting header for " . $srcType);
        }

        return $provider->setResolution((string)$file, $options);
    }
}

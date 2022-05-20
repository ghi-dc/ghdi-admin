<?php

// src/Service/SanitizationService.php
namespace App\Service;

/**
 * Wrap HTMLPurifier into a Service
 *
 */
class SanitizationService
{
    /** @var HTMLPurifier */
    private $htmlPurifier;

    /**
     * @param array $options Pass url, api-user, api-key and possibly root-collection
     */
    public function __construct(string $cacheDirectory)
    {
    	// Make sure the cache directory exists, as the purifier won't create it for you
        if (!file_exists($cacheDirectory) && !mkdir($cacheDirectory, 0777, true) && !is_dir($cacheDirectory)) {
            throw new \RuntimeException(sprintf('HTML purifier directory "%s" can not be created', $cacheDirectory));
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('AutoFormat.RemoveSpansWithoutAttributes', true);
        $config->set('CSS.AllowedProperties', []);
        $config->set('Cache.SerializerPath', $cacheDirectory);

        $this->htmlPurifier = new \HTMLPurifier($config);
    }

    public function sanitizeHtml(string $content): string
    {
        return $this->htmlPurifier->purify($content);
    }
}

<?php
// src/Twig/AppExtension.php

/**
 * see http://symfony.com/doc/current/cookbook/templating/twig_extension.html
 */

namespace App\Twig;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class AppExtension
extends \Twig\Extension\AbstractExtension
{
    private $kernel;
    private $translator;
    private $slugifyer;

    public function __construct(KernelInterface $kernel = null,
                                TranslatorInterface $translator = null,
                                $slugifyer = null)
    {
        $this->kernel = $kernel;
        $this->translator = $translator;
        $this->slugifyer = $slugifyer;
        if (!is_null($slugifyer)) {
            // this should be set in bundlesetup
            $slugifyer->addRule('Ṿ', 'V');
        }
    }

    public function getFunctions()
    {
        return [
            new \Twig\TwigFunction('file_exists', 'file_exists'),
        ];
    }

    public function getFilters()
    {
        return [
            // general
            new \Twig\TwigFilter('dateincomplete', [ $this, 'dateincompleteFilter' ]),
            new \Twig\TwigFilter('remove_by_key', [ $this, 'removeElementByKey' ]),
            new \Twig\TwigFilter('prettifyurl', [ $this, 'prettifyurlFilter' ]),

            // app specific
        ];
    }

    private function getLocale()
    {
        if (is_null($this->translator)) {
            return 'en';
        }

        return $this->translator->getLocale();
    }

    public function dateincompleteFilter($datestr, $locale = null)
    {
        if (is_null($locale)) {
            $locale = $this->getLocale();
        }

        if (is_object($datestr) && $datestr instanceof \DateTime) {
            $datestr = $datestr->format('Y-m-d');
        }

        return \App\Utils\Formatter::dateIncomplete($datestr, $locale);
    }

    public function removeElementByKey($array, $key)
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            unset($array[$key]);
        }

        return $array;
    }

    public function prettifyurlFilter($url)
    {
        $parsed = parse_url($url);
        if (empty($parsed['host'])) {
            // probably not an url, so return as is
            return $url;
        }

        return $parsed['host']
            . (!empty($parsed['path']) && '/' !== $parsed['path'] ? $parsed['path'] : '');
    }

    public function getName()
    {
        return 'app_extension';
    }
}

<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\RouteCollectionBuilder;

// see https://github.com/ikoene/symfony-micro
class MicroKernel
extends Kernel
{
    /*
     * Set an Environment Variable in Apache Configuration
     *   SetEnv APP_ENVIRONMENT prod
     * for production setting instead of having www/app.php and www/app_dev.php
     * This approach is described int
     *   https://www.pmg.com/blog/symfony-no-app-dev/
     */
    public static function fromEnvironment()
    {
        $env = getenv('APP_ENVIRONMENT');
        if (false === $env) {
            $env = 'dev';
            $debug = true;
        }
        else {
            $debug = filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN);
        }

        return new self($env, $debug);
    }

    use MicroKernelTrait;

    /*
     * {@inheritDoc}
     */
    public function registerBundles()
    {
        $bundles = [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),

            new \Symfony\Bundle\TwigBundle\TwigBundle(),
            new \Symfony\Bundle\MonologBundle\MonologBundle(),

            /*
            // translate routes - now using built-in
            // https://symfony.com/blog/new-in-symfony-4-1-internationalized-routing
            new \JMS\I18nRoutingBundle\JMSI18nRoutingBundle(),
            */
            // not required, but recommended for better extraction
            new \JMS\TranslationBundle\JMSTranslationBundle(),

            // login
            new \Symfony\Bundle\SecurityBundle\SecurityBundle(),

            // asset management
            // see http://symfony.com/doc/current/cookbook/assetic/asset_management.html
            new \Symfony\Bundle\AsseticBundle\AsseticBundle(),

            // menu
            // see http://symfony.com/doc/current/bundles/KnpMenuBundle/index.html
            new \Knp\Bundle\MenuBundle\KnpMenuBundle(),

            // slug
            new \Cocur\Slugify\Bridge\Symfony\CocurSlugifyBundle(),
        ];

        if (in_array($this->getEnvironment(), ['dev', 'test'], true)) {
            $bundles[] = new \LegacyAsseticBundle\LegacyAsseticBundle();
            $bundles[] = new \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new \Symfony\Bundle\DebugBundle\DebugBundle();
        }

        // as long as we don't use jms serializer bundle
        // Bootstrap the JMS custom annotations for Object to Json mapping
        // see https://stackoverflow.com/q/14629137
        // alternative is AnnotationRegistry::registerLoader('class_exists'); see https://github.com/doctrine/annotations/issues/103
        \Doctrine\Common\Annotations\AnnotationRegistry::registerAutoloadNamespace(
            'JMS\Serializer\Annotation',
            $this->getProjectDir().'/vendor/jms/serializer/src'
        );

        return $bundles;
    }

    // see https://github.com/symfony/symfony-standard/blob/master/app/AppKernel.php
    public function getCacheDir()
    {
        return $this->getProjectDir().'/var/cache/'.$this->getEnvironment();
    }

    public function getLogDir()
    {
        return $this->getProjectDir().'/var/logs';
    }

    public function getConfigDir()
    {
        return $this->getProjectDir().'/config';
    }

    /*
     * {@inheritDoc}
     */
    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
    {
        $loader->load($this->getConfigDir().'/config_'.$this->getEnvironment().'.yaml');
        $loader->load($this->getConfigDir().'/services.yaml');
    }

    /*
     * {@inheritDoc}
     */
    protected function configureRoutes(RouteCollectionBuilder $routes)
    {
        if (in_array($this->getEnvironment(), ['dev', 'test'], true)) {
            $routes->mount('/_wdt', $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.xml'));
            $routes->mount(
                '/_profiler',
                $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.xml')
            );

            // Preview error pages through /_error/{statusCode}
            //   see http://symfony.com/doc/current/cookbook/controller/error_pages.html
            // Note: not sure why this is mapped to /_error/_error/{code}.{_format} as can be seen by
            //   bin/console debug:router | grep twig
            // ->_twig_error_test            ANY      ANY      ANY    /_error/_error/{code}.{_format}

            $routes->mount(
                '/_error',
                $routes->import('@TwigBundle/Resources/config/routing/errors.xml')
            );
        }

        // our controllers
        $routes->mount('/', $routes->import($this->getConfigDir().'/routes.yaml'));
    }
}

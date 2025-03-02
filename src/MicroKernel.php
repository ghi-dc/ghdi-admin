<?php

// src/Microkernel.php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// see https://symfony.com/doc/current/configuration/micro_kernel_trait.html
final class MicroKernel extends Kernel
{
    use MicroKernelTrait;

    /*
     * Set an Environment Variable in Apache Configuration
     *   SetEnv APP_ENVIRONMENT prod
     * for production setting instead of having www/app.php and www/app_dev.php
     * This approach is described in
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

    public function registerBundles(): array
    {
        $bundles = [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),

            new \Symfony\Bundle\TwigBundle\TwigBundle(),
            new \Twig\Extra\TwigExtraBundle\TwigExtraBundle(),

            new \Symfony\Bundle\MonologBundle\MonologBundle(),

            // not required, but recommended for better extraction
            new \JMS\TranslationBundle\JMSTranslationBundle(),

            // login
            new \Symfony\Bundle\SecurityBundle\SecurityBundle(),

            // menu
            // see http://symfony.com/doc/current/bundles/KnpMenuBundle/index.html
            new \Knp\Bundle\MenuBundle\KnpMenuBundle(),

            // slug
            new \Cocur\Slugify\Bridge\Symfony\CocurSlugifyBundle(),

            // https://github.com/a-r-m-i-n/scssphp-bundle
            new \Armin\ScssphpBundle\ScssphpBundle(),

            // solr
            new \FS\SolrBundle\FSSolrBundle(),
        ];

        if (in_array($this->getEnvironment(), ['dev', 'test'], true)) {
            $bundles[] = new \Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new \Symfony\Bundle\DebugBundle\DebugBundle();
        }

        // as long as we don't use jms serializer bundle
        // Bootstrap the JMS custom annotations for Object to Json mapping
        // see https://stackoverflow.com/q/14629137
        /*
        \Doctrine\Common\Annotations\AnnotationRegistry::registerAutoloadNamespace(
            'JMS\Serializer\Annotation',
            $this->getProjectDir().'/vendor/jms/serializer/src'
        );
        */

        // since boppy/solr-bundle requires the class_exists-loader
        // which is no longer added by default in Symfony 6.4
        // https://github.com/symfony/symfony/issues/50617#issuecomment-1635951372
        // we call the following for both JMS and FS\SolrBundle
        \Doctrine\Common\Annotations\AnnotationRegistry::registerLoader('class_exists');

        return $bundles;
    }

    // optional, to use the standard Symfony cache directory
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/' . $this->getEnvironment();
    }

    // optional, to use the standard Symfony logs directory
    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/logs';
    }

    public function getConfigDir(): string
    {
        return $this->getProjectDir() . '/config';
    }

    protected function configureContainer(ContainerBuilder $c, LoaderInterface $loader)
    {
        $loader->load($this->getConfigDir() . '/config_' . $this->getEnvironment() . '.yaml');
        $loader->load($this->getConfigDir() . '/services.yaml');

        // configure WebProfilerBundle only if the bundle is enabled
        // see https://symfony.com/doc/current/configuration/micro_kernel_trait.html#advanced-example-twig-annotations-and-the-web-debug-toolbar
        if (isset($this->bundles['WebProfilerBundle'])) {
            $c->loadFromExtension('web_profiler', [
                'toolbar' => true,
                'intercept_redirects' => false,
            ]);
        }
    }

    /**
     * {@inheritDoc}
     *
     * use
     *      bin/console debug:router
     * to show all your routes
     */
    protected function configureRoutes(RoutingConfigurator $routes)
    {
        if (in_array($this->getEnvironment(), ['dev', 'test'], true)) {
            $routes->import('@WebProfilerBundle/Resources/config/routing/wdt.xml')->prefix('/_wdt');
            $routes->import('@WebProfilerBundle/Resources/config/routing/profiler.xml')->prefix('/_profiler');

            // Preview error pages through /_error/{statusCode}
            //   see http://symfony.com/doc/current/cookbook/controller/error_pages.html
            $routes->import('@FrameworkBundle/Resources/config/routing/errors.xml')->prefix('/_error');
        }

        // App controllers
        $routes->import($this->getConfigDir() . '/routes.yaml');
    }
}

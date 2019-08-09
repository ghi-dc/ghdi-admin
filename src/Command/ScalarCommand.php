<?php

// src/App/Command/ScalarCommand.php
namespace App\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *
 * Import content from eXist-db admin into Scalar
 *
 */
class ScalarCommand
extends ContainerAwareCommand
{
    protected $adminClient;
    protected $scalarClient;
    protected $config = [];

    public function __construct(\Symfony\Contracts\HttpClient\HttpClientInterface $adminClient,
                                \App\Utils\AnvcScalarClient $scalarClient)
    {
        $this->adminClient = $adminClient;
        $this->scalarClient = $scalarClient;

        // you *must* call the parent constructor
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('scalar:import')
            ->setDescription('Import')
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'what you want to import (introduction, documents, images, maps, map-path)'
            )
            ->addOption(
                'volume',
                null,
                InputOption::VALUE_REQUIRED,
                'volume id (e.g. 15)'
            )
            ->addOption(
                'locale',
                null,
                InputOption::VALUE_REQUIRED,
                'what locale (en or de)',
                'en'
            )
            ;
    }

    /**
     * Fetch a JSON representation from our admin
     */
    protected function fetchJson($volumeId, $page = null, $locale = 'en')
    {
        $url = 'en' != $locale ? $locale . '/' : '';

        if (is_null($page)) {
            $url .= sprintf('volume/volume-%d', $volumeId);

        }
        else {
            $url .= sprintf('resource/volume-%d/%s',
                            $volumeId, $page);
        }

        $url .= '.scalar.json';

        $response = $this->adminClient->request('GET', $url);
        try {
            $pageInfo = $response->toArray();
        }
        catch (\Exception $e) {
            $pageInfo = false;
        }

        return $pageInfo;
    }

    /**
     * Fetches an image from the legacy site and stores it into $imagePath
     */
    protected function fetchRemoteImage($url, $basename, $imagePath)
    {
        if (preg_match('~(^.+/)([^/]+)$~', $url, $matches)) {
            // handles spaces and umlauts e.g. http://germanhistorydocs.ghi-dc.org/images/00004711_Stand auf dem Blutgerüste.jpg
            $url = $matches[1] . rawurlencode($matches[2]);
        }

        $parts = parse_url($url);
        $path = parse_url($url, PHP_URL_PATH);

        if (!preg_match('/(\.jpg)$/i', $path, $matches)) {
            die('TODO: handle extension for ' . $url);
        }

        $extension = strtolower($matches[1]);
        $imageName = $basename . $extension;

        if (!file_exists($imagePath . $imageName)) {
            file_put_contents($imagePath . $imageName, fopen($url, 'r'));
        }

        if (file_exists($imagePath . $imageName)) {
            $maxDimension = 1280;

            // check if we need to convert (either resize or change format)
            $imageConversion = $this->getContainer()->get(\App\Service\ImageConversion\ConversionService::class);

            $file = new \Symfony\Component\HttpFoundation\File\File($imagePath . $imageName);

            $info = $imageConversion->identify($file);
            if ((!empty($info['width']) && $info['width'] > $maxDimension)
                || (!empty($info['height']) && $info['height'] > $maxDimension))
            {
                $converted = $imageConversion->convert($file, [
                    'geometry' => $maxDimension . 'x' . $maxDimension, 'target_type' => 'image/jpeg',
                ]);
                $imageName = $converted->getFileName();
            }
        }

        return $imageName;
    }

    protected function addOrUpdateMedia($volumeId, $slug, $locale = 'en')
    {
        $mediaProperties = [ 'dcterms:title', 'dcterms:description', 'dcterms:creator', 'dcterms:date' ];

        $pageInfo = $this->fetchJson($volumeId, $slug . '/media', $locale);
        $mediaSlug = $pageInfo['scalar:metadata:slug'];

        $update = false;
        $page = [];

        $pageExisting = $this->scalarClient->getPage($mediaSlug);
        if (!empty($pageExisting)) {
            foreach ($mediaProperties as $property) {
                $valExisting = array_key_exists($property, $pageExisting)
                    ? $pageExisting[$property] : '';
                $valNew = array_key_exists($property, $pageInfo)
                    ? $pageInfo[$property] : '';

                if (rtrim($valExisting) != rtrim($valNew)) {
                    $update = true;
                    break;
                }
            }

            if (!$update) {
                if (!empty($pageInfo['scalar:metadata:url'])) {
                    if (empty($pageExisting['art:url'])) {
                        // image missing, therefore update
                        $update = true;
                    }
                    else {
                        // maybe some logic to determine if image has changed
                    }
                }
            }

            if (!$update) {
                return $pageExisting;
            }

            $page = $pageExisting;
        }

        if (!empty($pageInfo['scalar:metadata:url'])) {
            $basename = basename($mediaSlug); // chop of leading media/
            $imageName = $this->fetchRemoteImage($pageInfo['scalar:metadata:url'], $basename,
                                                 $imagePath = $this->getContainer()->get('kernel')->getProjectDir() . '/data/media/');

            // now we can upload
            $res = $this->scalarClient->upload($imagePath . $imageName);

            // check for error
            if (is_array($res) && !empty($res['error'])) {
                throw new \Exception(sprintf("Error uploading %s: %s",
                                             $basename, $res['error']));
            }

            // and now create the media-page
            $page['scalar:metadata:slug'] = $mediaSlug;

            $baseurlMedia = sprintf('%s%s/media/',
                                    $this->scalarClient->getBaseurl(),
                                    $this->scalarClient->getBook());
            $page['scalar:metadata:url'] = $baseurlMedia . $imageName;
            $extension = pathinfo($imageName, PATHINFO_EXTENSION);
            $page['scalar:metadata:thumb'] =  $baseurlMedia . $basename . '_thumb' . '.' . $extension;

            foreach ($mediaProperties as $key) {
                if (!empty($pageInfo[$key])) {
                    $page[$key] = $pageInfo[$key];
                }
            }

            return $update
                ? $this->scalarClient->updatePage($page, 'media')
                : $this->scalarClient->addPage($page, 'media');
        }
    }

    /**
     * scalar embeds media in form of link tags of the form
     * <a data-size="full" data-align="left" data-caption="description" data-annotations="" class="inline" name="scalar-inline-media" href="..." resource="media/media-1234"></a>
     *
     * adjustEmbeddedMedia checks for such tags and updates resource and href in these tags according to the book-specific url
     */
    protected function adjustEmbeddedMedia(&$html,
                                           $baseurl, $book)
    {
        $resources = [];

        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html, 'UTF-8');

        $crawler->filter('a')->each(function ($node, $i) use (&$resources, $baseurl, $book) {
            $resource = $node->attr('resource');

            if (empty($resource)) {
                return;
            }

            if (preg_match('/^media\/((image|map)\-(\d+))$/', $resource, $matches)) {
                $resources[] = $matches[1] ;

                // currently we use media-1234 as generic slug, we might keep original naming below 'media/' namespace
                $path = 'media/media-' . $matches[3];
                $node->getNode(0)->setAttribute('resource', $path);
                // link points to the actual upload for the $media, currently assuming everything is .jpg
                $node->getNode(0)->setAttribute('href',
                                                $baseurl . $book . '/' . $path . '.jpg');
            }
        });

        if (!empty($resources)) {
            // we adjusted href, so need to set an updated $html (without the body-tag that gets added through addHtmlContent)
            $html = $crawler->filter('body')->html();
        }

        return $resources;
    }

    protected function addOrUpdate($volumeId, $slug, $page, $locale = 'en')
    {
        $media = !empty($page['sioc:content'])
            ? $this->adjustEmbeddedMedia($page['sioc:content'],
                                         $this->scalarClient->getBaseurl(), $this->scalarClient->getBook())
            : [];

        if (!empty($media)) {
            // for each media embedded into the page, we need to make sure a current version exists in scalar
            foreach ($media as $mediaSlug) {
                $this->addOrUpdateMedia($volumeId, $mediaSlug, $locale);
            }
        }

        $pageExisting = $this->scalarClient->getPage($slug);

        if (!empty($pageExisting)) {
            if ($page['dcterms:title'] == $pageExisting['dcterms:title']
                && (
                    !empty($pageExisting['sioc:content']) && !empty($page['sioc:content']) && rtrim($page['sioc:content']) == $pageExisting['sioc:content'])
                    || (empty($pageExisting['sioc:content']) && empty($page['sioc:content'])
                )
                )
            {
                return [];
            }

            // update
            $pageExisting['dcterms:title'] = $page['dcterms:title'];
            $pageExisting['sioc:content'] = $page['sioc:content'];
            $updated = $this->scalarClient->updatePage($pageExisting);

            return $updated;
        }

        $page['scalar:metadata:slug'] = $slug;

        return $this->scalarClient->addPage($page);
    }

    protected function addOrUpdateRelation($slug, $slugChild, $type, $options)
    {
        $parent = $this->scalarClient->getPage($slug);
        if (empty($parent)) {
            return false;
        }

        $child = $this->scalarClient->getPage($slugChild);
        if (empty($child)) {
            return false;
        }

        // check if relation already exists
        $parentUrl = sprintf('%s.%s',
                             $parent['url'], $parent['ov:versionnumber']);
        $childUrl = sprintf('%s.%s',
                             $child['url'], $child['ov:versionnumber']);

        switch ($type) {
            case 'contained':
                $related = $this->scalarClient->listRelated($slug, 'path');
                foreach ($related as $url => $info) {
                    if (array_key_exists('http://www.openannotation.org/ns/hasBody', $info)
                        && array_key_exists('http://www.openannotation.org/ns/hasTarget', $info))
                    {
                        if ($parentUrl == $info['http://www.openannotation.org/ns/hasBody'][0]['value']) {
                            $index = 0;
                            if (preg_match('/^(.+)#index=(\d+)$/',
                                           $target = $info['http://www.openannotation.org/ns/hasTarget'][0]['value'], $matches)) {
                                $target = $matches[1];
                                $index = $matches[2];
                            }

                            if ($target == $childUrl) {
                                if ($index == $options['sort_number']) {
                                    return true;
                                }

                                die('TODO: handle change in sort_number');
                            }
                        }
                    }
                }
                break;

            default:
                die('TODO: check for already related not implemented yet for type: ' . $type);
                break;
        }

        $res = $this->scalarClient->relate($parent['scalar:urn'], $child['scalar:urn'], $type, $options);

        return $res;
    }

    protected function setPaths($output, $slugFrom, $pages)
    {
        $count = 0;
        foreach ($pages as $slug) {
            $res = $this->addOrUpdateRelation($slugFrom, $slug, 'contained', [
                'sort_number' => $sort_number = $count++,
            ]);

            if (true === $res) {
                $output->writeln(sprintf('<info>Path from %s to %s (%d) already exists</info>',
                                         $slugFrom, $slug, $sort_number));
            }
            else if ($res) {
                $output->writeln(sprintf('<info>Path from %s to %s (%d) was added</info>',
                                         $slugFrom, $slug, $sort_number));
            }
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $locale = $input->getOption('locale');
        $volumeId = $input->getOption('volume');

        $volumeInfo = $this->fetchJson($volumeId, null, $locale);

        if (false === $volumeInfo) {
            $output->writeln(sprintf('<error>an error occured in action: %s</error>',
                                     $input->getArgument('action')));

            return 1;
        }

        switch ($action = $input->getArgument('action')) {
            case 'introduction':
            case 'documents':
            case 'images':
            case 'maps':
                $parts = array_key_exists('dcterms:hasPart', $volumeInfo)
                    ? array_filter($volumeInfo['dcterms:hasPart'], function ($part) use ($action) { return $part['scalar:metadata:slug'] === $action; })
                    : [];

                foreach ($parts as $section) {
                    foreach ($section['dcterms:hasPart'] as $part) {
                        $pageInfo = $this->fetchJson($volumeId, $part['scalar:metadata:slug'], $locale);
                        if (false == $pageInfo) {
                            $output->writeln(sprintf('<error>an error occured in action: %s fetching %s</error>',
                                                     $action, $part['scalar:metadata:slug']));
                            continue;
                        }

                        $res = $this->addOrUpdate($volumeId, $slug = $pageInfo['scalar:metadata:slug'], $pageInfo, $locale);
                        if (empty($res)) {
                            $output->writeln(sprintf('<info>page %s already exists</info>',
                                                     $slug));
                        }
                        else {
                            $output->writeln(sprintf('<info>Add or update %s: %s</info>',
                                                     $action,
                                                     $this->jsonPretty($res)));
                        }

                        if (!empty($part['dcterms:hasPart'])) {
                            foreach ($part['dcterms:hasPart'] as $subPart) {
                                $pageInfo = $this->fetchJson($volumeId, $subPart['scalar:metadata:slug'], $locale);
                                if (false == $pageInfo) {
                                    $output->writeln(sprintf('<error>an error occured in action: %s fetching %s</error>',
                                                             $action, $subPart['scalar:metadata:slug']));
                                    continue;
                                }

                                $res = $this->addOrUpdate($volumeId, $slug = $pageInfo['scalar:metadata:slug'], $pageInfo, $locale);
                                if (empty($res)) {
                                    $output->writeln(sprintf('<info>page %s already exists</info>',
                                                             $slug));
                                }
                                else {
                                    $output->writeln(sprintf('<info>Add or update %s: %s</info>',
                                                             $action,
                                                             $this->jsonPretty($res)));
                                }
                            }
                        }
                    }
                }

                return 0;
                break;

            case 'index-path':
            case 'introduction-path':
            case 'document-path':
            case 'image-path':
            case 'map-path':
                $slugFrom = str_replace('-path',
                                        in_array($action, ['index-path', 'introduction-path'])
                                        ? '' : 's',
                                        $action);

                if ('index' == $slugFrom) {
                    $pages = array_map(function ($section) { return $section['scalar:metadata:slug']; },
                                       $volumeInfo['dcterms:hasPart']);

                    $this->setPaths($output, $slugFrom, $pages);
                }
                else {
                    $parts = array_key_exists('dcterms:hasPart', $volumeInfo)
                        ? array_filter($volumeInfo['dcterms:hasPart'],
                                       function ($part) use ($slugFrom) { return $part['scalar:metadata:slug'] === $slugFrom; })
                        : [];


                    foreach ($parts as $part) {
                        $pages = [];

                        /*
                        $pages =
                        */


                        foreach ($part['dcterms:hasPart'] as $section) {
                            $pages[] = $sectionFrom = $section['scalar:metadata:slug'];

                            if (!empty($section['dcterms:hasPart'])) {
                                $subpages = array_map(function ($subsection) { return $subsection['scalar:metadata:slug']; },
                                                      $section['dcterms:hasPart']);

                                if (!empty($subpages)) {
                                    $this->setPaths($output, $sectionFrom, $subpages);
                                }
                            }
                        }

                        $this->setPaths($output, $slugFrom, $pages);
                    }
                }

                return 0;
                break;

            // test
            case 'introduction-29':
            case 'document-12':
            case 'image-2':
            case 'image-1204':
                $pageInfo = $this->fetchJson($volumeId, $action, $locale);

                if (false === $pageInfo) {
                    $output->writeln(sprintf('<error>an error occured in action: %s</error>',
                                             $input->getArgument('action')));

                    return 1;
                }

                $res = $this->addOrUpdate($volumeId, $slug = $pageInfo['scalar:metadata:slug'], $pageInfo, $locale);
                if (empty($res)) {
                    $output->writeln(sprintf('<info>page %s already exists</info>',
                                             $slug));
                }
                else {
                    $output->writeln(sprintf('<info>Add or update %s: %s</info>',
                                             $action,
                                             $this->jsonPretty($res)));
                }

                return 0;
                break;

            default:
                $output->writeln(sprintf('<error>invalid action: %s</error>',
                                         $input->getArgument('action')));
                return 1;
        }
    }

    /**
     * Small helper for nice looking JSON content
     */
    protected function jsonPretty($res)
    {
        return json_encode($res,
                           JSON_UNESCAPED_SLASHES
                           | JSON_PRETTY_PRINT
                           | JSON_UNESCAPED_UNICODE);
    }
}

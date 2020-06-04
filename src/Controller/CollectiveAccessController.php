<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class CollectiveAccessController
extends BaseController
{
    static $LOCALE_MAP = [
        'de' => 'de_DE',
        'en' => 'en_US',
    ];

    /**
     * @Route("/collective-access", name="ca-list")
     */
    public function homeAction(Request $request,
                               \App\Service\CollectiveAccessService $caService)
    {
        $collections = $caService->getCollections();

        $collection = trim($request->request->get('collection'));

        $result = null;

        if (!empty($collection)
            && !empty(array_filter($collections,
                                 function ($aCollection) use ($collection) { return $collection === $aCollection['idno']; } )))
        {
            $locale = $request->getLocale();

            $condition = sprintf('ca_collections:"%s"', $collection);
            $caSearchService = $caService->getSearchService($condition);
            if (array_key_exists($locale, self::$LOCALE_MAP)) {
                $caSearchService->setLang(self::$LOCALE_MAP[$locale]);
            }

            $result = $caSearchService->request();
        }

        return $this->render('CollectiveAccess/list.html.twig', [
            'collections' => $collections,
            'collection' => $collection,
            'result' => $result,
        ]);
    }

    protected function htmlFragmentToXhtml($htmlFragment, $omitPara = false)
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('AutoFormat.RemoveSpansWithoutAttributes', true);
        $config->set('CSS.AllowedProperties', []);

        $purifier = new \HTMLPurifier($config);
        $xhtml = $purifier->purify($htmlFragment);

        if (function_exists('tidy_parse_string')) {
            $tidyConfig = [
                'clean' => true,
                'output-xhtml' => true,
                'show-body-only' => true,
                'wrap' => 0,
            ];

            $tidy = tidy_parse_string($xhtml, $tidyConfig, 'UTF8');
            $tidy->cleanRepair();

            $xhtml = (string)$tidy;
        }

        if ($omitPara) {
            $xhtml = preg_replace('#^<p>(.*?)</p>$#s', '\1', $xhtml);
        }

        return trim($xhtml);
    }

    /**
     * TODO: Switch to Pandoc Converter
     */
    protected function htmlFragmentToTei($htmlFragment, $omitPara = false)
    {
        $xhtml = $this->htmlFragmentToXhtml($htmlFragment, $omitPara);

        $pandocProcessor = $this->container->get(\App\Utils\PandocProcessor::class);

        $tei = $pandocProcessor->convertHtmlFragmentToTeiSimple($xhtml);
        if ($omitPara) {
            $tei = preg_replace('#^<p>(.*?)</p>$#s', '\1', $tei);
        }

        // for <hi> - should be solved in xml-processing or tei
        $teiDtabf = str_replace([ 'simple:bold', 'simple:italic' ],
                                [ '#b', '#i' ],
                                $tei);

        // don't allow role="c1" on ref
        $teiDtabf = preg_replace('/(<ref\s+[^>]*)role="[^"]*"([^>]*>)/', '\1\2', $teiDtabf);

        return trim($teiDtabf);
    }

    protected function hasHtmlTag($val)
    {
        return strip_tags($val) !== $val;
    }

    protected function decodeHtmlEntity($val)
    {
        return html_entity_decode($val, ENT_QUOTES, 'utf-8');
    }

    protected function buildTeiValue($raw, $omitPara = false)
    {
        // check if it is html or plain text
        if (!$this->hasHtmlTag($raw)) {
            return $this->xmlSpecialchars($this->decodeHtmlEntity($raw));
        }

        return $this->htmlFragmentToTei($raw, $omitPara);
    }

    protected function buildLicenceTarget($raw)
    {
        $target = null;

        if (preg_match('/\bCC0\b/', $raw, $matches)) {
            $target = 'https://creativecommons.org/publicdomain/zero/1.0/';
        }
        else if (preg_match('/CC[\s\-]+(.+)/', $raw, $matches)) {
            $additional = $matches[1];
            if (preg_match('/^by[\-\s]*sa/i', $additional)) {
                // start with generic by-sa 4.0
                $target = 'https://creativecommons.org/licenses/by-sa/4.0/';
                $rest = trim(preg_replace('/^by[\-\s]*sa/i', '', $additional));
                if (!empty($rest)) {
                    if (preg_match('/^4/', $rest)) {
                        // we are done
                    }
                    else {
                        // TODO: handle variantes
                        var_dump($rest);
                    }
                }
            }
            else {
                var_dump($matches);
                exit;
            }
        }
        else if (preg_match('/\bpublic\s+domain\b/i', $raw, $matches)) {
            $target = 'https://creativecommons.org/publicdomain/mark/1.0/';
        }
        else {
            // var_dump($raw);
        }

        return $target;
    }

    protected function buildTeiHeader($data, $locale, \App\Service\CollectiveAccessService $caService)
    {
        $languages = [];
        foreach (self::$LOCALE_MAP as $aLocale => $lang) {
            if ($locale == $aLocale) {
                array_unshift($languages, $lang);
            }
            else {
                $languages[] = $lang;
            }
        }

        $teiHeader = new \App\Entity\TeiHeader();
        $teiHeader->setGenre('image'); // TODO: maybe look at format
        $teiHeader->setLanguage(\App\Utils\Iso639::code1To3($locale));

        foreach ( [ 'preferred_labels' => 'title' ] as $src => $dst) {
            if (array_key_exists($src, $data)) {
                $struct = & $data[$src];
                foreach ($languages as $lang) {
                    if (array_key_exists($lang, $struct)) {
                        $val = $this->buildTeiValue($struct[$lang][0]['name'], true);
                        $method = 'set' . ucfirst($dst);
                        $teiHeader->$method($val);

                        break;
                    }
                }
            }
        }

        foreach ( [ 'ca_objects.description' => 'note' ] as $src => $dst) {
            if (array_key_exists($src, $data)) {
                foreach ($languages as $lang) {
                    foreach ($data[$src] as $struct) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang]['description'], false);
                            $method = 'set' . ucfirst($dst);
                            $teiHeader->$method($val);

                            break 3;
                        }
                    }
                }
            }
        }

        if (!empty($data['related'])) {
            foreach ([ 'ca_entities' ] as $relation) {
                if (array_key_exists($relation, $data['related'])) {
                    foreach ($data['related'][$relation] as $entity) {
                        // TODO: maybe lookup related entities indididually since
                        // "locale_id" => "1" / "4" comes without a clear structure
                        switch ($entity['relationship_typename']) {
                            case 'creator':
                                if (array_key_exists('forename', $entity)) {
                                    // assume a structured name
                                    $nameParts = [];
                                    foreach ([ 'forename', 'surname' ] as $key) {
                                        if (!empty($entity[$key])) {
                                            $val = $this->buildTeiValue($entity[$key], true);
                                            $nameParts[] = $val; // if you want to wrap into extra tags: sprintf('<%s>%s</%s>', $key, $val, $key);
                                        }
                                    }

                                    $name = sprintf('<persName>%s</persName>', join(' ', $nameParts));
                                }
                                else {
                                    $name = $this->buildTeiValue($entity['displayname'], true);
                                }

                                $teiHeader->addAuthor($name);
                                break;

                            case 'contributor':
                                if (array_key_exists('forename', $entity)) {
                                    // assume a structured name
                                    $nameParts = [];
                                    foreach ([ 'forename', 'surname' ] as $key) {
                                        if (!empty($entity[$key])) {
                                            $val = $this->buildTeiValue($entity[$key], true);
                                            $nameParts[] = $val; // if you want to wrap into extra tags: sprintf('<%s>%s</%s>', $key, $val, $key);
                                        }
                                    }

                                    $teiHeader->addResponsible(join(' ', $nameParts), 'Contributor', 'persName');
                                }
                                else {
                                    $name = $this->buildTeiValue($entity['displayname'], true);
                                    $teiHeader->addResponsible($name, 'Contributor', 'name');
                                }
                                break;

                            default:
                                die('TODO: handle relationship_typename ' . $entity['relationship_typename']);
                        }
                    }
                }
            }
        }

        foreach ( [
                'ca_objects.date' => 'dates_value',
                'ca_objects.coverageDates' => 'coverageDates',
            ] as $src => $dst)
        {
            if (array_key_exists($src, $data)) {
                foreach ($languages as $lang) {
                    foreach ($data[$src] as $struct) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang][$dst], false);
                            if (!empty($val)) {
                                // let's see if it is a date string like February 23 1893
                                $dateInfo = date_parse($val);
                                if (0 == $dateInfo['error_count'] && 0 != $dateInfo['month']) {
                                    $val = sprintf('%04d-%02d-%02d',
                                                   $dateInfo['year'], $dateInfo['month'], $dateInfo['day']);
                                }
                            }

                            $method = null;

                            switch ($src) {
                                case 'ca_objects.date':
                                    $dcDatesType = array_key_exists('dc_dates_types', $struct[$lang])
                                        ? $struct[$lang]['dc_dates_types']
                                        : 'Date created';

                                    switch ($dcDatesType) {
                                        case 'Date created':
                                            $method = 'setDateCreation';
                                            break;
                                    }

                                    break;

                                case 'ca_objects.coverageDates':
                                    $teiHeader->setSettingDate($val);
                                    break;
                            }

                            if (!empty($method)) {
                                $teiHeader->$method($val);
                            }

                            break 2;
                        }
                    }
                }
            }
        }

        foreach ( [ 'ca_objects.rights' => 'rightsText' ] as $src => $dst) {
            if (array_key_exists($src, $data)) {
                foreach ($languages as $lang) {
                    foreach ($data[$src] as $struct) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang][$dst], false);
                            if (!empty($val)) {
                                $teiHeader->setSourceDescBibl($val);
                            }

                            if (!empty($struct[$lang]['copyrightStatement'])) {
                                $target = $this->buildLicenceTarget($struct[$lang]['copyrightStatement']);
                                if (!empty($target)) {
                                    $teiHeader->setLicenceTarget($target);
                                }
                            }

                            break 2;
                        }
                    }
                }
            }
        }

        foreach ([
                  'ca_objects.lcsh_terms' => 'lcsh_terms',
            ] as $src => $dst)
        {
            if (array_key_exists($src, $data)) {
                foreach ($data[$src] as $struct) {
                    foreach ($languages as $lang) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang][$dst], false);
                            switch ($dst) {
                                case 'lcsh_terms':
                                    $scheme = '#term';
                                    // TODO: resolve $val with something like [info:lc/authorities/names/n80076591]
                                    break;
                            }

                            $teiHeader->addClassCode($scheme, $val);
                            break;
                        }
                    }
                }
            }
        }

        return $teiHeader;
    }

    protected function buildFigures($data, $locale)
    {
        $figures = [];

        if (!array_key_exists('representations', $data)) {
            return $figures;
        }

        foreach ($data['representations'] as $representation) {
            /*
            // if we only want first one
            if (!$representation['is_primary']) {
                continue;
            }
            */

            $figures[] = $representation;
        }

        return $figures;
    }

    protected function buildFigureFacs($figure)
    {
        $facs = $figure['urls']['original'];

        $path = parse_url($figure['urls']['original'], PHP_URL_PATH);
        $corresp = basename($path);

        if (!empty($figure['original_filename'])) {
            $corresp = $figure['original_filename'];
        }

        return [
            'facs' => $facs,
            'corresp' => $corresp,
        ];
    }

    /**
     * @Route("/collective-access/{id}.tei.xml", name="ca-detail-tei", requirements={"id" = "[0-9]+"})
     * @Route("/collective-access/{id}", name="ca-detail", requirements={"id" = "[0-9]+"})
     */
    public function detailAction(Request $request,
                                 $id,
                                 \App\Service\CollectiveAccessService $caService)
    {
        $caItemService = $caService->getItemService($id);

        // seemingly doesn't make a difference
        $locale = $request->getLocale();
        if (array_key_exists($locale, self::$LOCALE_MAP)) {
            $caItemService->setLang(self::$LOCALE_MAP[$locale]);
        }

        $result = $caItemService->request();
        if (!$result->isOk()) {
            return $this->redirect($this->generateUrl('ca-home'));
        }

        $teiHeader = $this->buildTeiHeader($result->getRawData(), $request->getLocale(), $caService);

        $figures = $this->buildFigures($result->getRawData(), $request->getLocale());

        if ('ca-detail-tei' == $request->get('_route')) {
            $content = $this->getTeiSkeleton();

            if (false !== $content) {
                $data = $teiHeader->jsonSerialize();

                $teiHelper = new \App\Utils\TeiHelper();
                $tei = $teiHelper->adjustHeaderString($content, $data);

                if (!empty($figures)) {
                    $body = $tei('//tei:body')[0];
                    $fragment = $tei->createDocumentFragment();
                    foreach ($figures as $figure) {
                        $facsInfo = $this->buildFigureFacs($figure);
                        $fragment->appendXML(sprintf('<p><figure facs="%s" corresp="%s"></figure></p>',
                                                     htmlspecialchars($facsInfo['facs'], ENT_XML1, 'utf-8'),
                                                     htmlspecialchars($facsInfo['corresp'], ENT_XML1, 'utf-8')));
                   }

                    (new \FluentDOM\Nodes\Modifier($body))
                        ->replaceChildren($fragment);
                }

                $response = new Response($this->prettyPrintTei($tei->saveXML()));
                $response->headers->set('Content-Type', 'xml');

                return $response;
            }
        }

        return $this->render('CollectiveAccess/detail.html.twig', [
            'item' => $teiHeader,
            'figures' => $figures,
            'raw' => $result->getRawData(),
        ]);
    }
}

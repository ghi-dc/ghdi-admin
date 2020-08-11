<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

use App\Service\CollectiveAccessService;
use App\Service\ExistDbClientService;
use App\Utils\PandocProcessor;
use App\Utils\XmlPrettyPrinter\XmlPrettyPrinter;

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

    protected $pandocProcessor;
    protected $termChoicesByUri = [];

    public function __construct(ExistDbClientService $existDbClientService,
                                KernelInterface $kernel,
                                XmlPrettyPrinter $teiPrettyPrinter,
                                string $siteKey,
                                PandocProcessor $pandocProcessor)
    {
        parent::__construct($existDbClientService, $kernel, $teiPrettyPrinter, $siteKey);

        $this->pandocProcessor = $pandocProcessor;
    }

    protected function getTermChoicesByUri($locale)
    {
        if (!array_key_exists($locale, $this->termChoicesByUri)) {
            $this->termChoicesByUri[$locale] = $this->buildTermChoices($locale);
        }

        return $this->termChoicesByUri[$locale];
    }

    /**
     * @Route("/collective-access", name="ca-list")
     */
    public function homeAction(Request $request,
                               CollectiveAccessService $caService)
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
     */
    protected function htmlFragmentToTei($htmlFragment, $omitPara = false)
    {
        $xhtml = $this->htmlFragmentToXhtml($htmlFragment, $omitPara);

        // TODO: Switch to Pandoc Converter
        $tei = $this->pandocProcessor->convertHtmlFragmentToTeiSimple($xhtml);
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
            return $this->xmlSpecialchars($this->decodeHtmlEntity(trim($raw)));
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
            if (preg_match('/^by[\-\s]*nc[\-\s]*nd/i', $additional)) {
                // start with generic by-nc-nd 4.0
                $target = 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
                $rest = trim(preg_replace('/^by[\-\s]*nc[\-\s]*nd/i', '', $additional));
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
            else if (preg_match('/^by[\-\s]*nc[\-\s]*sa/i', $additional)) {
                // start with generic by-nc-sa 4.0
                $target = 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
                $rest = trim(preg_replace('/^by[\-\s]*nc[\-\s]*sa/i', '', $additional));
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
            else if (preg_match('/^by[\-\s]*sa/i', $additional)) {
                // start with generic by-sa 4.0
                $target = 'https://creativecommons.org/licenses/by-sa/4.0/';
                $rest = trim(preg_replace('/^by[\-\s]*sa/i', '', $additional));
                if (!empty($rest)) {
                    if (preg_match('/^4/', $rest)) {
                        // we are done
                    }
                    else if (preg_match('/^3/', $rest)) {
                        $target = 'https://creativecommons.org/licenses/by-sa/3.0/';
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

    protected function buildTeiHeader($data,
                                      TranslatorInterface $translator,
                                      CollectiveAccessService $caService,
                                      $locale,
                                      $addMissingTerm = false)
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


        $parsedown = new \Parsedown();

        foreach ([ 'preferred_labels' => 'title' ] as $src => $dst) {
            if (array_key_exists($src, $data)) {
                $struct = & $data[$src];
                foreach ($languages as $lang) {
                    if (array_key_exists($lang, $struct)) {
                        $valueHtml = $parsedown->line($struct[$lang][0]['name']);
                        $val = $this->buildTeiValue($valueHtml, true);
                        $method = 'set' . ucfirst($dst);
                        $teiHeader->$method($val);

                        break;
                    }
                }
            }
        }

        foreach ([ 'ca_objects.description' => 'note' ] as $src => $dst) {
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
            foreach ([ 'ca_entities', 'ca_list_items' ] as $relation) {
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

                            case 'depicts':
                                // ignore, this associates section
                                break;

                            case 'is described by':
                                $term = $this->lookupTerm($caService, $entity, $locale);
                                if (!empty($term)) {
                                    $key = array_key_first($term);
                                    if ('' === $key) {
                                        // lookup failed
                                        if ($addMissingTerm) {
                                            // add label
                                            $teiHeader->addTerm($term[$key]);
                                        }
                                    }
                                    else {
                                        $teiHeader->addTerm($key);
                                    }
                                }

                                break;

                            default:
                                die('TODO: handle relationship_typename ' . $entity['relationship_typename']);
                        }
                    }
                }
            }
        }

        foreach ([
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

        foreach ([ 'ca_objects.rights' => 'rightsText' ] as $src => $dst) {
            if (array_key_exists($src, $data)) {
                foreach ($languages as $lang) {
                    foreach ($data[$src] as $struct) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang][$dst], false);
                            if (!empty($val)) {
                                $teiHeader->setSourceDescBibl($val);
                            }

                            if (!empty($struct[$lang]['rightsHolder'])) {
                                $val = $this->buildTeiValue($struct[$lang]['rightsHolder'], false);
                                if (!empty($val)) {
                                    $teiHeader->setLicence($val);
                                }
                            }

                            if (!empty($struct[$lang]['copyrightStatement'])) {
                                $target = $this->buildLicenceTarget($struct[$lang]['copyrightStatement']);
                                if (!empty($target)) {
                                    $teiHeader->setLicenceTarget($target);
                                    $license = $teiHeader->getLicence();
                                    if (empty($license)) {
                                        switch ($target) {
                                            case 'https://creativecommons.org/publicdomain/zero/1.0/':
                                                $teiHeader->setLicence($translator->trans('This work has been identified as being free of known restrictions under copyright law, including all related and neighboring rights.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by-sa/3.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution-ShareAlike 3.0 License.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by-sa/4.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution-ShareAlike 4.0 License.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by-nc-sa/4.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution-NonCommercial-ShareAlike 4.0 License. Only noncommercial uses of the work are permitted.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by-nc-nd/4.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 License. Only noncommercial uses of the work are permitted. You may not distribute modified versions.'));
                                                break;

                                            default:
                                                die('TODO: license text for ' . $target);
                                        }
                                    }
                                }
                            }

                            break 2;
                        }
                    }
                }
            }
        }

        /*
        // we now use keywords
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
        */

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

    protected function lookupTerm(CollectiveAccessService $caService,
                                  $entity, $locale)
    {
        // TODO: add some caching
        $caItemService = $caService->getItemService($entity['item_id'], 'ca_list_items');

        // get specific properties as by https://docs.collectiveaccess.org/wiki/Label_Bundles
        $caItemService->setRequestBody([
            "bundles" => [
                "ca_list_items.item_id" => [],
                "ca_list_items.preferred_labels.name_singular" => [],
                "ca_list_items.preferred_labels.name_plural" => [],
                "ca_lists.list_code" => [],
            ],
        ]);

        // get the localized value
        if (array_key_exists($locale, self::$LOCALE_MAP)) {
            $caItemService->setLang(self::$LOCALE_MAP[$locale]);
        }

        $result = $caItemService->request();
        $data = $result->getRawData();

        if ($data['ca_lists.list_code'] == 'is-knowledge-sections') {
            return;
        }

        $label = null;
        foreach ([ 'name_plural', 'name_singular' ] as $lookup) {
            $key = 'ca_list_items.preferred_labels.' . $lookup;
            if (!empty($data[$key])) {
                $choice = array_search($data[$key], $this->getTermChoicesByUri($locale));
                if (false !== $choice) {
                    return [ $choice => $data[$key] ];
                }

                if (is_null($label)) {
                    $label = $data[$key];
                }
            }
        }

        if (!is_null($label)) {
            return [ '' => $label ];
        }
    }

    /**
     * @Route("/collective-access/{id}.tei.xml", name="ca-detail-tei", requirements={"id" = "[0-9]+"})
     * @Route("/collective-access/{id}", name="ca-detail", requirements={"id" = "[0-9]+"})
     */
    public function detailAction(Request $request,
                                 TranslatorInterface $translator,
                                 CollectiveAccessService $caService,
                                 $id)
    {
        $caItemService = $caService->getItemService($id);

        $result = $caItemService->request();
        if (!$result->isOk()) {
            return $this->redirect($this->generateUrl('ca-list'));
        }

        $teiHeader = $this->buildTeiHeader($result->getRawData(),
                                           $translator,
                                           $caService,
                                           $request->getLocale(),
                                           'ca-detail' == $request->get('_route'));

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
            'termChoices' => $this->getTermChoicesByUri($request->getLocale()),
            'raw' => $result->getRawData(),
        ]);
    }
}

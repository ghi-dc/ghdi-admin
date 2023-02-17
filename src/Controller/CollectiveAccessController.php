<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

use Asika\Autolink\Linker;

use Cocur\Slugify\SlugifyInterface;

use App\Service\CollectiveAccessService;
use App\Service\ExistDbClientService;
use App\Service\SanitizationService;
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
    protected $sanitizationService;
    protected $termChoicesByUri = [];

    public function __construct(ExistDbClientService $existDbClientService,
                                KernelInterface $kernel,
                                XmlPrettyPrinter $teiPrettyPrinter,
                                string $siteKey, string $sequenceStart,
                                PandocProcessor $pandocProcessor,
                                SanitizationService $sanitizationService)
    {
        parent::__construct($existDbClientService, $kernel, $teiPrettyPrinter,
                            $siteKey, $sequenceStart);

        $this->pandocProcessor = $pandocProcessor;
        $this->sanitizationService = $sanitizationService;
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

    protected function htmlFragmentToXhtml($htmlFragment, $omitPara = false, $autolink = false)
    {
        if (!$omitPara && preg_match('#(?:<br\s*/?>\s*?){2,}#', $htmlFragment)) {
            if (!preg_match('#^<p>(.*?)</p>$#s', $htmlFragment)) {
                $htmlFragment = '<p>' . $htmlFragment . '</p>';
            }

            // convert double br to <p>
            $htmlFragment = preg_replace('#(?:<br\s*/?>\s*?){2,}#', '</p><p>', $htmlFragment);
        }

        if ($autolink) {
            $htmlFragment = $this->autolink($htmlFragment);
        }

        $xhtml = $this->sanitizationService->sanitizeHtml($htmlFragment);

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
    protected function htmlFragmentToTei($htmlFragment, $omitPara = false, $autolink = false)
    {
        $xhtml = $this->htmlFragmentToXhtml($htmlFragment, $omitPara, $autolink);

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

    /**
     * Try to guess if a string is plain text or HTML
     */
    protected function hasHtmlTag($val)
    {
        return strip_tags($val) !== $val;
    }

    protected function decodeHtmlEntity($val)
    {
        return html_entity_decode($val, ENT_QUOTES, 'utf-8');
    }

    /**
     * Add <a> or <ref> around plain-text URLs
     */
    protected function autolink($markup, $mode = 'html')
    {
        $tag = 'a'; $attribute = 'href';
        if ('tei' == $mode) {
            $tag = 'ref';
            $attribute = 'target';
        }

        $autolinker = new \Asika\Autolink\Autolink;

        // see https://github.com/asika32764/php-autolink/#link-builder
        $autolinker->setLinkBuilder(function($url, $attribs) use ($tag, $attribute) {
            $attribs = [ $attribute => $url ];

            return (string) new \Windwalker\Dom\HtmlElement($tag, $url, $attribs);
        });

        // Collective Acess replaces url encoded characters with diacritics
        // $autolinker->convert() doesn't pick these up, so call rawurlencode() before
        $schemeRegex = sprintf("(%s)\:\/\/", $autolinker->getSchemes(true));
        $reRegular = "[\/a-zA-Z0-9\-._~:?#\[\]@!$&'()*+,;=%\">]";
        $reDiacritic = '[\x{00c0}-\x{00ff}]';
        $reRegularOrDiacritic = "[\/a-zA-Z0-9\-._~:?#\[\]@!$&'()*+,;=%\">\x{00c0}-\x{00ff}]";

        $regex = '/(([a-zA-Z]*=")*' . $schemeRegex . "[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,3}({$reRegularOrDiacritic}*)?)/u";

        $markup = preg_replace_callback($regex, function ($matches) use ($reDiacritic) {
                $url = $matches[0];

                if (preg_match('/' . $reDiacritic . '/u', $url)) {
                    $parts = parse_url($url);

                    $parts['path'] = implode('/', array_map(function ($v) {
                        return rawurlencode($v);
                    }, explode('/', $parts['path'])));

                    // reverse parse_url: https://gist.github.com/Ellrion/f51ba0d40ae1d62eeae44fd1adf7b704
                    $scheme   = isset($parts['scheme']) ? ($parts['scheme'] . '://') : '';

                    $host     = ($parts['host'] ?? '');
                    $port     = isset($parts['port']) ? (':' . $parts['port']) : '';

                    $user     = ($parts['user'] ?? '');

                    $pass     = isset($parts['pass']) ? (':' . $parts['pass'])  : '';
                    $pass     = ($user || $pass) ? "$pass@" : '';

                    $path     = ($parts['path'] ?? '');
                    $query    = isset($parts['query']) ? ('?' . $parts['query']) : '';
                    $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';

                    return implode('', [$scheme, $user, $pass, $host, $port, $path, $query, $fragment]);
                }

                return $url;
            }, $markup);

        return $autolinker->convert($markup);
    }

    protected function buildTeiValue($raw, $omitPara = false, $autolink = false)
    {
        // check if it is html or plain text
        if (!$this->hasHtmlTag($raw)) {
            $tei = $this->xmlSpecialchars($this->decodeHtmlEntity(trim($raw)));

            if ($autolink) {
                $tei = $this->autolink($tei, 'tei');
            }

            return $tei;
        }

        return $this->htmlFragmentToTei($raw, $omitPara, $autolink);
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
            else if (preg_match('/^by/i', $additional)) {
                // start with generic by 4.0
                $target = 'https://creativecommons.org/licenses/by/4.0/';
                $rest = trim(preg_replace('/^by/i', '', $additional));
                if (!empty($rest)) {
                    if (preg_match('/^4/', $rest)) {
                        // we are done
                    }
                    else if (preg_match('/^3/', $rest)) {
                        $target = 'https://creativecommons.org/licenses/by/3.0/';
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

    /**
     * Build a TEI representation from the Collective Access Entry
     */
    protected function buildTei($data,
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

        $teiHeader = new \App\Entity\TeiFull();

        $genre = 'image';
        if (array_key_exists('type_id', $data)
            && array_key_exists('display_text', $data['type_id'])
            && array_key_exists('en_US', $data['type_id']['display_text']))
        {
            switch ($data['type_id']['display_text']['en_US']) {
                case 'Sound':
                    $genre = 'audio';
                    break;

                case 'Moving Image':
                    $genre = 'video';
                    break;
            }
        }

        $teiHeader->setGenre($genre);
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

        foreach ([
                'ca_objects.description' => 'note',
                'ca_objects.description_source' => 'body',
            ] as $src => $dst)
        {
            if (array_key_exists($src, $data)) {
                foreach ($languages as $lang) {
                    foreach ($data[$src] as $struct) {
                        if (array_key_exists($lang, $struct) && !empty($struct[$lang])) {
                            $keyParts = explode('.', $src, 2);
                            $val = $this->buildTeiValue($struct[$lang][$keyParts[1]], false, 'body' == $dst);

                            if ('note' == $dst && !preg_match('#^<p>(.*?)</p>$#s', $val)) {
                                $val = '<p>' . $val . '</p>';
                            }

                            $method = 'set' . ucfirst($dst);
                            $teiHeader->$method($val);

                            break 2;
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
                                $person = $this->lookupPerson($caService, $entity, $locale);
                                /*
                                if (array_key_exists('forename', $entity)) {
                                    // assume a structured name
                                    $nameParts = [];
                                    foreach ([ 'forename', 'surname' ] as $key) {
                                        if (!empty($entity[$key])) {
                                            $val = $this->buildTeiValue($entity[$key], true);
                                            $nameParts[] = sprintf('<%s>%s</%s>',
                                                                   $key, $val, $key);
                                        }
                                    }

                                    $name = sprintf('<persName>%s</persName>', join(' ', $nameParts));
                                }
                                else {
                                    $name = $this->buildTeiValue($entity['displayname'], true);
                                }
                                */

                                $persName = $person->teiSerializePersName();
                                if (!empty($persName)) {
                                    $teiHeader->addAuthor($persName);
                                }

                                break;

                            case 'contributor':
                                if (array_key_exists('forename', $entity) || array_key_exists('surname', $entity)) {
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
                        if (array_key_exists($lang, $struct) && array_key_exists($dst, $struct[$lang])) {
                            $val = $this->buildTeiValue($struct[$lang][$dst], false);
                            if (!empty($val)) {
                                // let's see if it is a date string like February 23 1893
                                $dateInfo = date_parse($val);
                                if (0 == $dateInfo['error_count'] && 0 != $dateInfo['month']) {
                                    $val = sprintf('%04d-%02d-%02d',
                                                   $dateInfo['year'], $dateInfo['month'], $dateInfo['day']);
                                }
                                else {
                                    // catch things like 18th century
                                    if (preg_match('/^(\d+)th century$/i', $val, $matches)) {
                                        $val = $translator->trans('%century%th century', [
                                            '%century%' => $matches[1],
                                        ]);
                                    }
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
                                            $method = 'setDateCreated';
                                            break;
                                    }

                                    break;

                                case 'ca_objects.coverageDates':
                                    $teiHeader->setTemporalCoverage($val);
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
                        if (array_key_exists($lang, $struct) &&
                            !empty($struct[$lang])
                            && (array_key_exists($dst, $struct[$lang]) || array_key_exists('rightsHolder', $struct[$lang])))
                        {
                            if (!empty($struct[$lang][$dst])) {
                                $raw = $struct[$lang][$dst];

                                /* we prefix Source: */
                                $raw = $translator->trans('Source') . ': ' . $raw;

                                $val = $this->buildTeiValue($raw, false, true);
                                if (!empty($val)) {
                                    $teiHeader->setSourceDescBibl($val);
                                }
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
                                    $licence = $teiHeader->getLicence();
                                    if (empty($licence)) {
                                        switch ($target) {
                                            case 'https://creativecommons.org/publicdomain/zero/1.0/':
                                                $teiHeader->setLicence($translator->trans('The work has been dedicated to the public domain by waiving all rights under copyright law, including all related and neighboring rights, to the extent allowed by law.'));
                                                break;

                                            case 'https://creativecommons.org/publicdomain/mark/1.0/':
                                                $teiHeader->setLicence($translator->trans('This work has been identified as being free of known restrictions under copyright law, including all related and neighboring rights.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by/3.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution 3.0 License.'));
                                                break;

                                            case 'https://creativecommons.org/licenses/by/4.0/';
                                                $teiHeader->setLicence($translator->trans('This work is licensed under the Creative Commons Attribution 4.0 License.'));
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

        return $teiHeader;
    }

    protected function lookupFigureCaption(CollectiveAccessService $caService, $representation, $locale)
    {
        $caItemService = $caService->getItemService($representation['representation_id'], 'ca_object_representations');

        // get specific properties as by https://docs.collectiveaccess.org/wiki/Label_Bundles
        $caItemService->setRequestBody([
            'bundles' => [
                'ca_object_representations.representation_id' => [],
                'ca_object_representations.description' => [],
            ],
        ]);

        // get the localized value
        if (array_key_exists($locale, self::$LOCALE_MAP)) {
            $caItemService->setLang(self::$LOCALE_MAP[$locale]);
        }

        $result = $caItemService->request();
        $data = $result->getRawData();

        if (!empty($data['ca_object_representations.description'])) {
            return $this->htmlFragmentToTei($data['ca_object_representations.description']);
        }
    }

    protected function buildFigures(CollectiveAccessService $caService, $data, $locale, $primaryOnly = false)
    {
        $figures = [];

        if (!array_key_exists('representations', $data)) {
            return $figures;
        }

        $idxAV = -1;

        foreach ($data['representations'] as $representation) {
            // if we only want primary one
            if ($primaryOnly && !$representation['is_primary']) {
                continue;
            }

            // skip newly created access: 'internal archiv only': 10
            if (array_key_exists('access', $representation) && 10 == $representation['access']) {
                continue;
            }

            $figureCaption = $this->lookupFigureCaption($caService, $representation, $locale);

            if (in_array($representation['mimetype'], [ 'audio/mpeg', 'video/mp4' ])) {
                // set $idxAV if there is exactly one AV so we might merge possible poster-figure into single figure
                if (-1 == $idxAV) {
                    $idxAV = count($figures);
                }
                else {
                    // set back if there is more than one AV, so $idxAV has already been set
                    $idxAV = -1;
                }
            }
            else if (in_array($representation['mimetype'], [ 'image/jpeg', 'text/xml' ])) {
                // Map SVG as well as JPEGs can be localized
                if (preg_match('/\.(deu|eng)\.(jpe?g|svg)$/', $representation['original_filename'], $matches)) {
                    if ($matches[1] != \App\Utils\Iso639::code1To3($locale)) {
                        continue;
                    }
                }
            }

            $figures[] = $representation + [ 'caption' => $figureCaption ];
        }

        // if there is exactly one AV and one image, assign image to AV as poster
        if ($idxAV != -1 && count($figures) == 2) {
            $av = $figures[$idxAV];

            $idxFigure = 0 == $idxAV ? 1 : 0; // it is the other one
            $figure = $figures[$idxFigure];
            if (!empty($figure['caption'])) {
                $av['caption'] = (empty($av['caption']) ? '' : $av['caption'])
                    . $figure['caption'];
            }

            $figures = [ $av +  [ 'poster' => $figure ] ];
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
     *
     */
    protected function lookupPerson(CollectiveAccessService $caService,
                                    $entity, $locale)
    {
        // TODO: add some caching
        $caItemService = $caService->getItemService($entity['entity_id'], 'ca_entities');

        // get specific properties as by https://docs.collectiveaccess.org/wiki/Label_Bundles
        $caItemService->setRequestBody([
            'bundles' => [
                'ca_entities.preferred_labels.displayname' => [],
                'ca_entities.preferred_labels.forename' => [],
                'ca_entities.preferred_labels.surname' => [],
                'ca_entities.preferred_labels.other_forenames' => [],
                'ca_entities.preferred_labels.middlename' => [],
                'ca_entities.preferred_labels.prefix' => [],
                'ca_entities.preferred_labels.suffix' => [],
            ],
        ]);

        // get the localized value
        if (array_key_exists($locale, self::$LOCALE_MAP)) {
            $caItemService->setLang(self::$LOCALE_MAP[$locale]);
        }

        $result = $caItemService->request();
        $data = $result->getRawData();

        $givenNameParts = [];
        $familyNameParts = [];
        foreach ([ 'prefix', 'forename', 'other_forname', 'middlename', 'surname', 'suffix' ] as $lookup) {
            $key = 'ca_entities.preferred_labels.' . $lookup;
            if (!empty($data[$key])) {
                if (in_array($lookup, [ 'surname', 'suffix' ])) {
                    $familyNameParts[] = $data[$key];
                }
                else {
                    $givenNameParts[] = $data[$key];
                }
            }
        }

        $person = new \App\Entity\Person();
        if (!empty($givenNameParts)) {
            $person->setGivenName(join(' ', $givenNameParts));
            if (!empty($familyNameParts)) {
                $person->setFamilyName(join(' ', $familyNameParts));
            }
        }
        else {
            // unstructured
            $person->setName(array_key_exists('ca_entities.preferred_labels.displayname', $data)
                             ? $data['ca_entities.preferred_labels.displayname']
                             : $entity['displayname']);
        }

        return $person;
    }

    /**
     *
     */
    protected function lookupTerm(CollectiveAccessService $caService,
                                  $entity, $locale)
    {
        // TODO: add some caching
        $caItemService = $caService->getItemService($entity['item_id'], 'ca_list_items');

        // get specific properties as by https://docs.collectiveaccess.org/wiki/Label_Bundles
        $caItemService->setRequestBody([
            'bundles' => [
                'ca_list_items.item_id' => [],
                'ca_list_items.preferred_labels.name_singular' => [],
                'ca_list_items.preferred_labels.name_plural' => [],
                'ca_lists.list_code' => [],
            ],
        ]);

        // get the localized value
        if (array_key_exists($locale, self::$LOCALE_MAP)) {
            $caItemService->setLang(self::$LOCALE_MAP[$locale]);
        }

        $result = $caItemService->request();
        $data = $result->getRawData();

        if (array_key_exists('ca_lists.list_code', $data)
            && $data['ca_lists.list_code'] == 'is-knowledge-sections')
        {
            return;
        }

        $label = null;
        foreach ([ 'name_plural', 'name_singular' ] as $lookup) {
            $key = 'ca_list_items.preferred_labels.' . $lookup;
            if (!empty($data[$key])) {
                // call decodeHtmlEntity to handle $data[$key] like 'Pogrom Night &lt;1938&gt;'
                $value = $this->decodeHtmlEntity($data[$key]);

                $choice = array_search($value, $this->getTermChoicesByUri($locale));
                if (false !== $choice) {
                    return [ $choice => $value ];
                }

                if (is_null($label)) {
                    $label = $value;
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
                                 SlugifyInterface $slugify,
                                 $id)
    {
        $caItemService = $caService->getItemService($id);

        $result = $caItemService->request();
        if (!$result->isOk()) {
            return $this->redirect($this->generateUrl('ca-list'));
        }

        $teiFull = $this->buildTei($result->getRawData(),
                                   $translator,
                                   $caService,
                                   $request->getLocale(),
                                   'ca-detail' == $request->get('_route'));

        $figures = $this->buildFigures($caService, $result->getRawData(), $request->getLocale());

        if ($teiFull->getGenre() == 'image' && !empty($figures)) {
            // genre might not be set correctly in CollectiveAccess
            // possibly re-assign from figures
            if (preg_match('/^audio/', $figures[0]['mimetype'])) {
                $isAudio = true;
                for ($i = 1; $i < count($figures); $i++) {
                    if (!preg_match('/^audio/', $figures[$i]['mimetype'])) {
                        $isAudio = false;
                        break;
                    }
                }

                if ($isAudio) {
                    $teiFull->setGenre('audio');
                }
            }

            if (preg_match('/^video/', $figures[0]['mimetype'])) {
                $isVideo = true;
                for ($i = 1; $i < count($figures); $i++) {
                    if (!preg_match('/^video/', $figures[$i]['mimetype'])) {
                        $isVideo = false;
                        break;
                    }
                }

                if ($isVideo) {
                    $teiFull->setGenre('video');
                }
            }
        }

        if ('ca-detail-tei' == $request->get('_route')) {
            $skeleton = $this->getTeiSkeleton();

            if (false !== $skeleton) {
                $teiHelper = new \App\Utils\TeiHelper();
                $tei = $teiHelper->patchHeaderString($skeleton, $teiFull->jsonSerialize());

                $body = $teiFull->getBody();

                if (!empty($figures) || !empty($body)) {
                    $bodyNode = $tei('//tei:body')[0];
                    $fragment = $tei->createDocumentFragment();

                    if (!empty($figures)) {
                        $figureTags = [];

                        foreach ($figures as $figure) {
                            $facsInfo = $this->buildFigureFacs($figure);

                            if (in_array($figure['mimetype'], [ 'audio/mpeg', 'video/mp4' ])) {
                                $attrFigure = '';

                                if (!empty($figure['poster'])) {
                                    $posterInfo = $this->buildFigureFacs($figure['poster']);
                                    $attrFigure = sprintf(' facs="%s" corresp="%s"',
                                                        htmlspecialchars($posterInfo['facs'], ENT_XML1, 'utf-8'),
                                                        htmlspecialchars($posterInfo['corresp'], ENT_XML1, 'utf-8'));
                                }

                                $figureTags[] = sprintf('<figure%s><media mimeType="%s" url="%s" corresp="%s" />%s</figure>',
                                                        $attrFigure,
                                                        htmlspecialchars($figure['mimetype'], ENT_XML1, 'utf-8'),
                                                        htmlspecialchars($facsInfo['facs'], ENT_XML1, 'utf-8'),
                                                        htmlspecialchars($facsInfo['corresp'], ENT_XML1, 'utf-8'),
                                                        !empty($figure['caption']) ? $figure['caption'] : '');
                            }
                            else {
                                $figureTags[] = sprintf('<figure facs="%s" corresp="%s">%s</figure>',
                                                        htmlspecialchars($facsInfo['facs'], ENT_XML1, 'utf-8'),
                                                        htmlspecialchars($facsInfo['corresp'], ENT_XML1, 'utf-8'),
                                                        !empty($figure['caption']) ? $figure['caption'] : '');

                            }
                        }

                        // dd($figureTags);

                        $fragment->appendXML('<p' . (count($figureTags) > 1 ? ' class="gallery"' : '') . '>'
                                             . implode("\n", $figureTags)
                                             . '</p>');
                    }

                    if (!empty($body)) {
                        // further reading
                        $fragment->appendXML(sprintf('<div xml:id="%s" n="2">' . "\n"
                                                     . '<head>%s</head>' . "\n"
                                                     . '%s'
                                                     . "\n" . '</div>',
                                                     $slugify->slugify($translator->trans('Further Reading')),
                                                     $translator->trans('Further Reading'),
                                                     $body));
                    }

                    (new \FluentDOM\Nodes\Modifier($bodyNode))
                        ->replaceChildren($fragment);
                }

                $response = new Response($this->prettyPrintTei($tei->saveXML()));
                $response->headers->set('Content-Type', 'xml');

                return $response;
            }
        }

        return $this->render('CollectiveAccess/detail.html.twig', [
            'item' => $teiFull,
            'figures' => $figures,
            'termChoices' => $this->getTermChoicesByUri($request->getLocale()),
            'raw' => $result->getRawData(),
        ]);
    }
}

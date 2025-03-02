<?php

namespace App\Utils;

use Symfony\Component\HttpFoundation\Response;

trait RenderTeiTrait
{
    function removeByCssSelector($html, $selectorsToRemove, $removeBodyTag = false)
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        foreach ($selectorsToRemove as $selector) {
            $crawler->filter($selector)->each(function ($crawler) {
                foreach ($crawler as $node) {
                    $node->parentNode->removeChild($node);
                }
            });
        }

        return $removeBodyTag
            ? preg_replace('/<\/?body>/', '', $crawler->html())
            : $crawler->html();
    }

    protected function renderPdf($pdfConverter, $html, $filename = '', $locale = 'en')
    {
        /*
        // for debugging
        echo $html;
        exit;
        */

        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfConverter->SHYlang = $lang;
        $pdfConverter->SHYleftmin = 3;
        */

        $imageVars = [];
        try {
            // try to get logo from repository in order to support multiple sites with same code-base
            $client = $this->getExistDbClient($this->subCollection);
            $image = $client->getBinaryResource($this->getAssetsPath() . '/logo-print.' . $locale . '.jpg');
            if (false !== $image) {
                $imageVars['logo_top'] = $image;
            }
        }
        catch (\Exception $e) {
            // ignore
        }

        if (!array_key_exists('logo_top', $imageVars)) {
            // fall-back to file system
            $imageVars['logo_top'] = file_get_contents($this->getProjectDir() . '/public/img/logo-print.' . $locale . '.jpg');
        }

        if (!empty($imageVars)) {
            $pdfConverter->setOption('imageVars', $imageVars);
        }

        $htmlDoc = new HtmlDocument();
        $htmlDoc->loadString($html);

        $pdfDoc = @$pdfConverter->convert($htmlDoc);

        return new Response((string) $pdfDoc, Response::HTTP_OK, [
            'Content-Type'          => 'application/pdf',
            'Content-Disposition'   => 'inline; filename="' . $filename . '"',
        ]);
    }

    protected function buildAbsoluteUrl($url, $baseUrl)
    {
        if (empty($baseUrl)) {
            return $url;
        }

        // check if url contains host
        $host = parse_url($url, PHP_URL_HOST);
        if (!empty($host)) {
            // nothing to prepend
            return $url;
        }

        return $baseUrl . '/' . $url;
    }

    protected function adjustMedia($html, $baseUrl, $imgClass = 'image-responsive')
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        $crawler->filter('audio > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildAbsoluteUrl($src, $baseUrl));
        });

        $crawler->filter('video > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildAbsoluteUrl($src, $baseUrl));
        });

        $crawler->filter('img')->each(function ($node, $i) use ($baseUrl, $imgClass) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $this->buildAbsoluteUrl($src, $baseUrl));
            if (!empty($imgClass)) {
                $node->getNode(0)->setAttribute('class', $imgClass);
            }
        });

        return $crawler->html();
    }

    /**
     * Set a span around certain combining characters in order to switch font in css.
     *
     * TODO: Keep in sync with method in AppExtension
     */
    protected function markCombiningCharacters($html)
    {
        // since it doesn't seem to possible to style the follwing with unicode-ranges
        // set span in order to set an alternate font-family

        // Unicode Character 'COMBINING MACRON' (U+0304)
        $html = preg_replace('/([n]\x{0304})/u', '<span class="combining">\1</span>', $html);

        // Unicode Character 'COMBINING LATIN SMALL LETTER E' (U+0364)
        return preg_replace('/([aou]\x{0364})/u', '<span class="combining">\1</span>', $html);
    }

    protected function adjustHtml($html, $baseUrlMedia)
    {
        // run even if there is nothing to remove since xslt creates
        // self-closing tags like <div/> which are not valid in HTML5
        $html = $this->removeByCssSelector($html, [
            // 'h2 + br',
            // 'h3 + br',
            // 'div#license',
        ], true);

        $html = $this->adjustMedia($html, $baseUrlMedia);

        // since it doesn't seem to possible to style this with unicode-range
        // set a span around Combining Characters so we can set an alternate font-family
        $html = $this->markCombiningCharacters($html);

        return $html;
    }
}

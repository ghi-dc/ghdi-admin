<?php

namespace App\Utils;

/**
 *
 *
 */
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

    protected function renderPdf($html, $filename = '', $dest = 'I', $locale = 'en')
    {
        /*
        // for debugging
        echo $html;
        exit;
        */

        // mpdf - TODO: move this to a service
        $pdfGenerator = new \App\Utils\PdfGenerator([
            'fontDir' => [
                $this->get('kernel')->getProjectDir()
                    . '/data/font',
            ],
            'fontdata' => [
                'brill' => [
                    'R' => 'Brill-Roman.ttf',
                    'B' => 'Brill-Bold.ttf',
                    'I' => 'Brill-Italic.ttf',
                    'BI' => 'Brill-Bold-Italic.ttf',
                ],
            ],
        ]);

        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfGenerator->SHYlang = $lang;
        $pdfGenerator->SHYleftmin = 3;
        */

        try {
            // try to get logo from repository in order to support multiple sites with same code-base
            $client = $this->getExistDbClient($this->subCollection);
            $pdfGenerator->imageVars['logo_top'] = $client->getBinaryResource($this->getAssetsPath() . '/logo-print.' . $locale . '.jpg');
        }
        catch (\Exception $e) {
            // fall-back to file system
            $pdfGenerator->imageVars['logo_top'] = $this->get('kernel')->getProjectDir() . '/public/img/logo-print.' . $locale . '.jpg';
        }

        // silence due to https://github.com/mpdf/mpdf/issues/302 when using tables
        @$pdfGenerator->writeHTML($html);

        $pdfGenerator->Output($filename, 'I');
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

    protected function markCombiningE($html)
    {
        // since it doesn't seem to possible to style this with unicode-range
        // set a span around Combining Latin Small Letter E so we can set an alternate font-family
        return preg_replace('/([aou]\x{0364})/u', '<span class="combining-e">\1</span>', $html);
    }

    protected function adjustHtml($html, $baseUrl = 'http://germanhistorydocs.ghi-dc.org/images/')
    {
        // run even if there is nothing to remove since xslt creates
        // self-closing tags like <div/> which are not valid in HTML5
        $html = $this->removeByCssSelector($html, [
            // 'h2 + br',
            // 'h3 + br',
            // 'div#license',
        ], true);

        $html = $this->adjustMedia($html, $baseUrl);

        // since it doesn't seem to possible to style this with unicode-range
        // set a span around Combining Latin Small Letter E so we can set an alternate font-family
        $html = $this->markCombiningE($html);

        return $html;
    }
}

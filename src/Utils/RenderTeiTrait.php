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
                    // var_dump($node);
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

        // mpdf
        $pdfGenerator = new \App\Utils\PdfGenerator([
            'fontDir' => [
                $this->get('kernel')->getProjectDir()
                    . '/data/font',
            ],
            'fontdata' => [
                'gentium' => [
                    'R' => 'GenBasR.ttf',
                    'B' => 'GenBasB.ttf',
                    'I' => 'GenBasI.ttf',
                    'BI' => 'GenBasBI.ttf',
                ],
            ],
            'default_font' => 'gentium',
        ]);

        /*
        // hyphenation
        list($lang, $region) = explode('_', $display_lang, 2);
        $pdfGenerator->SHYlang = $lang;
        $pdfGenerator->SHYleftmin = 3;
        */

        // imgs
        $fnameLogo = $this->get('kernel')->getProjectDir() . '/public/img/logo-small.' . $locale . '.gif';
        $pdfGenerator->imageVars['logo_top'] = file_get_contents($fnameLogo);

        // silence due to https://github.com/mpdf/mpdf/issues/302 when using tables
        @$pdfGenerator->writeHTML($html);

        $pdfGenerator->Output($filename, 'I');
    }

    protected function adjustMedia($html, $baseUrl, $imgClass = 'image-responsive')
    {
        $crawler = new \Symfony\Component\DomCrawler\Crawler();
        $crawler->addHtmlContent($html);

        $crawler->filter('audio > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        $crawler->filter('video > source')->each(function ($node, $i) use ($baseUrl) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
        });

        $crawler->filter('img')->each(function ($node, $i) use ($baseUrl, $imgClass) {
            $src = $node->attr('src');
            $node->getNode(0)->setAttribute('src', $baseUrl . '/' . $src);
            if (!empty($imgClass)) {
                $node->getNode(0)->setAttribute('class', $imgClass);
            }
        });

        return $crawler->html();
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

        return $html;
    }
}

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
}

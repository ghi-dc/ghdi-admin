<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

/**
 *
 */
class WordToTeiController
extends Controller
{
    /**
     * @Route("/upload", name="upload")
     */
    public function uploadAction(Request $request)
    {
        // replace this example code with whatever you need
        return $this->render('Upload/index.html.twig', [
        ]);
    }

    /**
     * @Route("/upload/handler", name="upload-handler")
     */
    public function wordToTeiAction(Request $request)
    {
        $output = [ 'uploaded' => false ];

        $file = $request->files->get('file');

        $officeDoc = new \App\Utils\BinaryDocument();
        $officeDoc->load($file->getRealPath());

        $pandocConverter = $this->get(\App\Utils\PandocConverter::class);

        // inject TeiFromWordCleaner
        $myTarget = new class()
        extends \App\Utils\TeiSimplePrintDocument
        {
            use \App\Utils\TeiFromWordCleaner;
        };

        $pandocConverter->setOption('target', $myTarget);

        $teiSimpleDoc = $pandocConverter->convert($officeDoc);

        $conversionOptions = [
            'prettyPrinter' => $this->get('app.tei-prettyprinter'),
            'language' => \App\Utils\Iso639::code1to3($request->getLocale()),
            'genre' => 'document', // todo: make configurable
        ];

        $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
        $teiDtabfDoc = $converter->convert($teiSimpleDoc);

        $output['uploaded'] = true;
        $output['valid'] = $teiDtabfDoc->validate($this->get('kernel')->getProjectDir() . '/data/schema/basisformat.rng');
        $output['content'] = (string)$teiDtabfDoc;

        return new JsonResponse($output);
    }
}

<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 *
 */
class WordToTeiController
extends BaseController
{
    /**
     * @Route("/convert", name="convert")
     */
    public function uploadAction(Request $request,
                                 \App\Utils\PandocConverter $pandocConverter)
    {
        if ($request->isMethod('post')) {
            $file = $request->files->get('file');

            if (is_null($file)) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', 'No upload found, please try again')
                    ;
            }
            else {
                $mime = $file->getMimeType();
                if (!in_array($mime, [
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/docx',
                    ]))
                {
                    $request->getSession()
                            ->getFlashBag()
                            ->add('error', "Uploaded file wasn't recognized as a Word-File (.docx)")
                        ;
                }
                else {
                    $officeDoc = new \App\Utils\BinaryDocument();
                    $officeDoc->load($file->getRealPath());

                    // inject TeiFromWordCleaner
                    $myTarget = new class()
                    extends \App\Utils\TeiSimplePrintDocument
                    {
                        use \App\Utils\TeiFromWordCleaner;
                    };

                    $pandocConverter->setOption('target', $myTarget);

                    $teiSimpleDoc = $pandocConverter->convert($officeDoc);

                    $conversionOptions = [
                        'prettyPrinter' => $this->getTeiPrettyPrinter(),
                        'language' => \App\Utils\Iso639::code1to3($request->getLocale()),
                        'genre' => 'document', // todo: make configurable
                    ];

                    $converter = new \App\Utils\TeiSimplePrintToDtabfConverter($conversionOptions);
                    $teiDtabfDoc = $converter->convert($teiSimpleDoc);

                    return new Response((string)$teiDtabfDoc, Response::HTTP_OK, [
                        'Content-Type' => 'text/xml',
                    ]);
                }
            }
        }

        // render upload form
        return $this->render('Upload/index.html.twig', []);
    }
}

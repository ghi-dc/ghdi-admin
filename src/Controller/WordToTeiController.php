<?php
// src/Controller/WordToTeiController.php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Upload form for Word to TEI conversion
 */
class WordToTeiController
extends BaseController
{
    #[Route(path: '/convert', name: 'convert')]
    public function uploadAction(Request $request,
                                 TranslatorInterface $translator,
                                 \App\Utils\PandocConverter $pandocConverter)
    {
        if ($request->isMethod('post')) {
            $file = $request->files->get('file');

            if (is_null($file)) {
                $request->getSession()
                        ->getFlashBag()
                        ->add('warning', $translator->trans('No upload found, please try again'))
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
                            ->add('danger', $translator->trans("The uploaded file wasn't recognized as a Word-File (.docx)"))
                        ;
                }
                else {
                    $myTarget = new class()
                    extends \App\Utils\TeiSimplePrintDocument
                    {
                        // inject TeiFromWordCleaner
                        use \App\Utils\TeiFromWordCleaner;
                    };

                    $pandocConverter->setOption('target', $myTarget);

                    $officeDoc = new \App\Utils\BinaryDocument();
                    $officeDoc->load($file->getRealPath());

                    $teiSimpleDoc = $pandocConverter->convert($officeDoc);

                    // TeiSimple to TeiDtabf
                    $converter = new \App\Utils\TeiSimplePrintToDtabfConverter([
                        'prettyPrinter' => $this->getTeiPrettyPrinter(),
                        'language' => \App\Utils\Iso639::code1to3($request->getLocale()),
                        'genre' => 'document', // TODO: make configurable for introduction
                        // 'postprocessor' => new \App\Utils\TeiRefProcessor(), // we lack volume info for building proper links
                    ]);
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

<?php
// src/Command/HtmlPurifierTestCommand.php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class HtmlPurifierTestCommand
extends Command
{
    protected function configure()
    {
        $this
            ->setName('test:htmlpurifier')
            ->setDescription('Test clean-up pasted from Word')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // alternatives might be https://gist.github.com/dave1010/674071
        // https://github.com/aaron-kenny/microsoft-word-to-html-converter/blob/master/app/assets/php/documentConverter.php
        $dirty_html = <<<EOT
<p style="margin:0in 0in .0001pt;"><span style="font-size:12pt;"><span style="font-family:Cambria, serif;">In 1915, after the outbreak of World War I, the Belgian artist, architect, and designer Henry van de Velde was forced to resign from his post as director of the Grand Ducal School of Arts and Crafts in Weimar (<i>Großherzoglich Sächsischen Kunstgewerbeschule</i>), which he had founded ten years earlier. Van de Velde recommended the young German architect Walter Gropius (1883-1969) as a potential successor. In 1919, after the war, Gropius was appointed as the new director of the school, which he then merged with the Weimar Academy of Fine Arts to form the Bauhaus. He went on to appoint famous artists such as Wassily Kandinsky and Paul Klee to the Bauhaus faculty. In developing the school’s signature pedagogy, he leaned on the theories of the Swiss painter and Bauhaus faculty member Johannes Itten. Innovative features of the Bauhaus curriculum included the Preliminary Course and the school’s synthesis of aesthetic principles derived from theory and practice. The excerpt here describes the Bauhaus curriculum in 1923, at the time of its first and only all-school exhibit, which was staged in various buildings throughout the city of Weimar.</span></span></p>
EOT;

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('AutoFormat.RemoveSpansWithoutAttributes', true);
        $config->set('CSS.AllowedProperties', []);

        $purifier = new \HTMLPurifier($config);
        $clean_html = $purifier->purify($dirty_html);

        echo $clean_html;
        return 0;
    }
}

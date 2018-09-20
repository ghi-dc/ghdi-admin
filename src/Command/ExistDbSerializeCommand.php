<?php

// src/Command/ExistDbTestCommand.php
namespace App\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class ExistDbSerializeCommand
extends ExistDbCommand
{
    protected function configure()
    {
        $this
            ->setName('existdb:serialize')
            ->setDescription('Test output:method "json";')
            ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getExistDbClient();

       // see https://exist-db.org/exist/apps/fundocs/view.html?uri=http://exist-db.org/xquery/system&location=java:org.exist.xquery.functions.system.SystemModule&details=true
       // for fn:serialize: https://exist-db.org/exist/apps/wiki/blogs/eXist/XQuery31

       $xql =
'xquery version "3.1";
declare namespace output="http://www.w3.org/2010/xslt-xquery-serialization";
declare namespace json="http://www.json.org";

(:~
 : Travers the sub collections of the specified root collection.
 :
 : @param $root the path of the root collection to process
 :)
declare function local:sub-collections($root as xs:string) {
    let $children := xmldb:get-child-collections($root)
    for $child in $children
    return
        <children json:array="true">
		{ local:collections(concat($root, "/", $child), $child) }
		</children>
};

(:~
 : Generate metadata for a collection. Recursively process sub collections.
 :
 : @param $root the path to the collection to process
 : @param $label the label (name) to display for this collection
 :)
declare function local:collections($root as xs:string, $label as xs:string) {
    (
        <title>{$label}</title>,
        <isFolder json:literal="true">true</isFolder>,
        <key>{$root}</key>,
        if (sm:has-access($root, "rx")) then
            local:sub-collections($root)
        else
            ()
    )
};

declare variable $collection external;

(: Switch to JSON serialization :)
declare option output:method "json";
declare option output:media-type "text/javascript";


<collection json:array="true">
    {local:collections($collection, replace($collection, "^.*/([^/]+$)", "$1"))}
</collection>
';

        $query = $client->prepareQuery($xql);
        $query->bindVariable('collection', $client->getCollection());

        $res = $query->execute();
        $result = $res->getNextResult();

        $output->writeln(json_encode(json_decode($result), JSON_PRETTY_PRINT));

        return 0;
    }
}

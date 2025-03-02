<?php

namespace App\Search;

use FS\SolrBundle\Doctrine\Hydration\HydrationModes;
use FS\SolrBundle\Doctrine\Mapper\MetaInformationInterface;
use FS\SolrBundle\SolrInterface;

class Repository extends \FS\SolrBundle\Repository\Repository
{
    /**
     * Custom constructor to adjust HydrationMode.
     */
    public function __construct(SolrInterface $solr, MetaInformationInterface $metaInformation)
    {
        parent::__construct($solr, $metaInformation);

        $this->hydrationMode = HydrationModes::HYDRATE_INDEX;
    }

    /* debugging */
    public function findBy(array $args): array
    {
        $query = $this->solr->createQuery($this->metaInformation->getEntity());
        $query->setHydrationMode($this->hydrationMode);
        $query->setRows(100000);
        $query->setUseAndOperator(true);
        $query->addSearchTerm('id', $this->metaInformation->getDocumentName() . '_*');
        $query->setQueryDefaultField('id');

        $helper = $query->getHelper();
        foreach ($args as $fieldName => $fieldValue) {
            $fieldValue = $helper->escapeTerm($fieldValue);

            $query->addSearchTerm($fieldName, $fieldValue);
        }

        return $this->solr->query($query);
    }
}

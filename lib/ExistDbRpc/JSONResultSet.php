<?php
namespace ExistDbRpc;

class JSONResultSet extends ResultSet
{
    public function __construct($client, $resultId, $options)
    {
        parent::__construct($client, $resultId, $options);
    }

    public function getNextResult()
    {
        $result = $this->client->retrieve(
                $this->resultId,
                $this->currentHit,
                $this->options
        );

        $this->currentHit++;
        $this->hasMoreHits = $this->currentHit < $this->hits;
        $doc = json_decode($result->getDecoded(), true);
        return $doc;
    }

    public function current()
    {
        $doc = json_decode($this->retrieve()->getDecoded(), true);
        return $doc;
    }
}

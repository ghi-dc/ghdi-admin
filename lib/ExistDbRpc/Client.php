<?php

namespace ExistDbRpc;

class Client
{
    /**
     * The URI to the database instance inclusive of the port
     *
     * @var $uri
     */
    protected $uri;

    /**
     * The xml rpc client for the instance
     *
     * @var $connection
     */
    protected $connection;
    protected $options;
    protected $collection = null;

    protected $query;

    protected function defaultOptionValue()
    {
    }

    public function __construct($options = null)
    {
		$defaults = [
			'protocol' => 'http',
			'user' => 'guest',
			'password' => 'guest',
			'host' => 'localhost',
			'port' => '8080',
			'path' => '/exist/xmlrpc/'
		];

        if (!is_null($options)) {
            if (isset($options['collection'])) {
                $this->setCollection($options['collection']);
            }

            foreach ($defaults as $part => $value) {
                if (!isset($options[$part])) {
                    $options[$part] = $value;
                }
            }
        } else {
			$options = $defaults;
            $this->uri = 'http://guest:guest@localhost:8080/exist/xmlrpc/';
        }

		if (isset($options['uri'])) {
			$this->uri = $options['uri'];
		} else {
			$this->uri = $options['protocol'].'://'
					   . $options['user'] . ':' . $options['password'] . '@'
					   . $options['host'] .':' . $options['port'] . $options['path'];
		}

        $this->conn = null;

        $httpClient = new \GuzzleHttp\Client();
        $client = new \fXmlRpc\Client(
            $this->uri,
            new \fXmlRpc\Transport\HttpAdapterTransport(
                new \Http\Message\MessageFactory\GuzzleMessageFactory(), // alternative is DiactorosMessageFactory
                new \Http\Adapter\Guzzle6\Client($httpClient)
            )
        );

        $this->client = new \fXmlRpc\Proxy($client);
    }

	public function getUri()
	{
		return $this->uri;
	}

	public function setCollection($collection)
	{
		if (!empty($collection)) {
			$collection = rtrim($collection, '/');
		}

		$this->collection = $collection;
	}

	public function getCollection()
	{
		return $this->collection;
	}

	protected function assureSerializeParameters($parameters)
	{
		// [] gets serialized as array, so we we add one default parameters
		if (empty($parameters)) {
			return [ 'indent' => 'no' ];
		}

		return $parameters;
	}

	protected function buildDocumentPath($docName)
	{
		if (is_null($this->collection)) {
			return $docName;
		}

		if (!empty($docName) && '/' === $docName[0]) {
			// $docName is absolute
			return $docName;
		}

		// $docName is relative, so prepend collection
		return $this->collection . '/' . $docName;
	}

	protected function buildCollectionPath($collection)
	{
		return !is_null($collection)
			? $collection : $this->collection;
	}

	/**
     * Return the database version.
     *
     * @return string database version
     */
	public function getVersion()
	{
		return $this->client->getVersion();
	}

    /**
     * Retrieve document by name.
     *
     * @param name the document's name.
     * @param parameters Map of parameters.
     * @return The document value
     */
    public function getDocument($name, $parameters = [])
	{
		$parameters = $this->assureSerializeParameters($parameters);

		try {
			$val = $this->client->getDocument($this->buildDocumentPath($name), $parameters);

			return $val->getDecoded();
		}
		catch (\fXmlRpc\Exception\FaultException $e) {
			return false;
		}
	}

    /**
     * Retrieve document by name.
     *
     * @param name the document's name.
     * @param parameters Map of parameters.
     * @return The document value
     */
	public function getDocumentAsString($docName, $parameters = [])
	{
		$parameters = $this->assureSerializeParameters($parameters);

		return $this->client->getDocumentAsString($this->buildDocumentPath($docName), $parameters);
	}

	/**
     * Retrieve binary resource by name
     *
     * @param name
     * @return The resource content
     */
	public function getBinaryResource($name)
	{
		try {
			$val = $this->client->getBinaryResource($this->buildDocumentPath($name));

			return $val->getDecoded();
		}
		catch (\fXmlRpc\Exception\FaultException $e) {
			return false;
		}
	}

	/**
     * Does the document identified by name exist in the
     * repository?
     *
     * @param name of the document
     * @return true or false
     */
	public function hasDocument($name)
	{
		return $this->client->hasDocument($this->buildDocumentPath($name));
	}

	/**
	 * Get a list of all documents contained in $collection or (if null) the database.
	 */
	public function getDocumentListing($collection = null)
	{
		$collectionPath = $this->buildCollectionPath($collection);

		return is_null($collectionPath)
			? $this->client->getDocumentListing()
			: $this->client->getDocumentListing($collectionPath);
	}

	/**
     * Does the collection identified by name exist in the
     * repository?
     *
     * @param name of the collection
     * @return true or false
     */
	public function hasCollection($name = null)
	{
		return $this->client->hasCollection($this->buildCollectionPath($name));
	}

	/**
	 * Get a list of (sub)collections in $collection
	 */
	public function getCollectionListing($collection = null)
	{
		return $this->client->getCollectionListing($this->buildCollectionPath($collection));
	}

	public function existsAndCanOpenCollection($name = null)
	{
		return $this->client->existsAndCanOpenCollection($this->buildCollectionPath($name));
	}

    /**
     * Describe a collection: returns a struct with the following fields:
     *
     * <pre>
     *	name				The name of the collection
     *
     *	owner				The name of the user owning the collection.
     *
     *	group				The group owning the collection.
     *
     *	permissions	The permissions that apply to this collection (int value)
     *
     *	created			The creation date of this collection (long value)
     *
     *	collections		An array containing the names of all subcollections.
     *
     *	documents		An array containing a struct for each document in the collection.
     * </pre>
     */
	public function getCollectionDesc($collection = null)
	{
		return $this->client->getCollectionDesc($this->buildCollectionPath($collection));
	}

    /**
     * Describe a collection: similar to getCollectionDesc but without documents
     */
	public function describeCollection($collection = null)
	{
		return $this->client->describeCollection($this->buildCollectionPath($collection));
	}

	/**
	 * Describe a resource
	 */
	public function describeResource($resource)
	{
		return $this->client->describeResource($this->buildDocumentPath($resource));
	}

	/**
     * Returns the number of resources in the collection identified by
     * collectionName.
     */
	 public function getResourceCount($collectionName = null)
	{
		return $this->client->getResourceCount($this->buildCollectionPath($collectionName));
	}

	/**
	 * TODO: maybe add Date created, Date modified as optional
	 */
    public function parse($xml, $docName, $overwrite = false)
    {
        return $this->client->parse($xml, $this->buildDocumentPath($docName), $overwrite ? 1 : 0);
    }

	/**
	 * An Alias for parse()
	 */
    public function storeDocument($xml, $docName, $overwrite = false)
	{
		return $this->parse($xml, $docName, $overwrite);
	}

	/**
	 * TODO: maybe add Date created, Date modified as optional
	 */
    function storeBinary($data, $docName, $mimeType, $replace = false)
    {
        return $this->client->storeBinary(\fXmlRpc\Value\Base64::serialize($data),
                                          $this->buildDocumentPath($docName),
                                          $mimeType,
                                          $replace ? 1 : 0);
    }

    /**
     * Remove a document from the database.
     *
     * @param docName path to the document to be removed
     * @return
     */
    public function remove($docName)
    {
        return $this->client->remove($this->buildDocumentPath($docName));
    }

    /**
     * Remove an entire collection from the database.
     *
     * @param name path to the collection to be removed.
     * @return
     */
    public function removeCollection($name = null)
    {
        return $this->client->removeCollection($this->buildCollectionPath($name));
    }

    /**
     * Create a new collection on the database.
     *
     * @param name the path to the new collection.
     * @return
      */
    public function createCollection($name = null)
    {
        return $this->client->createCollection($this->buildCollectionPath($name));
    }

	public function getCreationDate($collectionName = null)
	{
        return $this->client->getCreationDate($this->buildCollectionPath($collectionName));
	}

	public function getTimestamps($documentName = null)
	{
        return $this->client->getTimestamps($this->buildDocumentPath($documentName));
	}

	/**
	 * Prepare the execution of an XPath/XQuery
	 */
    public function prepareQuery($xql, $collection = null)
    {
        $query = new Query($xql, $this->client, $this->buildCollectionPath($collection));

        return $query;
    }

	/**
	 * Determine the permissions of a resource
	 */
	public function getPermissions($resource)
	{
		return $this->client->getPermissions($this->buildDocumentPath($resource));
	}

	/**
	 * Get the members of a group
	 */
	public function getGroupMembers($groupName) {
		return $this->client->getGroupMembers($groupName);
	}

	/**
	 * Get all the groups
	 */
	public function getGroups() {
		return $this->client->getGroups();
	}

	public function getGroup($name)
	{
		return $this->client->getGroup($name);
	}

	/**
	 * Get all the accounts
	 */
	public function getAccounts()
	{
		return $this->client->getAccounts();
	}

	public function getAccount($name)
	{
		return $this->client->getAccount($name);
	}

	public function reindexCollection($name)
	{
		return $this->client->reindexCollection($this->buildCollectionPath($name));
	}

	/**
	 * TODO: as per https://github.com/eXist-db/exist/blob/develop/src/org/exist/xmlrpc/RpcAPI.java
	 *
	 *  shutdown()
	 *  shutdown(long delay)
	 *
	 *  sync()
	 *
	 *  enterServiceMode()
	 *  exitServiceMode()
	 *
	 *  boolean addGroup(String name, Map<String, String> metadata)
	 *  boolean updateGroup(final String name, final List<String> managers, final Map<String, String> metadata)
	 *  void removeGroup(String name)
	 *
	 *  boolean setUserPrimaryGroup(final String username, final String groupName)
	 *  void addAccountToGroup(final String accountName, final String groupName)
	 *  void addGroupManager(final String manager, final String groupName)
	 *  void removeGroupManager(final String groupName, final String manager)
	 *  void removeGroupMember(final String group, final String member)
	 *
	 *  addAccount(String name, String passwd, String digestPassword, List<String> groups,
	 *              Boolean isEnabled, Integer umask, Map<String, String> metadata)
	 *  updateAccount(String name, String passwd, String digestPassword, List<String> groups)
	 *  boolean removeAccount(String name)
	 *
	 *  boolean setPermissions(String resource, String permissions)
	 *  boolean chgrp(final String resource, final String ownerGroup)
	 *  boolean chown(final String resource, final String owner)
	 *
	 *  boolean lockResource(String path, String userName)
	 *  boolean unlockResource(String path)
	 *  String hasUserLock(String path)
	 *
	 *  Map<String, Object> getDocumentData(String name, Map<String, Object> parameters)
	 *  Map<String, Object> getNextChunk(String handle, int offset)
	 *  Map<String, Object> getNextExtendedChunk(String handle, String offset)
	 *
	 *  Map<String, Object> compile(byte[] xquery, Map<String, Object> parameters)
	 *
	 *  Map<String, Object> querySummary(String xquery)
	 *  Map<String, Object> querySummary(int resultId)
	 *
	 *
	 *  int xupdate(String collectionName, byte[] xupdate)
	 *  int xupdateResource(String resource, byte[] xupdate)
	 *
	 *  boolean setLastModified(final String documentPath, final long lastModified)
	 *  List<String> getDocType(String documentName)
     *  boolean setDocType(String documentName, String doctypename, String publicid, String systemid)
	 *
	 *  boolean copyCollection(String name, String namedest)
	 *  boolean moveCollection(String collectionPath, String destinationPath, String newName)
	 *
 	 *  boolean copyResource(String docPath, String destinationPath, String newName)
	 *  boolean moveResource(String docPath, String destinationPath, String newName)
	 *
	 *  List<List> getIndexedElements(String collectionName, boolean inclusive)
	 *  boolean reindexCollection(String name)
	 *  boolean reindexDocument(String docUri)
	 *
	 *  String printDiagnostics(String query, Map<String, Object> parameters)
	 *
	 *  String createResourceId(String collection)
	 *
	 *  boolean configureCollection(String collection, String configuration)
	 *
	 *  boolean backup(String userbackup, String password, String destcollection, String collection)
	 *  boolean dataBackup(String dest)
	 *
	 *  void runCommand(XmldbURI collectionURI, List<String> params)
	 *
	 *  long getSubCollectionCreationTime(String parentPath, String name)
	 *  Map<String, Object> getSubCollectionPermissions(String parentPath, String name)
	 *  Map<String, Object> getSubResourcePermissions(String parentPath, String name)
	 *
	 *  boolean setTriggersEnabled(String path, String value)
	 *
	 *  For handling huge files, maybe add the following
	 *      String upload(String file, byte[] chunk, int length)
	 *      boolean parseLocal(String localFile, String docName, boolean replace, String mimeType)
	 *
	 *  The following don't seem to work properly in 4.0.0?!
	 *  	Map<String, List> listDocumentPermissions(String name)
	 *  	Map<XmldbURI, List> listCollectionPermissions(String name)
	 *
	 *  	List<String> getDocType(String documentName)
	 *
	 */
}

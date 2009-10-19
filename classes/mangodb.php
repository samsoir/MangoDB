<?php

class MangoDB {

	// Database instances
	public static $instances = array();

	public static function instance($name = 'default', array $config = NULL)
	{
		if( ! isset(self::$instances[$name]) )
		{
			if ($config === NULL)
			{
				// Load the configuration for this database
				$config = Kohana::config('mangoDB')->$name;
			}

			new MangoDB($name,$config);
		}

		return self::$instances[$name];
	}

	// Instance name
	protected $_name;

	// Connected
	protected $_connected = FALSE;

	// Raw server connection
	protected $_connection;

	// Raw database connection;
	protected $_db;

	// Collection objects
	protected $_collections = array();

	// Store config locally
	protected $_config;

	protected function __construct($name, array $config)
	{
		$this->_name = $name;

		$this->_config = $config;

		// Store the database instance
		MangoDB::$instances[$name] = $this;
	}

	final public function __destruct()
	{
		$this->disconnect();
	}

	final public function __toString()
	{
		return $this->_name;
	}

	public function connect()
	{
		if ($this->_connection)
		{
			return;
		}

		// Extract the connection parameters, adding required variabels
		extract($this->_config + array(
			'hostnames'  => NULL,
			'persistent' => FALSE,
			'paired'     => FALSE
		));

		// Clear the connection parameters for security
		unset($this->_config['connection']);

		// Create connection object
		$this->_connection = new Mongo($hostnames, FALSE, $persistent, $paired);

		// Set the connection type
		$connect = $persistent 
			? ($paired 
					? 'pairPersistConnect' 
					: 'persistConnect'
				)
			: ($paired 
					? 'pairConnect' 
					: 'connect'
				);

		try
		{
			// Try to connect to the database server
			$this->_connection->$connect();
		}
		catch ( MongoConnectionException $e)
		{
			// Unable to connect to the database server
			throw new Kohana_Exception('Unable to connect to MongoDB server at :hostnames',
				array(':hostnames' => $e->getMessage()));
		}

		$this->_db = $this->_connection->selectDB($this->_config['database']);

		return $this->_connected = TRUE;
	}

	public function disconnect()
	{
		if ( $this->_connection)
		{
			$this->_connection->close();
		}

		$this->_db = $this->_connection = NULL;

		$this->_collections = array();
	}

	public function get_collection($name)
	{
		$this->_connected OR $this->connect();

		if ( ! isset($this->_collections[$name]))
		{
			$this->_collections[$name] = $this->_db->selectCollection($name);
		}

		return $this->_collections[$name];
	}

	/* A simple cache
	 *
	 * Best performance if you create the collection 'cache' yourself
	 * and make it a capped collection.
	 * See: http://www.mongodb.org/display/DOCS/Capped+Collections
	 *
	 * Please note there are some limits with capped collections:
	 * "You may update the existing objects in the collection. However, 
	 *  the objects must not grow in size. If they do, the update will 
	 *  fail. (There are some possible workarounds; contact us in the 
	 *  support forums for more information, if help is needed.)"
	 *
	 * The key is to create enough padding in the object, so that 
	 * whenever it is updated, there is enough space left for the update
	 */

	/* Store object: $value in cache under key: $key
	 * Use the $size parameter to set the total length (serialized string length)
	 * If the actual object has a smaller length, it will be padded with spaces
	 * allowing future updates
	 */
	public function cache_set($key,$value,$size = 100)
	{
		$this->get_collection('cache')->save(array(
			'_id' => $key,
			'v'   => str_pad(serialize($value),$size)
		));
	}

	public function cache_get($key)
	{
		$item = $this->get_collection('cache')->findOne( array('_id'=>$key) );

		return $item
			? unserialize($item['v'])
			: NULL;
	}

	/* Mongo */

	public function last_error()
	{
		return $this->_connected
			? $this->_connection->lastError() 
			: NULL;
	}

	public function prev_error()
	{
		return $this->_connected
			? $this->_connection->prevError()
			: NULL;
	}
	
	public function reset_error()
	{
		return $this->_connected
			? $this->_connection->reset_error()
			: NULL;
	}

	/* MongoDB */
	public function create_collection ( string $collection_name, $capped= FALSE, $size= 0, $max= 0 )
	{
		return $this->_db->createCollection($collection_name,$capped,$size,$max);
	}

	public function drop_collection( $collection_name )
	{
		return $this->_db->dropCollection($collection_name);
	}

	public function execute( $code, array $args = array() )
	{
		return $this->_db->execute($code,$args);
	}

	/* MongoCollection */

	public function ensure_index ( $collection_name, $keys )
	{
		return $this->_get_collection($collection_name)->ensureIndex($keys);
	}

	public function batch_insert ( $collection_name, array $a )
	{
		return $this->get_collection($collection_name)->batchInsert($a);
	}

	public function count( $collection_name, array $query = array(), array $fields = array() )
	{
		return $this->get_collection($collection_name)->count($query,$fields);
	}

	public function find_one($collection_name, array $query = array(), array $fields = array())
	{
		return $this->get_collection($collection_name)->findOne($query,$fields);
	}

	public function find($collection_name, array $criteria = array(), array $fields = array())
	{
		return $this->get_collection($collection_name)->find($criteria,$fields);
	}

	public function group ( array $keys , array $initial , string $reduce, array $condition= array() )
	{
		return $this->get_collection($collection_name)->group($keys,$initial,$reduce,$condition);
	}

	public function update($collection_name, array $criteria, array $newObj, $upsert = FALSE)
	{
		return $this->get_collection($collection_name)->update($criteria,$newObj,$upsert);
	}

	public function insert($collection_name, array $a)
	{
		return $this->get_collection($collection_name)->insert($a);
	}

	public function remove($collection_name, array $criteria, $justOne = FALSE)
	{
		return $this->get_collection($collection_name)->remove($criteria,$justOne);
	}

	public function save($collection_name, array $a)
	{
		return $this->get_collection($collection_name)->save($a);
	}

	public function gridFS( $arg1 = NULL, $arg2 = NULL)
	{
		$this->_connected OR $this->connect();

		if (!isset($arg1))
		{
			$arg1 = isset($this->_config['gridFS']['arg1'])
				? $this->_config['gridFS']['arg1']
				: 'fs';
		}

		if (!isset($arg2) && isset($this->_config['gridFS']['arg2']))
		{
			$arg2 = $this->_config['gridFS']['arg2'];
		}

		return $this->_db->getGridFS($arg1,$arg2);
	}

	public function get_file( $criteria )
	{
		if(!is_array($criteria))
		{
			$criteria = array('filename' => $criteria);
		}

		return $this->gridFS()->findOne($criteria);
	}

	public function set_file_bytes($criteria, $bytes)
	{
		if(!is_array($criteria))
		{
			$criteria = array('filename'=>$criteria);
		}

		$this->gridFS()->storeBytes($bytes,$criteria);
	}
}
?>
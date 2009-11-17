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

	/* Database Management */

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

	public function ensure_index ( $collection_name, $keys )
	{
		return $this->_db->selectCollection($collection_name)->ensureIndex($keys);
	}

	public function execute( $code, array $args = array() )
	{
		return $this->_call('execute', array(
			'code' => $code,
			'args' => $args
		));
	}

	/* Collection management */

	public function create_collection ( string $name, $capped= FALSE, $size= 0, $max= 0 )
	{
		return $this->_call('create_collection', array(
			'name'    => $name,
			'capped'  => $capped,
			'size'    => $size,
			'max'     => $max
		));
	}

	public function drop_collection( $name )
	{
		return $this->_call('drop_collection', array(
			'name' => $name
		));
	}

	/* Data Management */

	public function batch_insert ( $collection_name, array $a )
	{
		return $this->_call('batch_insert', array(
			'collection_name' => $collection_name
		), $a);
	}

	public function count( $collection_name, array $query = array(), array $fields = array() )
	{
		return $this->_call('count', array(
			'collection_name' => $collection_name,
			'query'           => $query,
			'fields'          => $fields
		));
	}

	public function find_one($collection_name, array $query = array(), array $fields = array())
	{
		return $this->_call('find_one', array(
			'collection_name' => $collection_name,
			'query'           => $query,
			'fields'          => $fields
		));
	}

	public function find($collection_name, array $query = array(), array $fields = array())
	{
		return $this->_call('find', array(
			'collection_name' => $collection_name,
			'query'           => $query,
			'fields'          => $fields
		));
	}

	public function group( $collection_name, array $keys , array $initial , string $reduce, array $condition= array() )
	{
		return $this->_call('group', array(
			'collection_name' => $collection_name,
			'keys'            => $keys,
			'initial'         => $initial,
			'reduce'          => $reduce,
			'condition'       => $condition
		));
	}

	public function update($collection_name, array $criteria, array $newObj, $upsert = FALSE)
	{
		return $this->_call('update', array(
			'collection_name' => $collection_name,
			'criteria'        => $criteria,
			'upsert'          => $upsert
		), $newObj);
	}

	public function insert($collection_name, array $a)
	{
		return $this->_call('insert', array(
			'collection_name' => $collection_name
		), $a);
	}

	public function remove($collection_name, array $criteria, $justOne = FALSE)
	{
		return $this->_call('remove', array(
			'collection_name' => $collection_name,
			'criteria'        => $criteria,
			'justOne'         => $justOne
		));
	}

	public function save($collection_name, array $a)
	{
		return $this->_call('save', array(
			'collection_name' => $collection_name
		), $a);
	}

	/* File management */

	public function gridFS( $arg1 = NULL, $arg2 = NULL)
	{
		$this->_connected OR $this->connect();

		if ( ! isset($arg1))
		{
			$arg1 = isset($this->_config['gridFS']['arg1'])
				? $this->_config['gridFS']['arg1']
				: 'fs';
		}

		if ( ! isset($arg2) && isset($this->_config['gridFS']['arg2']))
		{
			$arg2 = $this->_config['gridFS']['arg2'];
		}

		return $this->_db->getGridFS($arg1,$arg2);
	}

	public function get_file(array $criteria = array())
	{
		return $this->_call('get_file', array(
			'criteria' => $criteria
		));
	}

	public function set_file_bytes($bytes, array $extra = array())
	{
		return $this->_call('set_file_bytes', array(
			'bytes' => $bytes,
			'extra' => $extra
		));
	}

	public function set_file($filename, array $extra = array())
	{
		return $this->_call('set_file', array(
			'filename' => $filename,
			'extra'    => $extra
		));
	}

	public function remove_file( array $criteria = array(), $justOne = FALSE)
	{
		return $this->_call('remove_file', array(
			'criteria' => $criteria,
			'justOne'  => $justOne
		));
	}

	/* 
	 * All commands for which benchmarking could be useful
	 * are executed by this method
	 *
	 * This allows for easy benchmarking
	 */
	protected function _call($command, array $arguments = array(), array $values = NULL)
	{
		$this->_connected OR $this->connect();

		extract($arguments);

		if ( ! empty($this->_config['profiling']))
		{
			$_bm_name = isset($collection_name)
			 ? $collection_name . '.' . $command
			 : $command;

			$_bm = Profiler::start("MangoDB {$this->_name}",$_bm_name);
		}

		if ( isset($collection_name))
		{
			$c = $this->_db->selectCollection($collection_name);
		}

		switch ( $command)
		{
			case 'create_collection':
				$r = $this->_db->createCollection($name,$capped,$size,$max);
			break;
			case 'drop_collection':
				$r = $this->_db->dropCollection($name);
			break;
			case 'execute':
				$r = $this->_db->execute($code,$args);
			break;
			case 'batch_insert':
				$r = $c->batchInsert($values);
			break;
			case 'count':
				$r = $c->count($query,$fields);
			break;
			case 'find_one':
				$r = $c->findOne($query,$fields);
			break;
			case 'find':
				$r = $c->find($query,$fields);
			break;
			case 'group':
				$r = $c->group($keys,$initial,$reduce,$condition);
			break;
			case 'update':
				$r = $c->update($criteria, $values, $upsert);
			break;
			case 'insert':
				$r = $c->insert($values);
			break;
			case 'remove':
				$r = $c->remove($criteria,$justOne);
			break;
			case 'save':
				$r = $c->save($values);
			break;
			case 'get_file':
				$r = $this->gridFS()->findOne($criteria);
			break;
			case 'set_file_bytes':
				$r = $this->gridFS()->storeBytes($bytes,$extra);
			break;
			case 'set_file':
				$r = $this->gridFS()->storeFile($filename,$extra);
			break;
			case 'remove_file':
				$r = $this->gridFS()->remove($criteria, $justOne);
			break;
		}

		if ( isset($_bm))
		{
			Profiler::stop($_bm);
		}

		return $r;
	}
}
?>
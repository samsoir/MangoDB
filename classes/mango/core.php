<?php

abstract class Mango_Core implements Mango_Interface {

	/**
	 * @var  array  Extension config
	 */
	protected static $_cti;

	const CHANGE   = 0; // Pushes values into model - trigger changes (default)
	const EXTEND   = 1; // Returns empty model - only uses values to detect possible extension
	const CLEAN    = 2; // Pushes values into model - doesn't trigger changes

	const CHECK_FULL  = 0; // Full validation - local and embedded documents
	const CHECK_LOCAL = 1; // Full validation of local data only (not the embedded documents)
	const CHECK_ONLY  = 2; // Selective validation of supplied local fields only

	/**
	 * Load an Mango model.
	 *
	 * @param   string  model name
	 * @param   array   values to pre-populate the model
	 * @param   boolean use values to extend only, don't prepopulate model
	 * @return  Mango
	 */
	public static function factory($name, array $values = NULL, $load_type = 0)
	{
		static $models;

		if ( $values)
		{
			if ( self::$_cti === NULL)
			{
				// load extension config
				self::$_cti = Kohana::config('mangoCTI');
			}

			while ( isset(self::$_cti[$name]))
			{
				$key = key(self::$_cti[$name]);

				if ( isset($values[$key]) && isset(self::$_cti[$name][$key][$values[$key]]))
				{
					// extend
					$name = self::$_cti[$name][$key][$values[$key]];
				}
				else
				{
					break;
				}
			}
		}

		if ( ! isset($models[$name]))
		{
			$class = 'Model_'.$name;

			$models[$name] = new $class;
		}

		// Create a new instance of the model by clone
		$model = clone $models[$name];

		if ( $values)
		{
			switch ( $load_type)
			{
				case Mango::CHANGE:
					$model->values($values);
				break;
				case Mango::CLEAN:
					$model->values($values,TRUE);
				break;
			}
		}

		return $model;
	}

	/**
	 * @var  string  model name
	 */
	protected $_model;

	/**
	 * @var  string  database instance name
	 */
	protected $_db = 'default';

	/**
	 * @var  string  database collection name
	 */
	protected $_collection;

	/**
	 * @var  boolean  indicates if model is embedded
	 */
	protected $_embedded = FALSE;

	/**
	 * @var  array  field list (name => field data)
	 */
	protected $_fields = array();

	/**
	 * @var  array  relation list (name => relation data)
	 */
	protected $_relations = array();

	/**
	 * @var  array  object data
	 */
	protected $_object = array();

	/**
	 * @var  array  related data
	 */
	protected $_related = array();

	/**
	 * @var  array  changed fields
	 */
	protected $_changed = array();

	/**
	 * @var  boolean  initialization status
	 */
	protected $_init = FALSE;

	/**
	 * Calls the init() method. Mango constructors are only called once!
	 *
	 * @param   string   model name
	 * @return  void
	 */
	final protected function __construct()
	{
		$this->init();
	}

	/**
	 * Returns the model name.
	 *
	 * @return  string
	 */
	public function __toString()
	{
		return $this->_model;
	}

	/**
	 * Clones each of the fields and empty the model.
	 *
	 * @return  void
	 */
	public function __clone()
	{
		$this->clear();
	}

	/**
	 * Returns the attributes that should be serialized.
	 *
	 * @return  void
	 */
	public function __sleep()
	{
		return array('_object', '_changed');
	}

	/**
	 * Restores model data
	 *
	 * @return  void
	 */
	public function __wakeup()
	{
		$this->init();
	}

	/**
	 * Checks if a field is set
	 *
	 * @return  boolean  field is set
	 */
	public function __isset($name)
	{
		return isset($this->_fields[$name]) 
			? isset($this->_object[$name]) 
			: isset($this->_related[$name]);
	}

	/**
	 * Empties the model
	 */
	public function clear()
	{
		$this->_object = $this->_changed = array();

		return $this;
	}

	/**
	 * Return field data for a certain field
	 *
	 * @param   string         field name
	 * @return  boolean|array  field data if field exists, otherwise FALSE
	 */
	public function field($name)
	{
		return isset($this->_fields[$name])
			? $this->_fields[$name]
			: FALSE;
	}

	/**
	 * Return TRUE if field has been changed
	 *
	 * @param   string   field name
	 * @return  boolean  field has been changed
	 */
	public function is_changed($field)
	{
		return isset($this->_changed[$field]);
	}

	/**
	 * Return db reference
	 *
	 * @return  MangoDB  database object
	 */
	public function db()
	{
		if ( ! is_object($this->_db) )
		{
			// Initialize DB
			$this->_db = MangoDB::instance($this->_db);
		}

		return $this->_db;
	}

	/**
	 * Retrieve creation timestamp from MongoID object
	 *
	 * @return   int   Timestamp
	 */
	public function get_time()
	{
		if ( ! $this->loaded())
		{
			throw new Mango_Exception('Creation timestamp is only available on created documents');
		}

		if ( $this->_fields['_id']['type'] !== 'MongoId')
		{
			throw new Mango_Exception('Creation timestamp can only be deduced from MongoId document IDs, you\'re using: :id', array(
				':id' => $this->_fields['_id']['type']
			));
		}

		return $this->__get('_id')->getTimestamp();
	}

	/**
	 * Get the value of a field.
	 *
	 * @throws  Mango_Exception  field does not exist
	 * @param   string  field name
	 * @return  mixed
	 */
	public function __get($name)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			$field = $this->_fields[$name];

			$value = isset($this->_object[$name])
				? $this->_object[$name]
				: NULL;

			switch ( $field['type'])
			{
				case 'enum':
					$value = isset($value) && isset($field['values'][$value]) 
						? $field['values'][$value] 
						: NULL;
				break;
				case 'set':
				case 'array':
				case 'has_one':
				case 'has_many':
					if ( $value === NULL)
					{
						// 'secretly' create value - access _object directly, not recorded as change
						$value = $this->_object[$name] = $this->load_field($name,array());
					}
				break;
				case 'counter':
					if ( $value === NULL)
					{
						// 'secretly' create counter - access _object directly, not recorded as change
						$value = $this->_object[$name] = $this->load_field($name,0);
					}
				break;
			}

			if ( $value === NULL && isset($field['default']))
			{
				$value = $field['default'];
			}

			return $value;
		}
		elseif ( isset($this->_relations[$name]))
		{
			if ( ! isset($this->_related[$name]))
			{
				$relation = $this->_relations[$name];

				switch ( $relation['type'])
				{
					case 'has_one':
						$criteria = array($this->_model . '_id' => $this->_id);
						$limit = 1;
					break;
					case 'belongs_to':
						$criteria = array('_id' => $this->_object[$name . '_id']);
						$limit = 1;
					break;
					case 'has_many':
						$criteria = array($this->_model . '_id' => $this->_id);
						$limit = FALSE;
					break;
					case 'has_and_belongs_to_many':
						$criteria = array('_id' => array('$in' => $this->__get($name . '_ids')->as_array()));
						$limit = FALSE;
					break;
				}

				$this->_related[$name] = Mango::factory($relation['model'])->load($limit, NULL, NULL, array(), $criteria);
			}

			return $this->_related[$name];
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Set the value of a field.
	 *
	 * @throws  Mango_Exception  field does not exist
	 * @param   string  field name
	 * @param   mixed   new field value
	 * @return  mixed
	 */
	public function __set($name, $value)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			$value = $this->load_field($name,$value);

			if ( isset($this->_object[$name]))
			{
				// Unset field if setting existing field to NULL
				if ( $value === NULL)
				{
					return $this->__unset($name);
				}

				// don't update value if the value did not change
				$current = $this->_object[$name];

				if ( Mango::normalize($current) === Mango::normalize($value))
				{
					return FALSE;
				}
			}
			elseif ( $value === NULL || $value === '')
			{
				return;
			}

			//echo 'Setting:  ' . $name . ' to ' . (is_object($value) ? get_class($value) : $value) . ' in ' . $this->_model . '<bR>';

			// update object
			$this->_object[$name] = $value;

			// mark change
			$this->_changed[$name] = TRUE;
		}
		elseif ( isset($this->_relations[$name]) && $this->_relations[$name]['type'] === 'belongs_to')
		{
			$this->__set($name . '_id',$value->_id);
			$this->_related[$name] = $value;
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Unset a field
	 *
	 * @param   string  field name
	 * @return  void
	 */
	public function __unset($name)
	{
		if ( ! $this->_init)
		{
			$this->init();
		}

		if ( isset($this->_fields[$name]))
		{
			if ( $this->__isset($name))
			{
				// unset field
				unset($this->_object[$name]);

				// mark unset
				$this->_changed[$name] = FALSE;
			}
		}
		else
		{
			throw new Mango_Exception(':name model does not have a field :field',
				array(':name' => $this->_model, ':field' => $name));
		}
	}

	/**
	 * Initialize the fields and add validation rules based on field properties.
	 *
	 * @return  void
	 */
	protected function init()
	{
		if ( $this->_init)
		{
			// Can only be called once
			return;
		}

		// Set up fields
		$this->set_model_definition();

		if ( ! $this->_model)
		{
			// Set the model name based on the class name
			$this->_model = strtolower(substr(get_class($this), 6));
		}

		foreach ( $this->_fields as $name => & $field)
		{
			if ( $field['type'] === 'has_one' && ! isset($field['model']))
			{
				$field['model'] = $name;
			}
			elseif ( $field['type'] === 'has_many' && ! isset($field['model']))
			{
				$field['model'] = Inflector::singular($name);
			}
		}

		if (!$this->_embedded )
		{
			if ( ! $this->_collection)
			{
				// Set the collection name to the plural model name
				$this->_collection = Inflector::plural($this->_model);
			}

			if ( ! isset($this->_fields['_id']))
			{
				// default _id field
				$this->_fields['_id'] = array('type'=>'MongoId');
			}

			// Normalize relations
			foreach ( $this->_relations as $name => &$relation)
			{
				if ( $relation['type'] === 'has_and_belongs_to_many')
				{
					$relation['model'] = isset($relation['model']) 
						 ? $relation['model']
						 : Inflector::singular($name);

					$relation['related_relation'] = isset($relation['related_relation'])
						? $relation['related_relation']
						: Inflector::plural($this->_model);

					//$relation['model'] = Inflector::singular($name);
				}
				elseif ( ! isset($relation['model']))
				{
					if ( $relation['type'] === 'belongs_to' || $relation['type'] === 'has_one')
					{
						$relation['model'] = $name;
					}
					else
					{
						$relation['model'] = Inflector::singular($name);
					}
				}

				switch ( $relation['type'])
				{
					case 'belongs_to':
						$this->_fields[$name . '_id'] = array('type'=>'MongoId');
					break;
					case 'has_and_belongs_to_many':
						$this->_fields[$name . '_ids'] = array('type'=>'set', 'unique' => TRUE);
					break;
				}
			}
		}

		$this->_init = TRUE;
	}

	/**
	 * Overload in child classes to setup model definition
	 * Call $this->_set_model_definition( array( ... ));
	 *
	 * @return  void
	 */
	protected function set_model_definition() {}

	/**
	 * Add definition to this model.
	 * Called in child classes in the set_model_definition method
	 *
	 * @return  void
	 */
	protected function _set_model_definition(array $definition)
	{
		if ( isset($definition['_fields']))
		{
			$this->_fields = array_merge($this->_fields,$definition['_fields']);
		}

		if ( isset($definition['_relations']))
		{
			$this->_relations = array_merge($this->_relations,$definition['_relations']);
		}
	}

	/**
	 * Load all of the values in an associative array. Ignores all fields
	 * not in the model.
	 *
	 * @param   array    field => value pairs
	 * @param   boolean  values are clean (from database)?
	 * @return  $this
	 */
	public function values(array $values, $clean = FALSE)
	{
		// Remove all values which do not have a corresponding field
		$values = array_intersect_key($values, $this->_fields);

		foreach ($values as $field => $value)
		{
			//echo 'values as: ' .  ($clean ? 'TRUE' : 'FALSE') . ' in: ' . $field . ' to ' . (is_object($value) ? get_class($value) : $value) . ' in ' . $this->_model .'<br>';

			if ( $clean === TRUE)
			{
				// Set the field directly
				$this->_object[$field] = $this->load_field($field,$value);
			}
			else
			{
				// Set the field using __set()
				$this->$field = $value;
			}
		}

		return $this;
	}

	/**
	 * Get the model data as an associative array.
	 * @param  boolean  retrieve values directly from _object
	 *
	 * @return  array  field => value
	 */
	public function as_array( $clean = TRUE )
	{
		$array = array();

		foreach ( $this->_object as $name => $value)
		{
			if ( $value instanceof Mango_Interface)
			{
				$array[$name] = $value->as_array( $clean );
			}
			elseif ( $clean)
			{
				// direct from _object, for saving (enums numbers)
				$array[$name] = $value;
			}
			else
			{
				// user friendlier value, for displaying (string _IDs, enum values)
				$array[$name] = $value instanceof MongoId
					? (string) $value
					: $this->__get($name);
			}
		}

		return count($array) || ! $clean
			? $array
			: (object) array();
	}

	/**
	 * Test if the model is loaded.
	 *
	 * @return  boolean
	 */
	public function loaded()
	{
		return $this->_embedded || (isset($this->_id) && !isset($this->_changed['_id']));
	}

	/**
	 * Get all of the changed fields as an associative array.
	 *
	 * @param  boolean  indicate update (TRUE) or insert (FALSE) - (determines use of modifiers like $set/$inc)
	 * @param  array    prefix data, used internally
	 * @return  array  field => value
	 */
	public function changed($update, array $prefix= array())
	{
		$changed = array();

		foreach ( $this->_fields as $name => $field)
		{
			if (isset($field['local']) && $field['local'] === TRUE)
			{
				// local variables are not stored in DB
				continue;
			}

			$value = $this->__isset($name) 
				? $this->_object[$name] 
				: NULL;

			// prepare prefix
			$path = array_merge($prefix,array($name));

			if ( isset($this->_changed[$name]))
			{
				// value has been changed
				if ( $value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}

				if ( $this->_changed[$name] === TRUE)
				{
					// __set
					$changed = $update
						? arr::merge($changed,array('$set'=>array( implode('.',$path) => $value) ) )
						: arr::merge($changed, arr::path_set($path,$value) );
				}
				else
				{
					// __unset
					if ( $update)
					{
						$changed = arr::merge($changed, array('$unset'=> array( implode('.', $path) => TRUE)));
					}
				}
			}
			elseif ( $this->__isset($name))
			{
				// check any (embedded) objects/arrays/sets
				if ( $value instanceof Mango_Interface)
				{
					$changed = arr::merge($changed, $value->changed($update,$path));
				}
			}
		}

		return $changed;
	}

	/**
	 * Indicate model has been saved, resets $this->_changed array
	 *
	 * @return  void
	 */
	public function saved()
	{
		foreach ( $this->_object as $value)
		{
			if ( $value instanceof Mango_Interface)
			{
				$value->saved();
			}
		}

		$this->_changed = array();
	}

	/**
	 * Reload model from database
	 *
	 * @return  $this
	 */
	public function reload()
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be reloaded from database',
				array(':name' => $this->_model));
		}
		elseif ( ! isset($this->_id))
		{
			throw new Mango_Exception(':name model cannot be reloaded, _id value missing',
				array(':name' => $this->_model));
		}

		$this->_changed = array(
			'_id' => TRUE
		);

		return $this->load();
	}

	/**
	 * Load a (set of) document(s) from the database
	 *
	 * @param   mixed  limit the (maximum) number of models returned
	 * @param   array  sorts models on specified fields array( field => 1/-1 )
	 * @param   int    skip a number of results
	 * @param   array  specify the fields to return
	 * @param   array  specify additional criteria
	 * @return  mixed  if limit = 1, returns $this, otherwise returns iterator
	 */
	public function load($limit = 1, array $sort = NULL, $skip = NULL, array $fields = array(), array $criteria = array())
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be loaded from database',
				array(':name' => $this->_model));
		}

		$criteria += $this->changed(FALSE);

		// resets $this->_changed array
		$this->clear();

		if ( $limit === 1 && $sort === NULL && $skip === NULL)
		{
			$values = $this->db()->find_one($this->_collection,$criteria,$fields);

			return $values === NULL
				? $this
				: $this->values($values, TRUE);
		}
		else
		{
			$values = $this->db()->find($this->_collection,$criteria,$fields);

			if ( is_int($limit))
			{
				$values->limit($limit);
			}

			if ( $sort !== NULL)
			{
				$values->sort($sort);
			}

			if ( is_int($skip))
			{
				$values->skip($skip);
			}

			return $limit === 1
				? ($values->hasNext()
						? $this->values( $values->getNext(), TRUE)
						: $this)
				: new Mango_Iterator($this->_model,$values);
		}
	}

	/**
	 * Create a new document using the current data.
	 *
	 * @return  $this
	 */
	public function create()
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be created in the database',
				array(':name' => $this->_model));
		}

		if ( $values = $this->changed(FALSE))
		{
			// insert - MongoDB driver will generate unique _id value (if missing)
			$this->db()->insert($this->_collection, $values);

			// check for success
			$err = $this->db()->last_error();

			if ( $err['err'])
			{
				// Something went wrong - throw error
				throw new Mango_Exception('Unable to create :model, database returned error :error',
					array(':model' => $this->_model, ':error' => $err['err']));
			}

			if ( ! isset($this->_object['id']))
			{
				// Store (generated) MongoID in object
				$this->_object['_id'] = $this->load_field('_id',$values['_id']);
			}

			$this->saved();
		}

		return $this;
	}

	/**
	 * Update the current document using the current data.
	 *
	 * @param   array  Additional criteria for update
	 * @return  $this
	 */
	public function update( $criteria = array() )
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be updated itself (update parent instead)',
				array(':name' => $this->_model));
		}

		if ( $values = $this->changed(TRUE))
		{
			$criteria['_id'] = $this->_id;

			$this->db()->update($this->_collection, $criteria, $values, FALSE);

			$this->saved();
		}
		
		return $this;
	}

	/**
	 * Delete the current document using the current data.
	 *
	 * @return  $this
	 */
	public function delete()
	{
		if ( $this->_embedded)
		{
			throw new Mango_Exception(':name model is embedded and cannot be deleted (delete parent instead)',
				array(':name' => $this->_model));
		}
		elseif ( ! $this->loaded())
		{
			$this->load(1);

			if ( ! $this->loaded())
			{
				// model does not exist
				return $this;
			}
		}

		// Update/remove relations
		foreach ( $this->_relations as $name => $relation)
		{
			switch ( $relation['type'])
			{
				case 'has_one':
					$this->__get($name)->delete();
				break;
				case 'has_many':
					foreach ( $this->__get($name) as $hm)
					{
						$hm->delete();
					}
				break;
				case 'has_and_belongs_to_many':
					$set = $this->__get($name . '_ids')->as_array();

					if ( ! empty($set))
					{
						$this->db()->update( $name, array('_id' => array('$in' => $set)), array('$pull' => array($relation['related_relation'] . '_ids' => $this->_id)), array('multiple' => TRUE));
					}
				break;
			}
		}

		$this->db()->remove($this->_collection, array('_id'=>$this->_id), FALSE);

		return $this;
	}

	/**
	 * Check the given data is valid. Recursively checks embedded objects as well
	 *
	 * @throws  Validate_Exception  when an error is found
	 * @param   array    data to check, defaults to current document data (including embedded documents)
	 * @param   subject  specify what part of $data should be subjected to validation, Mango::CHECK_FULL, Mango::CHECK_LOCAL, Mango::CHECK_ONLY
	 * @return  array    validated $data
	 */
	public function check(array $data = NULL, $subject = 0)
	{
		if ( $data === NULL )
		{
			$data = $this->as_array( FALSE );
		}

		// Split data into local and embedded
		$local    = array();
		$embedded = array();

		foreach ( $data as $field => $value)
		{
			if ( $this->_fields[$field]['type'] === 'has_one' || $this->_fields[$field]['type'] === 'has_many')
			{
				$embedded[$field] = $value;
			}
			else
			{
				$local[$field] = $value;
			}
		}

		// Create array with validated data
		$values = array();

		// Validate local data (if required / available)
		if ( $subject !== Mango::CHECK_ONLY || count($local))
		{
			$array = Validate::factory($local);

			// Add validation rules
			$array = $this->_check($array);

			if ( $subject === Mango::CHECK_ONLY)
			{
				foreach ( $this->_fields as $field_name => $field_data)
				{
					if ( ! isset($data[$field_name]))
					{
						// do not validate this field
						unset($array[$field_name]);
					}
				}
			}

			// Validate
			if ( ! $array->check())
			{
				// Validation failed
				throw new Mango_Validate_Exception($this->_model,$array);
			}

			foreach ( $array as $field => $value)
			{
				// Don't include NULL values from fields that aren't set anyway
				if ( $value !== NULL || $this->__isset($field))
				{
					$values[$field] = $value;
				}
			}
		}

		// Validate embedded documents
		if ( $subject !== Mango::CHECK_LOCAL)
		{
			foreach ( $embedded as $field => $value)
			{
				if ( $this->_fields[$field]['type'] === 'has_one')
				{
					$values[$field] = Mango::factory($this->_fields[$field]['model'], $value, Mango::EXTEND)
						->check($value, $subject);
				}
				elseif ( $this->_fields[$field]['type'] === 'has_many')
				{
					$val = array();

					foreach ( $value as $k => $v)
					{
						$model = Mango::factory($this->_fields[$field]['model'],$v,Mango::EXTEND);
	
						try
						{
							$val[$k] = $model->check($v, $subject);
						}
						catch ( Mango_Validate_Exception $e)
						{
							// add sequence number of failed object to exception
							$e->seq = $k;
							throw $e;
						}
					}

					$values[$field] = $val;
				}
			}
		}

		// Return
		return $values;
	}

	/**
	 * Add validation rules to validiate object based on
	 * field specification.
	 *
	 * You can overload this method and add your own
	 * (more complicated) rules
	 *
	 * @param   Validate  Validate object
	 * @return  Validate  Validate object
	 */
	protected function _check(Validate $data)
	{
		foreach ($this->_fields as $name => $field)
		{
			// field type rules
			switch ( $field['type'])
			{
				case 'enum':
					$data->rule($name,'in_array',array($field['values']));
				break;
				case 'int':
				case 'float':
				case 'string':
				case 'array':
				case 'object':
					$data->rule($name,'is_' . $field['type']);
				break;
				case 'email':
					$data->rule($name,'email');
				break;
				case 'counter':
					$data->rule($name,'is_int');
				break;
				case 'set':
					$data->rule($name,'is_array');
				break;
				case 'boolean':
					$data->rule($name,'Mango::_is_bool');
				break;
				case 'mixed':
					$data->rule($name,'Mango::_is_mixed');
				break;
			}

			// field is required
			if ( isset($field['required']) AND $field['required'] === TRUE)
			{
				$data->rule($name,'required');
			}

			// min/max length/value
			foreach ( array('min_value','max_value','min_length','max_length') as $rule)
			{
				if ( isset($field[$rule]))
				{
					$data->rule($name,$rule,array($field[$rule]));
				}
			}

			// value has to be unique
			if ( isset($field['unique']) && ! in_array($field['type'], array('set','has_many')) && $field['unique'] === TRUE)
			{
				$data->callback($name,array($this,'_is_unique'));
			}

			// filters contained in field spec
			if ( isset($field['filters']))
			{
				$data->filters($name,$field['filters']);
			}

			// rules contained in field spec
			if ( isset($field['rules']))
			{
				$data->rules($name,$field['rules']);
			}

			if ( isset($field['callbacks']))
			{
				$data->callbacks($name,$field['callbacks']);
			}
		}

		foreach ($this->_relations as $name => &$relation)
		{
			// belongs to ID field
			if ( $relation['type'] === 'belongs_to')
			{
				$data->rule($name . '_id','not_empty');
			}
		}

		return $data;
	}

	/**
	 * Formats a value into correct a valid value for the specific field
	 *
	 * @param   string  field name
	 * @param   mixed   field value
	 * @return  mixed   formatted value
	 */
	protected function load_field($name, $value)
	{
		// Load field data
		$field = $this->_fields[$name];

		switch ( $field['type'])
		{
			case 'MongoId':
				if ( $value !== NULL AND ! $value instanceof MongoId)
				{
					$value = new MongoId($value);
				}
			break;
			case 'enum':
				if ( is_numeric($value) && (int) $value == $value)
				{
					$value = isset($field['values'][$value]) ? $value : NULL;
				}
				else
				{
					$value = ($key = array_search($value,$field['values'])) !== FALSE ? $key : NULL;
				}
			break;
			case 'int':
				if ((float) $value > PHP_INT_MAX)
				{
					// This number cannot be represented by a PHP integer, so we convert it to a float
					$value = (float) $value;
				}
				else
				{
					$value = (int) $value;
				}
			break;
			case 'float':
				$value = (float) $value;
			break;
			case 'boolean':
				$value = (bool) $value;
			break;
			case 'email':
			case 'string':
				$value = trim((string) $value);
			break;
			case 'has_one':
				if ( is_array($value))
				{
					$value = Mango::factory($field['model'], $value, Mango::CLEAN);
				}

				if ( ! $value instanceof Mango)
				{
					$value = NULL;
				}
			break;
			case 'has_many':
				$value = new Mango_Set($value, $field['model'], ! isset($field['unique']) ? TRUE : $field['unique']);
			break;
			case 'counter':
				$value = new Mango_Counter($value);
			break;
			case 'array':
				$value = new Mango_Array($value, isset($field['type_hint']) ? $field['type_hint'] : NULL);
			break;
			case 'set':
				$value = new Mango_Set($value, isset($field['type_hint']) ? $field['type_hint'] : NULL, isset($field['unique']) ? $field['unique'] : FALSE);
			break;
			case 'mixed':
				$value = ! is_object($value)
					? $value
					: NULL;
			break;
		}

		return $value;
	}

	/**
	 * Checks if model is related to supplied model
	 *
	 * @throws  Mango_Exception  when relation does not exist
	 * @param   Mango    model
	 * @param   string   alternative name (if hm column has a name other than model name)
	 * @return  boolean  model is related
	 */
	public function has(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->_has($model, Inflector::plural($name));
	}

	/**
	 * Adds model to relation (if not already)
	 *
	 * @throws  Mango_Exception  when relation does not exist
	 * @param   Mango    model
	 * @param   string   alternative name (if hm column has a name other than model name)
	 * @return  boolean  relation exists
	 */
	public function add(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->_add($model, Inflector::plural($name));
	}

	/**
	 * Removes related model
	 *
	 * @throws  Mango_Exception  when relation does not exist
	 * @param   Mango    model
	 * @param   string   alternative name (if hm column has a name other than model name)
	 * @return  boolean  model is gone
	 */
	public function remove(Mango $model, $name = NULL)
	{
		if ( $name === NULL)
		{
			$name = (string) $model;
		}

		return $this->_remove($model, Inflector::plural($name));
	}

	/**
	 * Internal has method. Please use Mango::has
	 */
	public function _has(Mango $model, $relation)
	{
		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM 
			$field = $relation . '_ids';
			$value = $model->_id;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			// embedded Has Many
			$field = $relation;
			$value = $model;
		}
		else
		{
			throw new Mango_Exception('model :model has no relation with model :related',
				array(':model' => $this->_model, ':related' => $name));
		}

		return isset($field) 
			? ($this->__isset($field) ? $this->__get($field)->find($value) !== FALSE : FALSE) 
			: FALSE;
	}

	/**
	 * Internal add method. Please use Mango::add.
	 */
	public function _add(Mango $model, $relation, $returned = FALSE)
	{
		if ( $this->_has($model,$relation))
		{
			// already added
			return TRUE;
		}

		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM
			if ( ! $model->loaded() || ! $this->loaded() )
			{
				return FALSE;
			}

			$field = $relation . '_ids';

			// try to push
			if ( $this->__get($field)->push($model->_id))
			{
				// push succeed

				// column has to be reloaded
				unset($this->_related[$relation]);

				if ( ! $returned )
				{
					// add relation to model as well
					$model->_add($this,$this->_relations[$relation]['related_relation'],TRUE);
				}
			}

			// model has been added or was already added
			return TRUE;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			return $this->__get($relation)->push($model);
		}
		else
		{
			throw new Mango_Exception('there is no :relation specified', array(':relation' => $relation));
		}

		return FALSE;
	}

	/**
	 * Internal remove method. Please use Mango::remove
	 */
	public function _remove(Mango $model, $relation, $returned = FALSE)
	{
		if ( ! $this->_has($model,$relation))
		{
			// already removed
			return TRUE;
		}

		if ( isset($this->_relations[$relation]) && $this->_relations[$relation]['type'] === 'has_and_belongs_to_many')
		{
			// related HABTM
			if ( ! $model->loaded() || ! $this->loaded())
			{
				return FALSE;
			}

			$field = $relation . '_ids';

			// try to pull
			if ( $this->__get($field)->pull($model->_id))
			{
				// pull succeed

				// column has to be reloaded
				unset($this->_related[$relation]);

				if ( ! $returned )
				{
					// remove relation from related model as well
					$model->_remove($this,$this->_relations[$relation]['related_relation'],TRUE);
				}
			}

			// model has been removed or was already removed
			return TRUE;
		}
		elseif ( isset($this->_fields[$relation]) && $this->_fields[$relation]['type'] === 'has_many' )
		{
			// embedded Has_Many
			return $this->__get($relation)->pull($model);
		}
		else
		{
			throw new Mango_Exception('there is no :relation specified', array(':relation' => $relation));
		}

		return FALSE;
	}

	/**
	 * Validation callback
	 *
	 * Verifies if field is unique
	 */
	public function _is_unique(Validate $array, $field)
	{
		if ( $this->loaded() AND $this->_object[$field] === $array[$field])
		{
			// This value is unchanged
			return TRUE;
		}

		$found = $this->db()->find_one( $this->_collection, array($field => $array[$field]), array('_id'=>TRUE));

		if ( $found !== NULL)
		{
			$array->error($field,'is_unique');
		}
	}

	/*
	 * Validation rule
	 *
	 * A bit more flexible than PHP's is_bool to manage 'booleans' from eg form fields
	 */
	public static function _is_bool($value)
	{
		return in_array($value, array(1,0,'1','0',TRUE,FALSE));
	}

	/*
	 * Validation rule
	 *
	 * Validates anything not object
	 */
	public static function _is_mixed($value)
	{
		return ! is_object($value);
	}

	/**
	 * Normalize value to default format so values can be compared
	 *
	 * (comparing two identical objects in PHP will return FALSE)
	 *
	 * @param   mixed   value to normalize
	 * @return  mixed   normalized value
	 */
	public static function normalize($value)
	{
		if ( $value instanceof Mango_Interface)
		{
			return $value->as_array( FALSE );
		}
		elseif ( $value instanceof MongoId)
		{
			return (string) $value;
		}
		else
		{
			return $value;
		}
	}
}
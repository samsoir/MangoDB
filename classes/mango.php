<?php


// PLEASE NOTE THE LIMITATIONS OF THIS LIBRARY (listed below)


/* ORM library for MongoDB
 *
 * Supports atomic updates (using $set/$inc/$push etc)
 * Supports embedded objects (has_one and has_many)
 * Supports related objects (has_one, belongs_to, has_many, has_and_belongs_to_many)
 * Supports cascading deletes (if an object is deleted, it's has_one and has_many related objects are removed too)
 * Increment values using the increment() method
 * Manage embedded has_many relations / arrays using the push()/pull()/array_key() methods
 * Manage HABTM relations using the add()/remove()/has() methods
 *
 *
 * This library is supposed to work with Kohana PHP Framework - http://www.kohanaphp.org
 *
 * I took a lot of ideas from Kohana PHP Framework's ORM Library
 * @package    ORM
 * @author     Kohana Team
 * @copyright  (c) 2007-2008 Kohana Team
 * @license    http://kohanaphp.com/license.html
 *
 *
 * For updates / questions / suggestions:
 * 	Kohana Forum: http://forum.kohanaphp.com/comments.php?DiscussionID=2968
 * or
 * 	Mongo List: http://groups.google.com/group/mongodb-user/browse_thread/thread/6d21fa8d818f2966
 * 
 */


// LIMITATIONS

/* It is not possible to replicate full RDBMS behaviour in MongoDB - MongoDB != MySQL

 * This section will list the limitations

 * 1) You cannot add AND remove values from a set (set column type, embedded has_many relation, has_and_belongs_to_many relation)
 *    at the same time
 *    This is ok:
 *    $blog->posts[] = $post;
 *    $blog->posts[] = $post2;
 *    $blog->save();
 *    This is not ok!:
 *    $blog->posts[] = $post1;
 *    unset($blog->posts[1]); // this won't be removed because we are already adding
 *    $blog->save();

 * 2) If an value is pushed to/pulled from an array, you CANNOT edit anything else in that array
 *    Mongo does not support $set and $push/$pull modifiers accessing the same array.
 *    This is ok (assuming posts are embedded):
 *      $blog->time = time()
 *      $blog->posts[] = $post;
 *      $blog->save();
 *    This is not ok!
 *      $posts = $blog->posts;
 *      $posts[0]->text = 'editing some existing post'; // this won't be saved
 *      $posts[] = $post2;
 *      $blog->save();
 *    This is ok though:
 *      $blog->posts[0]->text = 'editing some existing post';
 *      $blog->save();
 *    As is this:
 *      $blog->posts = array($post1,$post2);
 *      $blog->save();
 *    Although this is not atomic, because this will reset the array completely instead of adding
 *    only the new posts

 * 3) Always run save() after your edits. Only when you save, things are actually written to the DB.
 *    When adding/removing HABTM relations, you also have to save the added/removed object:
 *    $blog->add($manager);
 *    $blog->save();
 *    $manager->save();

 * 4) You cannot run modifiers on indexed fields. This should be fixed from 1.1.0
 */

// TODOs

// add & test $pull (should be availabe from Mongo 0.9.7)
// add & test $pushAll / $pullAll (Mongo 1.1.0??)
// $unset support
// something script to automatically set proper indexes in mongo (foreign keys / unique keys)

class Mango implements Mango_Interface {

	// Object information
	protected $_columns = array();
	protected $_embedded = FALSE;
	protected $_collection_name;
	protected $_db = 'default';

	// define relations
	protected $_has_one = array();
	protected $_has_many = array();
	protected $_belongs_to = array();
	protected $_has_and_belongs_to_many = array();

	// Current object
	protected $_object  = array();
	protected $_related = array();
	protected $_changed = array();
	protected $_loaded  = FALSE;
	protected $_saved   = FALSE;

	// Object information
	protected $_object_name;
	protected $_object_plural;

	// Mango config data
	protected static $_config;

	// Factory
	public static function factory($object_name,$id = NULL)
	{
		if(self::$_config === NULL)
		{
			// load config
			self::$_config = Kohana::config('mango');
		}

		if (is_array($id) && isset(self::$_config['cti']))
		{
			// object might be extended - read for extension data
			while(isset(self::$_config['cti'][$object_name]))
			{
				$type_key = key(self::$_config['cti'][$object_name]);

				if (isset($id[$type_key]) && isset(self::$_config['cti'][$object_name][$type_key][$id[$type_key]]))
				{
					// extension found - update model_name
					$object_name = self::$_config['cti'][$object_name][$type_key][$id[$type_key]];
				}
				else
				{
					break;
				}
			}
		}

		$model = 'Model_' . ucfirst($object_name);
		return new $model($id);
	}

	// Constructor
	public function __construct($id = NULL)
	{
		$this->initialize();

		if (is_array($id))
		{
			// Load an object from array
			$this->load_values($id);
		}
		elseif (!empty($id))
		{
			// Find an object by ID
			$this->find( $this->unique_criteria($id) , 1 );
		}
	}

	public function initialize()
	{
		// Derive object_name
		$this->_object_name   = strtolower(substr(get_class($this), 6));
		$this->_object_plural = inflector::plural($this->_object_name);

		// set columns/relationships
		$this->set_model_definition();

		if (!$this->_embedded )
		{
			// Setup DB if not embedded
			if (empty($this->_collection_name))
			{
				// Collection name is the same as plural of object_name
				$this->_collection_name = $this->_object_plural;
			}

			// Add ID field to columns
			if( ! isset($this->_columns['_id']) )
			{
				$this->_columns['_id'] = array('type'=>'MongoId');
			}

			// Add foreign key IDs
			foreach($this->_belongs_to as $object_name)
			{
				if( ! isset($this->_columns[$object_name . '_id']) )
				{
					$this->_columns[$object_name . '_id'] = array('type'=>'MongoId');
				}
			}

			foreach($this->_has_and_belongs_to_many as $object_plural)
			{
				$this->_columns[$object_plural . '_ids'] = array('type'=>'set');
			}

			// Initialize DB
			if(! is_object($this->_db) )
			{
				$this->_db = MangoDB::instance($this->_db);
			}
		}
	}

	protected function set_model_definition() {}

	// Update columns/relationships during initialization
	// Primary used in inheritance patterns
	protected function _set_model_definition(array $definition = NULL)
	{
		if(isset($definition['_columns']))
		{
			$this->_columns = array_merge($this->_columns,$definition['_columns']);
		}

		if(isset($definition['_has_one']))
		{
			$this->_has_one = array_merge($this->_has_one,$definition['_has_one']);
		}

		if(isset($definition['_has_many']))
		{
			$this->_has_many = array_merge($this->_has_many,$definition['_has_many']);
		}

		if(isset($definition['_has_and_belongs_to_many']))
		{
			$this->_has_and_belongs_to_many = array_merge($this->_has_and_belongs_to_many,$definition['_has_and_belongs_to_many']);
		}
	}

	// Find one or more objects (documents) in collection
	public function find(array $criteria = array(),$limit = NULL,array $sort = NULL,$fields = array())
	{
		if($this->_embedded)
		{
			// no database interaction on embedded objects
			throw new Kohana_Exception('no queries possible on embedded objects');
		}

		if($limit === 1 && $sort === NULL)
		{
			// looking for ID or limiting 1 without sort -> use findOne
			$values = $this->_db->find_one($this->_collection_name,$criteria,$fields);
			return $values !== NULL ? $this->load_values($values) : $this->clear();
		}
		else
		{
			// looking for 2+ objects or sorting - use find
			$values = $this->_db->find($this->_collection_name,$criteria,$fields);

			if($limit !== NULL)
			{
				$values->limit($limit);
			}

			if($sort !== NULL)
			{
				$values->sort($sort);
			}

			$result = new Mango_Iterator($this->_object_name,$values);

			if($limit === 1)
			{
				return $result->count() ? $result->current() : NULL;
			}
			else
			{
				return $result;
			}
		}
	}

	// Reload object from database
	public function reload()
	{
		return $this->_loaded ? $this->find( array('_id' => $this->_id), 1 ) : $this->clear();
	}

	// Returns all changes made to this object after last save
	// if $update === TRUE, will format updates using modifiers and dot notated keystrings
	public function get_changed($update, array $prefix= array())
	{
		$changed = array();

		foreach($this->_columns as $column_name => $column_data)
		{
			$value = $this->__isset($column_name) ? $this->_object[$column_name] : NULL;

			if (isset($column_data['local']) && $column_data['local'] === TRUE)
			{
				// local variables are not stored in DB
				continue;
			}

			// prepare prefix
			$level = $prefix;
			$level[] = $column_name;

			if (isset($this->_changed[$column_name]))
			{
				// value has been changed
				if($value instanceof Mango_Interface)
				{
					$value = $value->as_array();
				}

				if($update)
				{
					$changed = arr::merge($changed,array('$set'=>array( implode('.',$level) => $value) ) );
				}
				else
				{
					$changed = arr::merge($changed, arr::path_set($level,$value) );
				}
			}
			elseif ($this->__isset($column_name))
			{
				// check any (embedded) objects/arrays/sets
				if($value instanceof Mango_Interface)
				{
					$changed = arr::merge($changed, $value->get_changed($update,$level));
				}
			}
		}

		return $changed;
	}

	// Returns object (and its embedded objects) as associative array
	public function as_array()
	{
		$array = array();

		foreach($this->_object as $column_name => $value)
		{
			$array[$column_name] = $value instanceof Mango_Interface ? $value->as_array() : $value;
		}

		return $array;
	}

	// Set status to saved and empties changed array
	// in all of this document and its embedded data
	public function set_saved()
	{
		foreach($this->_object as $column_name => $value)
		{
			if($value instanceof Mango_Interface)
			{
				$value->set_saved();
			}
		}

		$this->_loaded = $this->_saved = TRUE;
		$this->_changed = array();
	}

	// Validate data before saving
	protected function validate(Validate $validate, $save = FALSE)
	{
		foreach($this->_columns as $column_name => $column_data)
		{
			// Add rules based on $_columns data
			
			switch($column_data['type'])
			{
				case 'enum':
					$validate->rule($column_name,'in_array',array($column_data['values']));
				break;
				case 'int':
				case 'float':
				case 'string':
				case 'array':
					$validate->rule($column_name,'is_' . $column_data['type']);
				break;
				case 'boolean':
					$validate->rule($column_name,'is_bool');
				break;
				case 'counter':
					$validate->rule($column_name,'is_int');
				break;
				case 'set':
					$validate->rules($column_name,'is_array');
				break;
			}

			if(isset($column_data['required']) AND $column_data['required'] === TRUE)
			{
				$validate->rule($column_name,'required');
			}

			foreach( array('min_value','max_value','min_length','max_length') as $rule)
			{
				if(isset($column_data[$rule]))
				{
					$validate->rule($column_name,$rule,array($column_data[$rule]));
				}
			}
		}

		foreach($this->_belongs_to as $parent)
		{
			$validate->rule($parent . '_id','required');
		}

		if($status = $validate->check())
		{
			$fields = $validate->as_array();

			foreach($fields as $column => $value)
			{
				if( ($value === NULL || $value === '') AND ! $this->__isset($column))
				{
					// don't set empty values
					continue;
				}

				$this->$column = $value;
			}

			if ($save === TRUE OR is_string($save))
			{
				// Save this object
				$this->save();

				if (is_string($save))
				{
					// Redirect to the saved page
					url::redirect($save);
				}
			}
		}

		return $status;
	}

	// Save object
	public function save()
	{
		if($this->_embedded)
		{
			return FALSE;
		}
		
		$update = $this->get_changed( $this->_loaded );
		
		if(! empty($update))
		{
			if($this->_loaded === TRUE)
			{
				// Exists in DB - update
				$this->_db->update($this->_collection_name,array('_id'=>$this->_id), $update, TRUE);
			}
			else
			{
				// checking of duplicate ID (the insert query does not tell you if the insert was sucessful)

				$user_defined_id = isset($update['_id']);

				do
				{
					// try to insert data into collection
					$this->_db->insert($this->_collection_name, $update );

					// read error
					$err = $this->_db->last_error();

					// just a safety measure - not sure if the database throws other errors here
					// that would throw this into a endless loop
					$try = isset($try) ? $try + 1 : 1; 
				}
				while( $err['err'] && ! $user_defined_id && $try < 5 );

				if($err['err'])
				{
					// Something went wrong - throw error
					throw new Kohana_Exception('Database returned error :error',
						array(':error' => $err['err']));
				}

				if( ! isset($this->_object['id'] ) )
				{
					// Store (assigned) MongoID in object
					$this->_object['_id'] = $this->load_type('_id',$update['_id'],FALSE);
				}
			}
		}

		// Everything is up to date now
		$this->set_saved();

		return $this->_saved;
	}

	// Deletes object
	public function delete($id = NULL)
	{
		if ($id === NULL)
		{
			if( ! $this->_loaded || $this->_embedded )
			{
				return FALSE;
			}

			// Use the the primary key value
			$id = $this->_object['_id'];
		}
		else if($id !== $this->_id)
		{
			// call delete method in actual object - to make sure it's children are deleted properly
			return Mango::factory($this->_object_name,$id)->delete();
		}

		// Delete children
		foreach($this->_has_one as $ha)
		{
			$this->$ha->delete();
		}

		foreach($this->_has_many as $hm)
		{
			// Remove each object separately because delete method could be overloaded
			foreach($this->__get($hm) as $h)
			{
				$h->delete();
			}
		}

		// Delete HABTM relation info
		$foreign_column_name = $this->_object_plural . '_ids';
		foreach($this->_has_and_belongs_to_many as $hb)
		{
			$column_name = $hb . '_ids';
			
			if(! empty($this->$column_name))
			{
				// we can do DB.eval here
				$this->_db->execute('function () {'.
				'  db.' . $hb . '.find({_id: { $in:[ObjectId(\''. implode('\',\'',$this->$column_name->as_array() ) . '\')]}}).forEach( function(obj) {'.
				'    db.' . $hb . '.update({_id:obj._id},{ $pull : { ' . $foreign_column_name . ': ObjectId(\'' .  $this->_id . '\')}});'.
				'  });'.
				'}');
			}
		}

		// Delete this object
		$this->_db->remove( $this->_collection_name, $this->unique_criteria($id), TRUE);

		return $this->clear();
	}

	// Load an array of values into object
	public function load_values(array $values)
	{
		if (array_key_exists('_id', $values) || $this->_embedded)
		{
			// Replace the object and reset the object status
			$this->_object = $this->_changed = $this->_related = array();

			// Set the loaded and saved object status based on the primary key
			$this->_loaded = $this->_saved = $this->_embedded || ($values['_id'] !== NULL);
		}

		foreach ($values as $column => $value)
		{
			if (isset($this->_columns[$column]))
			{
				$this->_object[$column] = $this->load_type($column, $value, FALSE);
			}
		}

		return $this;
	}

	// Serialize info
	public function __sleep()
	{
		return array('_object', '_changed', '_loaded', '_saved');
	}

	// Serialize info
	public function __wakeup()
	{
		$this->initialize();
	}

	// Empty object
	public function clear()
	{
		// Create an array with all the columns set to NULL
		$columns = array_keys($this->_columns);
		$values  = array_combine($columns, array_fill(0, count($columns), NULL));

		// Replace the current object with an empty one
		$this->load_values($values);

		return $this;
	}

	public function has(Mango $model)
	{
		$object_plural = $model->_object_plural;

		if(in_array($object_plural,$this->_has_and_belongs_to_many))
		{
			$column = $object_plural . '_ids';
		}
		elseif ( isset($this->_columns[$object_plural]) && $this->_columns[$object_plural]['type'] === 'has_many' )
		{
			$column = $object_plural;
		}

		return isset($column) ? ($this->__isset($column) ? $this->__get($column)->find($model) !== FALSE : FALSE) : FALSE;
	}

	public function add(Mango $model, $returned = FALSE)
	{
		$object_plural = $model->_object_plural;

		if($this->has($model))
		{
			// already added
			return TRUE;
		}

		if(in_array($object_plural,$this->_has_and_belongs_to_many))
		{
			if( ! $model->_loaded || ! $this->_loaded )
				return FALSE;

			$column = $model->_object_plural . '_ids';

			// try to push
			if($this->__get($column)->push($model->_id))
			{
				// push succeed
				if( isset($this->_related[$object_plural]) )
				{
					// Related models have been loaded already, add this one
					$this->_related[$object_plural][] = $model;
				}

				if( ! $returned )
				{
					// add relation to model as well
					$model->add($this,TRUE);
				}
			}

			// model has been added or was already added
			return TRUE;
		}
		elseif ( isset($this->_columns[$object_plural]) && $this->_columns[$object_plural]['type'] === 'has_many' )
		{
			return $this->__get($object_plural)->push($model);
		}
		
		return FALSE;
	}

	public function remove(Mango $model, $returned = FALSE)
	{
		$object_plural = $model->_object_plural;

		if(! $this->has($model))
		{
			// already removed
			return TRUE;
		}

		if(in_array($object_plural,$this->_has_and_belongs_to_many))
		{
			if( ! $model->_loaded || ! $this->_loaded )
				return FALSE;

			$column = $model->_object_plural . '_ids';

			// try to pull
			if($this->__get($column)->pull($model->_id))
			{
				// pull succeed
				if( isset($this->_related[$object_plural]) )
				{
					// Related models have been loaded already, remove this one
					$related = array();
					foreach($this->_related[$object_plural] as $objecT)
					{
						if($object->as_array() !== $model->as_array())
						{
							$related[] = $object;
						}
					}
					$this->_related[$object_plural] = $related;
				}

				if( ! $returned )
				{
					// add relation to model as well
					$model->remove($this,TRUE);
				}
			}

			// model has been removed or was already removed
			return TRUE;
		}
		elseif ( isset($this->_columns[$object_plural]) && $this->_columns[$object_plural]['type'] === 'has_many' )
		{
			return $this->__get($object_plural)->pull($model);
		}

		return FALSE;
	}

	// Magic set
	public function __set($column,$value = NULL)
	{
		if (isset($this->_columns[$column]))
		{
			// update object
			$this->_object[$column] = $value === NULL ? NULL : $this->load_type($column,$value);

			// object is no longer saved
			$this->_saved = FALSE;

			$this->_changed[$column] = TRUE;
		}
		elseif (in_array($column,$this->_belongs_to) )
		{
			if($value instanceof Mango && $value->_loaded)
			{
				$foreign_key = $column . '_id';

				if($this->__get($foreign_key) !== $value->_id)
				{
					$this->__set($foreign_key,$value->_id);
				}

				$this->_related[$column] = $value;
			}
			else
			{
				throw new Kohana_Exception('Please provide parent object');
			}
		}
	}

	// Load a value into a column
	protected function load_type($column, $value)
	{
		// Load column data
		$column_data = $this->_columns[$column];

		switch($column_data['type'])
		{
			case 'MongoId':
				if( $value !== NULL AND ! $value instanceof MongoId)
				{
					$value = new MongoId($value);
				}
			break;
			case 'enum':
				if(is_int($value))
				{
					$value = isset($column_data['values'][$value]) ? $value : NULL;
				}
				else
				{
					$value = ($key = array_search($value,$column_data['values'])) !== FALSE ? $key : NULL;
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
			case 'string':
				$value = (string) $value;
			break;
			case 'has_one':
				if(is_array($value))
				{
					$value = Mango::factory($column,$value);
				}
				
				if( ! ($value instanceof Mango) || $value->_object_name !== $column )
				{
					$value = NULL;
				}
			break;
			case 'has_many':
				if(! is_array($value))
				{
					$value = NULL;
				}
				else
				{
					$value = new Mango_Set($value,inflector::singular($column));
				}
			break;
			case 'counter':
				$value = is_numeric($value) ? new Mango_Counter($value) : NULL;
			break;
			case 'array':
				$value = is_array($value) ? new Mango_Array($value, isset($column_data['type_hint']) ? $column_data['type_hint'] : NULL) : NULL;
			break;
			case 'set':
				$value = is_array($value) ? new Mango_Set($value, isset($column_data['type_hint']) ? $column_data['type_hint'] : NULL) : NULL;
			break;
		}

		if($value !== NULL)
		{
			switch($column_data['type'])
			{
				case 'int':
				case 'float':
					if(isset($column_data['min_value']) AND $value < $column_data['min_value'])
					{
						$value = NULL;
					}
					if(isset($column_data['max_value']) AND $value > $column_data['max_value'])
					{
						$value = NULL;
					}
				break;
				case 'string':
					if(isset($column_data['min_length']) AND UTF8::strlen($value) < $column_data['min_length'])
					{
						$value = NULL;
					}
					if(isset($column_data['max_length']) AND UTF8::strlen($value) > $column_data['max_length'])
					{
						$value = NULL;
					}
				break;
			}
		}

		return $value;
	}

	// Magic get
	public function __get($column)
	{
		if( isset($this->_columns[$column] ) )
		{
			// fetch value
			$value = $this->__isset($column) ? $this->_object[$column] : NULL;

			// fetch column data
			$column_data = $this->_columns[$column];

			switch($column_data['type'])
			{
				case 'enum':
					$value = isset($value) && isset($column_data['values'][$value]) ? $column_data['values'][$value] : NULL;
				break;
				case 'has_one':
					if($value === NULL)
					{
						/*$this->__set($column,Mango::factory($column));
						$value = $this->_object[$column];*/
						$value = $this->_object[$column] = Mango::factory($column);
					}
				break;
				case 'set':
				case 'has_many':
				case 'array':
					if($value === NULL)
					{
						// 'secretly' load type into _object (not via __get, does not modify $this->_changed)
						// any changes made to this object, are stored in the object's _changed and loaded
						// when get_changed is run
						$value = $this->_object[$column] = $this->load_type($column,array(),FALSE);
					}
				break;
				case 'counter':
					if($value === NULL)
					{
						$value = $this->_object[$column] = $this->load_type($column,0,FALSE);
					}
				break;
			}

			// check for default value
			if($value === NULL && isset($column_data['default']) && !array_key_exists($column,$this->_object))
			{
				// default value only applies if value is NULL and has not been purposely set to NULL
				$value = $column_data['default'];
			}

			return $value;
		}
		elseif (isset($this->_related[$column] ) )
		{
			return $this->_related[$column];
		}
		elseif (in_array($column,$this->_has_one) )
		{
			// has one - child contains foreign key
			return $this->_related[$column] = Mango::factory($column)->find( array($this->_object_name . '_id' => $this->_id) , 1);
		}
		elseif (in_array($column,$this->_belongs_to) )
		{
			// belongs to - this object contains foreign key
			return $this->_related[$column] = ($this->__isset($column . '_id') ? Mango::factory($column,$this->_object[$column.'_id']) : NULL);
		}
		elseif (in_array($column,$this->_has_many) )
		{
			// has many - children contain foreign key
			$object_name = inflector::singular($column);
			return $this->_related[$column] = Mango::factory($object_name)->find(array($this->_object_name . '_id' => $this->_id));
		}
		elseif (in_array($column,$this->_has_and_belongs_to_many) )
		{
			// has and belongs to many - IDs are stored in object
			$model = Mango::factory( inflector::singular($column) );
			$column_name = $model->_object_plural . '_ids';

			return $this->_related[$column] = ! empty($this->$column_name) ? $model->find(array('_id'=> array('$in' => $this->$column_name))) : array();
		}
		elseif (isset($this->$column) )
		{
			// local variable
			return $this->$column;
		}
		else
		{
			throw new Kohana_Exception('Property :column does not exist in :object', array(
				':column'=>$column,
				':object'=>$this->_object_name
			));
		}
	}

	public function __isset($column)
	{
		return isset($this->_columns[$column]) ? isset($this->_object[$column]) : isset($this->_related[$column]);
	}

	public function __unset($column)
	{
		// no support for $unset yet, now setting to NULL (if value was set)
		if($this->__isset($column))
		{
			$this->__set($column,NULL);
		}
	}

	public function unique_criteria($id)
	{
		return array('_id' => $id);
	}

}
?>
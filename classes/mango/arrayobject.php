<?php

/*
 * ArrayObject base class used by both Mango_Set (corresponds to Javascript array) and Mango_Array (corresponds to Javascript object)
 */

class Mango_ArrayObject extends ArrayObject implements Mango_Interface {

	/*
	 * Remembers changes made to this array object (for updating)
	 */
	protected $_changed = array();

	/*
	 * Stores the type of value stored in this array object
	 */
	protected $_type_hint;

	/*
	 * Constructor
	 *
	 * @param   array   Current data
	 * @param   string  Type Hint
	 * @return  void
	 */
	public function __construct($array = array(),$type_hint = NULL)
	{
		// Make sure we're dealing with an array
		if($array instanceof Mango_ArrayObject)
		{
			$array = $array->as_array(FALSE);
		}
		elseif (!is_array($array))
		{
			$array = array();
		}

		// create
		parent::__construct($array,ArrayObject::STD_PROP_LIST);

		if($type_hint !== NULL)
		{
			// set typehint
			$this->_type_hint = strtolower($type_hint);

			// load to make sure values are of correct type
			$this->load();
		}
	}

	/*
	 * Returns an array with changes - implemented by child classes
	 */
	public function changed($update, array $prefix = array()) {}

	/*
	 * Ensures all values are of correct type
	 */
	public function load()
	{
		foreach($this as &$value)
		{
			// replace by value loaded to correct type
			$value = $this->load_type($value,$this->_type_hint);
		}
	}

	/*
	 * Autodetects type (set or array)
	 */
	public function type_hint(array $value)
	{
		return array_keys($value) === range(0, count($value) - 1) ? 'set' : 'array';
	}

	/*
	 * Loads a value into correct type
	 */
	public function load_type($value)
	{
		if($this->_type_hint === NULL)
		{
			// no type_hint
			return $value;
		}

		switch($this->_type_hint)
		{
			case NULL:
				// do nothing
			break;
			case 'counter':
				if(is_array($value))
				{
					// multidimensional counter
					$value = $this->type_hint($value) === 'set' ? new Mango_Set($value,$this->_type_hint) : new Mango_Array($value,$this->_type_hint);
				}
				else
				{
					// simple counter
					$value = new Mango_Counter($value,$this->_type_hint);
				}
			break;
			case 'set':
				if(is_array($value))
				{
					$value = new Mango_Set($value,$this->_type_hint);
				}
			break;
			case 'array':
				if(is_array($value))
				{
					$value = new Mango_Array($value,$this->_type_hint);
				}
			break;
			default:
				$value = is_object($value) 
					? $value
					: Mango::factory($this->_type_hint,$value,Mango::CLEAN);
			break;
		}

		return $value;
	}

	/*
	 * Returns object as array
	 */
	public function getArrayCopy()
	{
		return $this->as_array();
	}

	/*
	 * Returns object as array
	 *
	 * @param   boolean  fetch value directly from object
	 * @return  array    array representation of array object
	 */
	public function as_array( $clean = TRUE )
	{
		$array = parent::getArrayCopy();

		foreach($array as &$value)
		{
			if ($value instanceof Mango_Interface)
			{
				$value = $value->as_array( $clean );
			}
		}

		return $array;
	}

	/*
	 * Set status to saved
	 */
	public function saved()
	{
		$this->_changed = array();

		foreach($this as $value)
		{
			if ($value instanceof Mango_Interface)
			{
				$value->saved();
			}
		}
	}

	/*
	 * Fetch value
	 */
	public function offsetGet($index)
	{
		if (! $this->offsetExists($index) )
		{
			// implicit set ($array[1][2] = 3)
			switch($this->_type_hint)
			{
				case 'array':
				case 'set':
					$value = array();
				break;
				case 'counter':
					$value = 0;
				break;
				default:
					// implicit set is only possible when we know the array type.
					throw new Kohana_Exception('Set typehint to \'set\', \'array\' or \'counter\' (now: :typehint) to support implicit array creation', 
						array(':typehint' => $this->_type_hint ? '\''.$this->_type_hint.'\'' : 'not set'));
			}
			// secretly set value (via parent::offsetSet, so no change is recorded)
			parent::offsetSet($index,$this->load_type($value));
		}

		return parent::offsetGet($index);
	}

	/*
	 * Set key to value
	 */
	public function offsetSet($index,$newval)
	{
		// make sure type is correct
		$newval = $this->load_type($newval);

		if($index !== NULL && $this->offsetExists($index))
		{
			$current = $this->offsetGet($index);

			// only update if new data
			if ( Mango::normalize($current) === Mango::normalize($newval))
			{
				return FALSE;
			}
		}

		// set
		parent::offsetSet($index,$newval);

		// on $array[], the $index newval === NULL
		if($index === NULL)
		{
			$index = $this->find($newval);
		}

		return $index;
	}

	/*
	 * Find index of value in array object
	 */
	public function find($needle)
	{
		// change type so we can compare over ===
		if ($needle instanceof Mango_Interface)
		{
			// we use false to prevent comparison of MongoEmptyObjs (which always fails)
			$needle = $needle->as_array( FALSE );
		}
		elseif ($needle instanceof MongoId)
		{
			$needle = (string)$needle;
		}

		// try all keys
		foreach($this as $key => $val)
		{
			if ($val instanceof Mango_Interface)
			{
				// we use false to prevent comparison of MongoEmptyObjs (which always fails)
				$val = $val->as_array( FALSE );
			}
			elseif ($val instanceof MongoId)
			{
				$val = (string) $val;
			}

			if ($val === $needle)
			{
				// found
				return $key;
			}
		}
		return FALSE;
	}

	/*
	 * Move pointer to end of array and return value
	 */
	public function end()
	{
		return end($this->as_array());
	}

	/*
	 * Pop last value from array and return
	 */
	public function pop()
	{
		// move pointer to end
		$arr = $this->as_array();
		end($arr);

		$key = key($arr);

		if($this->offsetExists($key))
		{
			$value = $this->offsetGet($key);

			return $this->offsetUnset($key) !== FALSE ? $value : FALSE;
		}
		else
		{
			return FALSE;
		}
	}

	/*
	 * Recursively find a dot notated key string
	 *
	 * @param  string|array  dot notated keystring or exploded array
	 * @param  mixed         default value to return if key not found
	 * @return  mixed        value (if found) or default value
	 */
	public function locate($key, $default = NULL)
	{
		if ( ! is_array($key))
		{
			$key = explode('.',(string) $key);
		}

		// fetch next key
		$next = array_shift($key);

		// read next key
		$value = isset($this[$next])
			? $this[$next]
			: NULL;

		if ( count($key))
		{
			// go deeper
			$value = $value instanceof Mango_ArrayObject
				? $value->locate($key,$default)
				: NULL;
		}

		// return
		return $value !== NULL
			? ($value instanceof Mango_Interface ? $value->as_array() : $value)
			: $default;
	}

	/*
	 * Create an (associative) array of values from this array object
	 *
	 * $blog->comments->select_list('id','author');
	 * $blog->comments->select_list('author');
	 */
	public function select_list($key = 'id',$val = NULL)
	{
		if($val === NULL)
		{
			$val = $key;
			$key = NULL;
		}

		$list = array();

		foreach($this as $object)
		{
			if($key !== NULL)
			{
				$list[$object->$key] = $object->$val;
			}
			else
			{
				$list[] = $object->$val;
			}
		}

		return $list;
	}

	/*
	 * Push a value onto array, similar to $array[]
	 */
	public function push($newval)
	{
		return $this->offsetSet(NULL,$newval);
	}

	/*
	 * Pull a value from array
	 */
	public function pull($oldval)
	{
		if( ($index = $this->find($this->load_type($oldval))) !== FALSE )
		{
			$this->offsetUnset( $index );
		}

		return TRUE;
	}
}
<?php

class Mango_ArrayObject extends ArrayObject implements Mango_Interface {

	protected $_changed = array();
	protected $_type_hint;

	public function __construct($array = array(),$type_hint = NULL)
	{
		if($array instanceof Mango_ArrayObject)
		{
			$array = $array->as_array(TRUE);
		}
		elseif (!is_array($array))
		{
			$array = array();
		}
		
		parent::__construct($array,ArrayObject::STD_PROP_LIST);

		$this->_type_hint = strtolower($type_hint);

		if($this->_type_hint !== NULL)
		{
			$this->load();
		}
	}

	// Implemented by child classes
	public function get_changed($update, array $prefix = array()) {}

	public function load()
	{
		foreach($this as &$value)
		{
			$value = $this->load_type($value,$this->_type_hint);
		}
	}

	// Tries to detect array type of value; (Mango_)set or (Mango_)array
	public function type_hint(array $value)
	{
		return array_keys($value) === range(0, count($value) - 1) ? 'set' : 'array';
	}

	public function load_type($value)
	{
		if($this->_type_hint === NULL)
		{
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
					$value = $this->type_hint($value) === 'set' ? new Mango_Set($value,$this->_type_hint) : new Mango_Array($value,$this->_type_hint);
				}
				else
				{
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
				$value = is_object($value) ? $value : Mango::factory($this->_type_hint,$value);
			break;
		}

		return $value;
	}

	public function getArrayCopy()
	{
		return $this->as_array();
	}

	public function as_array( $debug = FALSE )
	{
		$array = parent::getArrayCopy();

		foreach($array as &$value)
		{
			if ($value instanceof Mango_Interface)
			{
				$value = $value->as_array( $debug );
			}
		}

		return $array;
	}

	public function set_saved()
	{
		$this->_changed = array();

		foreach($this as $value)
		{
			if ($value instanceof Mango_Interface)
			{
				$value->set_saved();
			}
		}
	}

	public function offsetGet($index)
	{
		if (! $this->offsetExists($index) )
		{
			// multi dimensional array action - implicit set
			// EG $this->set[1][2] = '3';
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
			// we use parent::offsetSet so no change is recorded
			// (isset($array[12]) should not create a value at key 12)
			parent::offsetSet($index,$this->load_type($value));
		}

		return parent::offsetGet($index);
	}

	public function offsetSet($index,$newval)
	{
		$newval = $this->load_type($newval);

		if($index !== NULL && $this->offsetExists($index))
		{
			$current = $this->offsetGet($index);
			// return FALSE if new value is the same as current value
			if($current === $newval)
			{
				return FALSE;
			}
			elseif ($newval instanceof Mango_Interface && $current instanceof Mango_Interface && $newval->as_array() === $current->as_array())
			{
				return FALSE;
			}
			elseif ($newval instanceof MongoId && $current instanceof MongoId && (string) $newval === (string) $current)
			{
				return FALSE;
			}
		}

		parent::offsetSet($index,$newval);

		// on $array[], the $index newval === NULL
		if($index === NULL)
		{
			$index = $this->find($newval);
		}

		return $index;
	}

	public function find($needle)
	{
		if ($needle instanceof Mango_Interface)
		{
			$needle = $needle->as_array();
		}
		elseif ($needle instanceof MongoId)
		{
			$needle = (string)$needle;
		}

		foreach($this as $key => $val)
		{
			if ($val instanceof Mango_Interface)
			{
				$val = $val->as_array();
			}
			elseif ($val instanceof MongoId)
			{
				$val = (string) $val;
			}

			if ($val === $needle)
			{
				return $key;
			}
		}
		return FALSE;
	}

	public function end()
	{
		return end($this->as_array());
	}

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

	// lookup a / notated key string in a multi dimensional array
	public function locate($key,$default = NULL)
	{
		if( !is_array($key))
		{
			$key = explode('.',(string)$key);
		}

		$next = array_shift($key);

		if(isset($this[$next]))
		{
			if(count($key))
			{
				return $this[$next] instanceof Mango_ArrayObject ? $this[$next]->locate($key) : NULL;
			}
			else
			{
				return $this[$next];
			}
		}
		else
		{
			return $default;
		}
	}

	// Return an (associative) array of values
	// $blog->comments->select_list('id','author');
	// $blog->comments->select_list('author');
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

}
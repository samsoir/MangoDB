<?php

class Mango_ArrayObject extends ArrayObject implements Mango_Interface {

	protected $_changed = array();
	protected $_type_hint;

	public function __construct(array $array = array(),$type_hint = NULL)
	{
		parent::__construct($array,ArrayObject::STD_PROP_LIST);

		$this->_type_hint = $type_hint;

		$this->load();
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

	public function type_hint(array $value)
	{
		return array_keys($value) === range(0, count($value) - 1) ? 'set' : 'array';
	}

	public function load_type($value)
	{
		$type_hint = $this->_type_hint === 'auto' && is_array($value) ? $this->type_hint($value) : $this->_type_hint;

		if($type_hint !== NULL)
		{
			switch(strtolower($type_hint))
			{
				case 'counter':
					if(is_array($value))
					{
						$value = $this->type_hint($value) === 'set' ? new Mango_Set($value,$type_hint) : new Mango_Array($value,$type_hint);
					}
					else
					{
						$value = new Mango_Counter($value);
					}
				break;
				case 'set':
					$value = new Mango_Set($value);
				break;
				case 'array':
					$value = new Mango_Array($value);
				break;
				default:
					$value = is_object($value) ? $value : Mango::factory($type_hint,$value);
				break;
			}
		}

		return $value;
	}

	public function getArrayCopy()
	{
		return $this->as_array();
	}

	public function as_array( $__get = FALSE )
	{
		$array = parent::getArrayCopy();
	
		foreach($array as &$value)
		{
			if ($value instanceof Mango_Interface)
			{
				$value = $value->as_array( $__get );
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

	public function offsetSet($index,$newval)
	{
		$newval = $this->load_type($newval);

		parent::offsetSet($index,$newval);

		// on $array[], the $index value === NULL
		if($index === NULL)
		{
			$index = $this->find($newval);
		}

		return $index;
	}

	public function find($needle)
	{
		if($needle instanceof Mango_Interface)
		{
			$needle = $needle->as_array();
		}

		foreach($this as $key => $val)
		{
			if( ($val instanceof Mango_Interface && $val->as_array() === $needle) || ($val === $needle))
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
	public function locate($key)
	{
		if(is_string($key))
		{
			$key = explode('.',$key);
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
			return NULL;
		}
	}
}
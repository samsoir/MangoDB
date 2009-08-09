<?php

class Mango_Array extends Mango_ArrayObject {

	public function get_changed($update, array $prefix = array())
	{
		$changed = array();

		// Get all keys of array (current keys + unset keys)
		$keys = array();
		foreach($this as $key => $value)
		{
			$keys[] = $key;
		}
		$changed_keys = array_keys($this->_changed);
		// don't use array_unique(array_merge($changed_keys,$keys)) - array_unique has issues with 0 values (eg array_unique( array('a',0,0,'a','c'));
		$keys = array_merge( $keys, array_diff($changed_keys,$keys) );

		// Walk through array
		foreach($keys as $key)
		{
			$value = $this->offsetExists($key) ? $this->offsetGet($key) : NULL;

			if(isset($this->_changed[$key]))
			{
				// value has been changed (set/unset)
				// todo add $unset when available (now unset vars are set to NULL

				$value = $value instanceof Mango_Interface ? $value->as_array() : $value;

				$path = array_merge($prefix,array($key));

				if($update)
				{
					$changed = arr::merge($changed, array( '$set' => array( implode('.',$path) => $value) ) );
				}
				else
				{
					$changed = arr::merge($changed, arr::path_set($path,$value) );
				}
			}
			elseif ($value instanceof Mango_Interface)
			{
				$changed = arr::merge($changed, $value->get_changed($update, array_merge($prefix,array($key))));
			}
		}

		return $changed;
	}

	public function offsetSet($index,$newval)
	{
		if(($index = parent::offsetSet($index,$newval)) !== FALSE)
		{
			// new value - remember change
			$this->_changed[$index] = TRUE;
		}
	}

	public function offsetUnset($index)
	{
		parent::offsetUnset($index);

		$this->_changed[$index] = FALSE;
	}

	public function as_array( $debug = FALSE )
	{
		$array = parent::as_array( $debug );

		return count($array) || $debug ? $array : new MongoEmptyObj;
	}
}
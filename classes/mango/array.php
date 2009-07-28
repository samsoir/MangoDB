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

				if($update)
				{
					$changed = arr::merge($changed, array( '$set' => array( implode('.',$prefix) . '.' . $key => $value) ) );
				}
				else
				{
					$changed = arr::merge($changed, arr::path_set($prefix,$value) );
				}
			}
			elseif ($value instanceof Mango_Interface)
			{
				$prefix[] = $key;
				$changed = arr::merge($changed, $value->get_changed($update, $prefix));
			}
		}

		return $changed;
	}

	public function offsetSet($index,$newval)
	{
		$index = parent::offsetSet($index,$newval);

		$this->_changed[$index] = TRUE;
	}

	public function offsetUnset($index)
	{
		parent::offsetUnset($index);

		$this->_changed[$index] = FALSE;
	}

	public function as_array()
	{
		$array = parent::as_array();

		// TODO set MongoEmptyObj (need new driver)
		return count($array) ? $array : NULL; //new MongoEmptyObj;
	}
}
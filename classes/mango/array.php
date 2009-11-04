<?php

/*
 * Mango implementation of a Mongo Javascript Object.
 */

class Mango_Array extends Mango_ArrayObject {

	/*
	 * Return array of changes
	 */
	public function changed($update, array $prefix = array())
	{
		$changed = array();

		// Get a list of all relevant keys (current + unset keys)

		$keys = array();
		foreach($this as $key => $value)
		{
			$keys[] = $key;
		}
		// don't use array_unique(array_merge(array_keys($this->_changed),$keys)) - array_unique has issues with 0 values (eg array_unique( array('a',0,0,'a','c'));
		$keys = array_merge( $keys, array_diff( array_keys($this->_changed) ,$keys) );

		// Walk through array
		
		foreach($keys as $key)
		{
			$value = $this->offsetExists($key) 
				? $this->offsetGet($key) 
				: NULL;

			if(isset($this->_changed[$key]))
			{
				// value has been changed (set/unset)
				// todo add $unset when available (now unset vars are set to NULL

				$value = $value instanceof Mango_Interface
					? $value->as_array()
					: $value;

				$path = array_merge($prefix,array($key));

				$changed = $update
					? arr::merge($changed, array( '$set' => array( implode('.',$path) => $value) ) )
					: arr::merge($changed, arr::path_set($path,$value) );
			}
			elseif ($value instanceof Mango_Interface)
			{
				$changed = arr::merge($changed, $value->changed($update, array_merge($prefix,array($key))));
			}
		}

		return $changed;
	}

	/*
	 * Set a key to value
	 */
	public function offsetSet($index,$newval)
	{
		if(($index = parent::offsetSet($index,$newval)) !== FALSE)
		{
			// new value - remember change
			$this->_changed[$index] = TRUE;
		}
	}

	/*
	 * Unset a key
	 */
	public function offsetUnset($index)
	{
		parent::offsetUnset($index);

		$this->_changed[$index] = FALSE;
	}

	/*
	 * Updated as_array method
	 *
	 * Returns a MongoEmptyObj when array is empty (so that it is still saved as an object)
	 */
	public function as_array( $clean = TRUE )
	{
		$array = parent::as_array( $clean );

		return $clean && ! count($array) 
			? new MongoEmptyObj 
			: $array;
	}
}
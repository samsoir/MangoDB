<?php defined('SYSPATH') OR die('No direct access allowed.');

class Mango_Validate extends Kohana_Validate {

	/**
	 * Checks if a field is set.
	 *
	 * Modified version of Validate::not_empty, accepts values that
	 * Validate::not_empty doesn't (like FALSE, '0', 0 etc)
	 *
	 * @return  boolean
	 */
	public static function required($value)
	{
		if (is_object($value) AND $value instanceof ArrayObject)
		{
			// Get the array from the ArrayObject
			$value = $value->getArrayCopy();
		}

		return ! ($value === NULL || $value === array() || $value === '');
	}

	/**
	 * Removes a field, and all its rules/filters/callbacks & label from Validate object
	 */
	public function offsetUnset($field)
	{
		unset($this->_labels[$field], $this->_filters[$field], $this->_rules[$field], $this->_callbacks[$field]);

		if ( isset($this[$field]))
		{
			parent::offsetUnset($field);
		}
	}

} // End Mango_Validate
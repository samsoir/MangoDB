<?php defined('SYSPATH') OR die('No direct access allowed.');

class Mango_Validate extends Kohana_Validate {

	protected $_empty_rules = array('not_empty', 'matches', 'required');

	/**
	 * XSS Clean Filter
	 *
	 * Cleans strings from XSS data. Returns NULL if cleaned string is empty
	 *
	 * @param   string   The string to be cleaned
	 * @return  string   Clean String (or NULL)
	 */
	public static function xss_clean($value)
	{
		$value = Security::xss_clean($value);

		return $value === ''
			? NULL
			: $value;
	}

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
	 * Tests if a number has a minimum value.
	 *
	 * @return  boolean
	 */
	public static function min_value($number, $min)
	{
		return $number >= $min;
	}

	/**
	 * Tests if a number has a maximum value.
	 *
	 * @return  boolean
	 */
	public static function max_value($number, $max)
	{
		return $number <= $max;
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
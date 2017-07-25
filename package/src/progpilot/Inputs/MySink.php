<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Inputs;

class MySink extends MySpecify  {

	private $attack;
	private $parameters;
	private $has_parameters;

	public function __construct($name, $language, $attack) {

		parent::__construct($name, $language);

		$this->attack = $attack;
		$this->has_parameters = false;
		$this->parameters = [];
	}

	public function add_parameter($parameter)
	{
		$this->parameters[] = $parameter;
	}

	public function get_parameters()
	{
		return $this->parameters;
	}

	public function is_parameter($i)
	{
		foreach($this->parameters as $parameter)
		{
			if($parameter == $i)
				return true;
		}

		return false;
	}

	public function has_parameters()
	{
		return $this->has_parameters;
	}

	public function set_has_parameters($has_parameters)
	{
		$this->has_parameters = $has_parameters;
	}

	public function get_attack()
	{
		return $this->attack;
	}
}

?>

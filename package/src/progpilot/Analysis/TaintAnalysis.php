<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use progpilot\Objects\MyOp;
use progpilot\Objects\MyCode;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyDefinition;
use progpilot\Objects\MyInstance;
use progpilot\Objects\MyAssertion;

use progpilot\Dataflow\Definitions;

use progpilot\Code\Opcodes;

class TaintAnalysis {

	public static function funccall_validator($context, $data, $myclass, $myfunc_call, $arr_funccall, $instruction, $index)
	{     
		$nbparams = 0;
		$def_valid = null;
		$condition_respected = true;

		$class_name = false;
		if($myfunc_call->get_type() == MyOp::TYPE_INSTANCE)
			$class_name = $myclass->get_name();

		$myvalidator = $context->inputs->get_validator_byname($myfunc_call->get_name(), $class_name);
		if(!is_null($myvalidator))
		{
			while(true)
			{
				if(!$instruction->is_property_exist("argdef$nbparams"))
					break;

				$defarg = $instruction->get_property("argdef$nbparams"); 
				$exprarg = $instruction->get_property("argexpr$nbparams"); 

				$condition = $myvalidator->get_parameter_condition($nbparams+1);

				if($condition == "valid")
				{
					$thedefsargs = $exprarg->get_defs();
					if(count($thedefsargs) == 1)
						$def_valid = $thedefsargs[0];
				}

				else if($condition == "array_not_tainted")
				{
					if($defarg->get_type() == MyOp::TYPE_ARRAY && $defarg->is_tainted())
						$condition_respected = false;

					else if($defarg->get_type() == MyOp::TYPE_COPY_ARRAY)
					{
						$copyarrays = $defarg->get_copyarrays();
						foreach($copyarrays as $copyarray)
						{
							$arrvalue = $copyarray[0];
							$defarr = $copyarray[1];

							if($defarr->is_tainted())
								$condition_respected = false;
						}
					}
				}

				else if($condition == "not_tainted")
				{
					if($defarg->is_tainted())
						$condition_respected = false;
				}

				$nbparams ++;
			}
		}

		if(!is_null($def_valid))
		{
			if($condition_respected)
			{
				$codes = $context->get_mycode()->get_codes();
				$instruction_if = $codes[$index + 2];
				if($instruction_if->get_opcode() == Opcodes::COND_START_IF)
				{
					$myblock_if = $instruction_if->get_property("myblock_if");
					$myblock_else = $instruction_if->get_property("myblock_else");

					$type = "valid";
					$myassertion = new MyAssertion($def_valid, $type);

					if($instruction_if->is_property_exist("not_boolean"))
						$myblock_else->add_assertion($myassertion);
					else
						$myblock_if->add_assertion($myassertion);
				}
			}
		}
	}

	public static function funccall_sanitizer($context, $data, $myclass, $myfunc_call, $arr_funccall, $instruction, $index)
	{     
		$params_tainted = false;
		$exprs_tainted = [];
		$defs_tainted = [];
		$params_sanitized = false;
		$params_type_sanitized = [];
		$nbparams = 0;

		$codes = $context->get_mycode()->get_codes();

		while(true)
		{
			if(!$instruction->is_property_exist("argdef$nbparams"))
				break;

			$defarg = $instruction->get_property("argdef$nbparams"); 
			$exprarg = $instruction->get_property("argexpr$nbparams"); 

			if($defarg->is_tainted())
			{
				$params_tainted = true;
				$exprs_tainted[] = $exprarg;
				$defs_tainted[] = $defarg;
			}

			if($defarg->is_sanitized())
			{
				$params_sanitized = true;

				$tmps = $defarg->get_type_sanitized();

				foreach($tmps as $tmp)
				{
					if(!in_array($tmp, $params_type_sanitized))
						$params_type_sanitized[] = $tmp;
				}
			}

			$nbparams ++;
		}

		if($codes[$index + 2]->get_opcode() == Opcodes::END_ASSIGN)
		{
			$instruction_def = $codes[$index + 3];
			$mydef_return = $instruction_def->get_property("def");


			if($params_tainted)
			{
				for($j = 0; $j < count($defs_tainted); $j ++)  
					TaintAnalysis::set_tainted($data, $defs_tainted[$j], $mydef_return, $exprs_tainted[$j], false); 
			}

			$class_name = false;
			if($myfunc_call->get_type() == MyOp::TYPE_INSTANCE)
				$class_name = $myclass->get_name();

			$mysanitizer = $context->inputs->get_sanitizer_byname($myfunc_call->get_name(), $class_name);
			if(!is_null($mysanitizer))
			{
				$mydef_return->set_sanitized(true);
				$mydef_return->add_type_sanitized($mysanitizer->get_prevent());
			}
			if($params_sanitized)
			{
				$mydef_return->set_sanitized(true);
				foreach($params_type_sanitized as $tmp)
					$mydef_return->add_type_sanitized($tmp);
			}
		}
	}

	public static function funccall_source($context, $data, $myclass, $myfunc, $arr_funccall, $instruction)
	{ 
		$exprreturn = $instruction->get_property("expr");

		$class_name = false;
		if($myfunc->get_type() == MyOp::TYPE_INSTANCE)
			$class_name = $myclass->get_name();

		$mysource = $context->inputs->get_source_byname($myfunc->get_name(), true, $class_name);
		if(!is_null($mysource))
		{
			if($exprreturn->is_assign())
			{
				$defassign = $exprreturn->get_assign_def();

				$mydef = new MyDefinition($myfunc->getLine(), $myfunc->getColumn(), "return", false);
				$mydef->set_source_file($defassign->get_source_file());

				if($mysource->is_arr() && $arr_funccall == false)
				{
					$value_array = array($mysource->get_arr_value() => false);

					$defassign->add_copyarray($value_array, $mydef);
					//$defassign->set_copyarray(true);
					$defassign->set_type(MyOp::TYPE_COPY_ARRAY);

					$mydef->set_tainted(true);
					$mydef->set_taintedbyexpr($exprreturn);		
				}
				else if($mysource->is_arr())
				{
					$value_array = array($mysource->get_arr_value() => false);

					if($arr_funccall == $value_array)
					{
						$defassign->set_tainted(true);
						$defassign->set_taintedbyexpr($exprreturn);
						$mydef->set_tainted(true);
						$exprreturn->add_def($mydef);
					}
				}
				else if(!$mysource->is_arr())
				{
					$defassign->set_tainted(true);
					$defassign->set_taintedbyexpr($exprreturn);
				}
			}
		}
	}

	public static function funccall_after($data, $myfunc, $arr_funccall, $instruction)
	{ 
		$defsreturn = $myfunc->get_return_defs(); 
		$exprreturn = $instruction->get_property("expr");

		foreach($defsreturn as $defreturn)
		{        
			if(($arr_funccall != false && $defreturn->get_type() == MyOp::TYPE_ARRAY && $defreturn->get_arr_value() == $arr_funccall) || $arr_funccall == false)
			{
				$copydefreturn = $defreturn;

				$copydefreturn->add_expr($exprreturn);
				$exprreturn->add_def($copydefreturn);
				$exprs = $copydefreturn->get_exprs();

				foreach($exprs as $expr)
				{
					if($expr->is_assign())
					{
						$defassign = $expr->get_assign_def();
						TaintAnalysis::set_tainted($data, $copydefreturn, $defassign, $expr, false, null); 
					}
				}
			}
		}
	}

	public static function funccall_before($data, $myfunc, $instruction)
	{                          
		$nbparams = 0;
		$params = $myfunc->get_params();

		foreach($params as &$param)
		{
			if($instruction->is_property_exist("argdef$nbparams"))
			{
				$defarg = $instruction->get_property("argdef$nbparams"); 

				if($defarg->is_tainted())
				{
					$param->set_tainted(true);

					$exprs = $param->get_exprs();

					foreach($exprs as $expr)
					{
						if($expr->is_assign())
						{
							$defassign = $expr->get_assign_def();
							TaintAnalysis::set_tainted($data, $param, $defassign, $expr, false, null); 
						}
					}
				}

				$nbparams ++;
				unset($defarg);
			}
		}

		unset($params);
	}

	public static function set_tainted($data, $def, $defassign, $expr, $safe)
	{	     
		// assertions
		if(!$safe)
		{
			$visibility_final = true;
			if($defassign->get_type() == MyOp::TYPE_PROPERTY)
			{
				$copy_defassign = clone $defassign;
				$copy_defassign->set_assign_id(-1);
				$visibility_final = false;

				$instances = ResolveDefs::select_instances($data, $copy_defassign, true, true);

				foreach($instances as $instance)
				{
					if($instance->get_type() == MyOp::TYPE_INSTANCE)
					{
						$visibility_final = true;
						break;
					}
				}
			}

			if($def->is_tainted() && $visibility_final)
			{
				$defassign->set_tainted(true);
				$defassign->set_taintedbyexpr($expr);
			}

			if($def->is_sanitized() && $visibility_final)
			{
				$defassign->set_type_sanitized($def->get_type_sanitized());
				$defassign->set_sanitized(true);
			}
		}
	}
}

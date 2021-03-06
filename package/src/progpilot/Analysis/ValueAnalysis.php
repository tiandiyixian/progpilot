<?php

/*
 * This file is part of ProgPilot, a static analyzer for security
 *
 * @copyright 2017 Eric Therond. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */


namespace progpilot\Analysis;

use progpilot\Objects\MyFile;
use progpilot\Objects\MyOp;
use progpilot\Objects\ArrayStatic;
use progpilot\Objects\MyDefinition;
use progpilot\Dataflow\Definitions;
use progpilot\Objects\MyClass;
use progpilot\Objects\MyFunction;

use progpilot\Code\MyCode;
use progpilot\Code\Opcodes;

use progpilot\Utils;

class ValueAnalysis
{
    public static $exprs_cast;
    public static $exprs_knownvalues;

    public function __construct()
    {
    }

    public static function build_storage()
    {
        ValueAnalysis::$exprs_cast = new \SplObjectStorage;
        ValueAnalysis::$exprs_knownvalues = new \SplObjectStorage;
    }

    public static function update_storage_to_expr($expr)
    {
        if (!isset(ValueAnalysis::$exprs_cast[$expr]))
            ValueAnalysis::$exprs_cast[$expr] = [];

        if (!isset(ValueAnalysis::$exprs_knownvalues[$expr]))
            ValueAnalysis::$exprs_knownvalues[$expr] = [];
    }

    public static function compute_known_values($defassign, $expr)
    {
        if (isset(ValueAnalysis::$exprs_knownvalues[$expr]))
        {
            $known_values = ValueAnalysis::$exprs_knownvalues[$expr];
            $final_def_values = [];

            // storage[id_temp][] = def->get_last_known_values()
            // value = "id_temp1"."id_temp2"."id_temp3";
            foreach($known_values as $id_temp => $defs_known_values) // 1
            {
                // def
                // "id_temp1"
                $def_values = [];
                foreach ($defs_known_values as $def_known_values) // 2
                {
                    // def->get_last_known_values()
                    // "def_id_temp1"
                    foreach ($def_known_values as $def_known_value)
                        $def_values[] = $def_known_value;
                }

                if (count($final_def_values) == 0)
                    $final_def_values = $def_values;
                else
                {
                    $new_final_def_values = [];

                    foreach ($final_def_values as $final_def_value)
                    {
                        foreach ($def_values as $def_value)
                            $new_final_def_values[] = $final_def_value.$def_value;
                    }

                    $final_def_values = $new_final_def_values;
                }
            }

            foreach ($final_def_values as $final_def_value)
                $defassign->add_last_known_value($final_def_value);
        }
    }

    public static function compute_cast_values($defassign, $expr)
    {
        if (isset(ValueAnalysis::$exprs_cast[$expr]))
        {
            $nb_cast_safe = 0;
            $cast_values = ValueAnalysis::$exprs_cast[$expr];

            foreach ($cast_values as $cast_value)
            {
                if ($cast_value === MyDefinition::CAST_SAFE)
                    $nb_cast_safe ++;
            }

            if ($nb_cast_safe == count($cast_values))
                $defassign->set_cast(MyDefinition::CAST_SAFE);
            else
                $defassign->set_cast(MyDefinition::CAST_NOT_SAFE);
        }
    }

    public static function compute_embedded_chars($defassign, $expr)
    {
        $concat_embedded_chars = [];
        foreach ($expr->get_defs() as $def)
        {
            foreach($def->get_is_embeddedbychars() as $embedded_char => $boolean)
            $concat_embedded_chars[] = $embedded_char;
        }

        foreach ($concat_embedded_chars as $embedded_char)
        {
            $embedded_value = false;

            foreach ($expr->get_defs() as $def)
            {
                $boolean = $def->get_is_embeddedbychar($embedded_char);

                if ($boolean && $embedded_value)
                    $embedded_value = false;

                if ($boolean && !$embedded_value)
                    $embedded_value = true;

                if (!$boolean && $embedded_value)
                    $embedded_value = true;

                if (!$boolean && !$embedded_value)
                    $embedded_value = false;
            }

            $defassign->set_is_embeddedbychar($embedded_char, $embedded_value);
        }
    }

    public static function compute_sanitized_values($defassign, $expr)
    {
        $concat_types_sanitize = [];
        foreach ($expr->get_defs() as $def)
        {
            if ($def->is_sanitized())
            {
                foreach ($def->get_type_sanitized() as $type_sanitized)
                    $concat_types_sanitize["$type_sanitized"] = true;
            }
        }

        // foreach sanitize types
        foreach($concat_types_sanitize as $type_sanitized => $boolean_true)
        {
            $type_ok = true;
            foreach ($expr->get_defs() as $def)
            {
                // if we find a tainted value that is not sanitized the defassign is not sanitized
                if (!$def->is_type_sanitized($type_sanitized) && $def->is_tainted())
                    $type_ok = false;
            }

            if ($type_ok)
            {
                $defassign->set_sanitized(true);
                $defassign->add_type_sanitized($type_sanitized);
            }
        }
    }

    public static function copy_values($def, $defassign)
    {
        $defassign->set_is_embeddedbychars($def->get_is_embeddedbychars(), true);
    }
}

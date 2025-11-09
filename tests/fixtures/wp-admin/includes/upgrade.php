<?php

if (!function_exists('dbDelta')) {
    function dbDelta($sql) {
        if (!isset($GLOBALS['mock_dbdelta'])) {
            $GLOBALS['mock_dbdelta'] = [];
        }
        $GLOBALS['mock_dbdelta'][] = $sql;
        return true;
    }
}


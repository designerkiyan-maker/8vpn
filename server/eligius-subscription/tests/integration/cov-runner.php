<?php
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
register_shutdown_function(function () {
    $cov = xdebug_get_code_coverage();
    $name = getenv('P6_COV_OUT') ?: 'cov-unknown';
    file_put_contents('/srv/cov/' . $name . '.json', json_encode($cov));
});
require $argv[1];

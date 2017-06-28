<?php

require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'nntmux' . DIRECTORY_SEPARATOR . 'constants.php';
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'configuration' . DIRECTORY_SEPARATOR . 'database.php';

define('NN_APP_PATH', realpath(dirname(__DIR__)));

if (!defined('NN_ROOT')) {
	define('NN_ROOT', realpath(dirname(NN_APP_PATH)) . DS);
}

require_once NN_APP_PATH . DS . 'libraries' . DS . 'autoload.php';

?>

<?php

use wenbinye\tars\server\ServerApplication;

define('APP_PATH', dirname(__DIR__));

require APP_PATH . '/vendor/autoload.php';

ServerApplication::run();

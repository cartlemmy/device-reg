#!/usr/bin/php
<?php

require('inc/config.inc.php');
$androidData = require('inc/android-data.php');

require('inc/proc-scripts.php');

system('curl '.WWW.'whoiam.php');
#!/usr/bin/php
<?php

require('inc/config.inc.php');
$androidData = require('inc/android-data.php');

require('inc/proc.php');

system('curl '.WWW.'whoiam.php');

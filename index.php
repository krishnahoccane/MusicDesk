<?php

/*
 * LabelDesk — used only when the folder above "public" is the web root (for
 * example the application was extracted into public_html). The .htaccess
 * next to this file blocks every private file and sends requests here.
 * Recommended instead: point the domain's document root at "public".
 */
require __DIR__.'/public/index.php';

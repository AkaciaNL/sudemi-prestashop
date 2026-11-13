<?php

/**
 * Silence is golden. PrestaShop convention: every module directory carries this so a
 * misconfigured server cannot list its contents.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: ../');
exit;

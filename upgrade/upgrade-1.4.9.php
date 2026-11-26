<?php

/**
 * 1.4.9: product descriptions go to sudemi as HTML (paragraphs, lists and headings kept; sudemi
 * removes everything outside its safe subset itself). Asks for a full sync, so every product is
 * sent again with its description as HTML.
 *
 * @author    Akacia
 * @copyright Akacia
 * @license https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_9($module)
{
    SudemiSync::request();

    return true;
}

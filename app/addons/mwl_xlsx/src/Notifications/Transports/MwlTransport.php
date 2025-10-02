<?php
namespace Tygh\Addons\MwlXlsx\Notifications\Transports;

use Tygh\Notifications\Transports\ITransport;
use Tygh\Notifications\Transports\BaseMessageSchema;
use Tygh\Notifications\Receiver;
use Tygh\Notifications\Settings\Ruleset;

class MwlTransport implements ITransport
{
    public const ID = 'mwl';
    
    public static function getId()
    {
        return self::ID;
    }

    public function process(BaseMessageSchema $schema, array $receiver_search_conditions)
    {
        fn_mwl_xlsx_handle_vc_event($schema, $receiver_search_conditions);
        return true;
    }
}


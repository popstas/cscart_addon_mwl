<?php
namespace Tygh\Addons\MwlXlsx\Notifications\Transports;

use Tygh\Notifications\Transports\ITransport;
use Tygh\Notifications\Transports\BaseMessageSchema;
use Tygh\Notifications\Receiver;
use Tygh\Notifications\Settings\Ruleset;
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;

class MwlTransport implements ITransport
{
    public const ID = 'mwl';
    
    public static function getId()
    {
        return self::ID;
    }

    public function process(BaseMessageSchema $schema, array $receiver_search_conditions)
    {
        $event_repository = \fn_mwl_planfix_event_repository();
        if ($event_repository instanceof EventRepository) {
            $event_id = $event_repository->logVendorCommunicationEvent($schema, $receiver_search_conditions);
        } else {
            $event_id = null;
        }

        \fn_mwl_xlsx_handle_vc_event($schema, $receiver_search_conditions, $event_id);
        return true;
    }
}


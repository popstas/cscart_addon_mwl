<?php
$schema['mwl_wallet'] = [
    'processor'          => __('mwl_wallet.payment_processor_name'),
    'processor_script'   => 'mwl_wallet.php',
    'processor_template' => 'addons/mwl_xlsx/views/orders/components/payments/mwl_wallet.tpl',
    'callback'           => 'N',
    'type'               => 'P',
    'addon'              => 'mwl_xlsx',
];

return $schema;

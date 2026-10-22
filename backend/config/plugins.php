<?php

/*
 * Installed plugins. To add a gateway (e.g. a local card processor or SMS provider), drop a class in
 * app/Plugins/{Payment,Sms,Email} implementing the matching contract and list it here. It then appears
 * in GET /api/v1/admin/plugins and can be configured as a gateway from the admin panel.
 */
return [
    App\Plugins\Sms\LogSms::class,
    App\Plugins\Sms\HttpSms::class,
    App\Plugins\Sms\TwilioSms::class,
    App\Plugins\Email\LogEmail::class,
    App\Plugins\Email\SmtpEmail::class,
    App\Plugins\Payment\TestPayment::class,
];

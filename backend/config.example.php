<?php
return [
    // Путь вне каталога public_html. PHP должен иметь право записи только сюда.
    'data_dir' => dirname(__DIR__, 2) . '/cw-private',
    'mail_to' => 'internet.list@internet.ru',
    // Заполнить реальным адресом отправителя на домене после настройки почты.
    'mail_from' => '',
    'mail_enabled' => false,
    'timezone' => 'Europe/Moscow',
    'slots' => ['08:00–12:00', '12:00–16:00', '16:00–20:00'],
    // 0 — без автоматического ограничения. Интервал подтверждает продавец.
    'slot_capacity' => 0,
    'max_days' => 30,
    // Реальные секреты задавайте только в backend/config.php на хостинге.
    'sms_enabled' => false,
    'sms_api_id' => '', // SMS.RU; HTTPS и allow_url_fopen должны работать.
    'sms_daily_limit' => 30, // общий предел расходов; также 3 кода/номер/час.
    'order_contact_days' => 30, // после завершения и полного расчёта, без правового удержания.
    'new_order_alert_minutes' => 15,

];

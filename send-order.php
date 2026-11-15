<?php
http_response_code(410);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
echo json_encode(['error'=>'Обновите страницу сайта: используется новая защищённая форма заказа'],JSON_UNESCAPED_UNICODE);

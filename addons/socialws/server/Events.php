<?php

namespace addons\socialws\server;

use GatewayWorker\Lib\Gateway;

class Events
{
    public static function onMessage($client_id, $message)
    {
        $data = json_decode($message, true);
        if (!is_array($data) || empty($data['type'])) {
            return;
        }

        switch ($data['type']) {
            case 'ping':
                Gateway::sendToClient($client_id, json_encode(['type' => 'pong']));
                break;

            case 'subscribe':
                $joined = [];
                if (!empty($data['rooms']) && is_array($data['rooms'])) {
                    foreach ($data['rooms'] as $room) {
                        $key = self::roomKey($room);
                        if ($key !== '') {
                            Gateway::joinGroup($client_id, $key);
                            $joined[] = $key;
                        }
                    }
                }
                Gateway::sendToClient($client_id, json_encode(['type' => 'subscribed', 'rooms' => $joined]));
                break;

            case 'unsubscribe':
                if (!empty($data['rooms']) && is_array($data['rooms'])) {
                    foreach ($data['rooms'] as $room) {
                        $key = self::roomKey($room);
                        if ($key !== '') {
                            Gateway::leaveGroup($client_id, $key);
                        }
                    }
                }
                break;
        }
    }

    /**
     * Room key formula — MUST stay identical to Socialws::roomKey() in
     * addons/socialws/Socialws.php (duplicated deliberately; no shared code
     * layer between the resident process and the web request).
     */
    private static function roomKey($r)
    {
        if (!is_array($r) || empty($r['kind'])) {
            return '';
        }
        if ($r['kind'] === 'chat') {
            if (!isset($r['vod_id'])) {
                return '';
            }
            return 'chat_' . (int)$r['vod_id'];
        }
        if ($r['kind'] === 'danmaku') {
            if (!isset($r['vod_id']) || !isset($r['sid']) || !isset($r['nid'])) {
                return '';
            }
            return 'dm_' . (int)$r['vod_id'] . '_' . (int)$r['sid'] . '_' . (int)$r['nid'];
        }
        return '';
    }
}

<?php

namespace addons\socialws;

use think\Addons;

class Socialws extends Addons
{
    public function install()
    {
        return true;
    }

    public function uninstall()
    {
        return true;
    }

    /**
     * Hook: app_init — fires early in the request bootstrap, before
     * App::routeCheck() runs.
     * maccms disables ThinkPHP route checking (url_route_on=false) by default,
     * which prevents the fastadmin-addons Route::any('addons/:addon/...') from matching.
     * Only enable route checking when the request targets this addon, to avoid
     * side-effects on MaCMS's own URL resolution for all other requests.
     */
    public function appInit()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if (strpos($uri, 'addons/socialws') !== false) {
            \think\App::route(true);
        }
    }

    /**
     * Hook: social_broadcast — fired by Chatroom::saveData() / Danmaku::saveData()
     * after a new chat/danmaku row is inserted. Pushes it to the matching
     * GatewayWorker room. Never throws — a down/unreachable daemon must not
     * affect the HTTP request that already committed the DB write.
     */
    public function socialBroadcast($params)
    {
        if (empty($params['kind']) || empty($params['data'])) {
            return;
        }

        $room = $this->roomKey($params);
        if ($room === '') {
            return;
        }

        if (!class_exists('\GatewayWorker\Lib\Gateway')) {
            return;
        }

        try {
            $config = $this->getConfig();
            \GatewayWorker\Lib\Gateway::$registerAddress = $config['register_address'];
            \GatewayWorker\Lib\Gateway::$connectTimeout = 0.2;
            \GatewayWorker\Lib\Gateway::sendToGroup($room, json_encode([
                'type' => $params['kind'],
                'room' => $room,
                'data' => $params['data'],
            ]));
        } catch (\Throwable $e) {
            \think\Log::record('[socialws] broadcast fail: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Room key formula — MUST stay identical to Events::roomKey() in
     * addons/socialws/server/Events.php (duplicated deliberately; the two
     * runtimes don't share a code layer).
     */
    private function roomKey($params)
    {
        $kind = $params['kind'];
        if ($kind === 'chat') {
            if (!isset($params['vod_id'])) {
                return '';
            }
            return 'chat_' . (int)$params['vod_id'];
        }
        if ($kind === 'danmaku') {
            if (!isset($params['vod_id']) || !isset($params['sid']) || !isset($params['nid'])) {
                return '';
            }
            return 'dm_' . (int)$params['vod_id'] . '_' . (int)$params['sid'] . '_' . (int)$params['nid'];
        }
        return '';
    }
}

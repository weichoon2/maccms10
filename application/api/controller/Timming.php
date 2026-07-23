<?php
namespace app\api\controller;
use app\common\util\ExternalSyncRunner;
use think\Controller;
use app\common\util\AnalyticsAggregator;

class Timming extends Base
{
    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {
        $param = input('get.','','trim,urldecode');
        $name = $param['name'];
        if(empty($name)){
            //return $this->error('参数错误!');
        }

        $list = config('timming');
        foreach($list as $k=>$v){
            if(!empty($name) && $v['name'] !=$name){
                continue;
            }

            if(!empty($v['runtime'])) { $oldweek= date('w',$v['runtime']); $oldhours= date('H',$v['runtime']); }
            $curweek= date('w',time()) ;	$curhours= date("H",time());
            if(strlen($oldhours)==1 && intval($oldhours) <10){ $oldhours= '0'.$oldhours; }
            if(strlen($curhours)==1 && intval($curhours) <10){ $curhours= substr($curhours,1,1); }
            $last = (!empty($v['runtime']) ? date('Y-m-d H:i:s',$v['runtime']) : lang('api/never'));
            $status = $v['status'] == '1' ?  lang('open'): lang('close');

            //测试
            //$v['runtime']=0;

            // 分钟/秒级任务支持：任务可选配置 interval（秒）。设置后按「距上次执行的时间间隔」判断，
            // 突破默认的小时级粒度（如 push_broadcast 需每分钟派发一次队列）。未配置则维持原小时级逻辑。
            $interval = isset($v['interval']) ? intval($v['interval']) : 0;
            $weekOk = strpos($v['weeks'],$curweek)!==false;
            $shouldRun = false;
            if($v['status']=='1'){
                if($param['enforce']=='1'){
                    $shouldRun = true;
                }elseif(empty($v['runtime'])){
                    $shouldRun = true;
                }elseif($interval > 0){
                    $shouldRun = ($weekOk && (time() - intval($v['runtime'])) >= $interval);
                }else{
                    $shouldRun = (($oldweek."-".$oldhours) != ($curweek."-".$curhours) && $weekOk && strpos($v['hours'],$curhours)!==false);
                }
            }

            if( $shouldRun ) {

                mac_echo( lang('api/task_tip_exec',[$v['name'] ,$status,$last]));
                $list[$k]['runtime'] = time();

                $res = mac_arr2file( APP_PATH .'extra/timming.php', $list);
                if($res===false){
                    return $this->error(lang('write_err_config'));
                }
                $this->reset();

                // 兼容旧数据：早期资源站中心写入的任务使用 type/url 字段
                $file  = isset($v['file']) && $v['file'] !== '' ? $v['file'] : (isset($v['type']) ? $v['type'] : '');
                $param = isset($v['param']) ? $v['param'] : '';
                if ($param === '' && !empty($v['url'])) {
                    // 旧数据的 url 形如 .../collect/api?ac=cj&...，取 query string 作为 param
                    $query = parse_url($v['url'], PHP_URL_QUERY);
                    $param = $query !== null ? $query : '';
                }

                if (!is_string($file) || $file === '' || !method_exists($this, $file)) {
                    mac_echo(lang('api/task_tip_jump', [$v['name'], $status, $last]));
                    die;
                }

                $this->$file($param);
                die;

            }
            else{
                mac_echo(lang('api/task_tip_jump',[$v['name'] ,$status,$last]));
            }
        }
    }

    private function reset()
    {
        foreach($_REQUEST as $k=>$v){
            $_REQUEST[$k]='';
        }
    }

    protected function collect($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/collect');
        $request->api($output);
    }

    protected function make($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/make');
        $request->make($output);
    }

    protected function cj($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/cj');
        $request->col_all($output);
    }

    protected function cache($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/index');
        $request->clear();
    }

    protected function urlsend($param)
    {
        @parse_str($param,$output);
        $request = controller('admin/urlsend');
        $request->push($output);
    }

    protected function analytics($param)
    {
        @parse_str($param, $output);
        $mode = empty($output['mode']) ? 'hour' : trim($output['mode']);
        $date = empty($output['date']) ? '' : trim($output['date']);
        $res = $mode === 'day'
            ? AnalyticsAggregator::runDay($date)
            : AnalyticsAggregator::runHour($date);
        if (isset($res['msg'])) {
            mac_echo('[analytics] ' . $res['msg']);
        }
    }

    protected function extsync($param)
    {
        @parse_str($param, $output);
        $provider = isset($output['provider']) ? trim((string)$output['provider']) : '';
        $cfg = config('maccms');
        $extCfg = isset($cfg['ai_search']['external_sources']) && is_array($cfg['ai_search']['external_sources'])
            ? $cfg['ai_search']['external_sources']
            : [];
        $runner = new ExternalSyncRunner();
        $runner->runDueJobs($extCfg, $provider);
    }

    protected function notify($param)
    {
        @parse_str($param, $output);
        $days = isset($output['days']) ? intval($output['days']) : 3;
        if ($days < 1) {
            $days = 3;
        }
        $res = model('Notify')->sendVipExpirationReminders($days);
        if (isset($res['info']['sent'])) {
            mac_echo('[notify] vip expiration reminders sent: ' . intval($res['info']['sent']));
        }
    }

    protected function vodpublish($param)
    {
        @parse_str($param, $output);
        $limit = isset($output['limit']) ? intval($output['limit']) : 200;
        $res = \app\common\util\VodPublishService::publishDue($limit);
        mac_echo('[vodpublish] ' . $res['msg']);
        if (!empty($res['ids'])) {
            mac_echo('[vodpublish] ids: ' . implode(',', $res['ids']));
        }
    }

    /**
     * Web Push 全员广播队列派发：管理员广播只入队，逐条 HTTPS POST 在此异步分批发送。
     * 参数：batch=单批订阅数（默认100），max=单次运行订阅处理上限（默认500）。
     */
    protected function pushbroadcast($param)
    {
        @parse_str($param, $output);
        $batch = isset($output['batch']) ? intval($output['batch']) : 100;
        $max   = isset($output['max']) ? intval($output['max']) : 500;
        $res = \app\common\util\PushDispatcher::runQueue($batch, $max);
        mac_echo('[pushbroadcast] ' . (isset($res['msg']) ? $res['msg'] : '') . ' processed=' . (isset($res['processed']) ? intval($res['processed']) : 0));
    }
}


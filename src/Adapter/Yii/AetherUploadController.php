<?php

namespace AetherUpload\Adapter\Yii;

use yii\web\Controller;

/**
 * 承接四条业务路由的薄壳：每个动作一行，转交给框架无关的内核控制器。
 *
 * 为什么需要这层壳：Yii 的 Module::createController()/createControllerByID() 只接受
 * yii\base\Controller 的子类，而内核控制器是纯类（webman 用 [类名, 方法] 当路由处理器，
 * 不需要继承任何东西）。
 *
 * 必须是 yii\web\Controller，不能是 yii\base\Controller：路由变量到动作参数的绑定只在
 * web\Controller::bindActionParams() 里（base 的那个直接 return []）——用 base 的话
 * display/download 的 <uri> 永远绑不上，动作会因为缺参数炸掉。
 *
 * CSRF 关掉：四条路由是前端直传的无状态 JSON 接口，前端脚本不带 csrf token；而且这些接口
 * 本身不认任何登录态，token 挡不住「换个机器直接 POST」的攻击者，开着只会让默认配置下的上传全 400。
 *
 * 动作签名只保留路由变量（与内核控制器一致）；请求数据一律由内核经 Runtime::request() 取，
 * 这样「post() 不过滤」的契约在六个框架下是同一份实现。
 */
class AetherUploadController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionPreprocess()
    {
        return (new \AetherUpload\UploadController())->preprocess();
    }

    /** 路由 id 是 uploading（见配置 route_uploading），落到内核的 saveChunk */
    public function actionUploading()
    {
        return (new \AetherUpload\UploadController())->saveChunk();
    }

    /**
     * @param string $uri
     */
    public function actionDisplay($uri)
    {
        return (new \AetherUpload\ResourceController())->display($uri);
    }

    /**
     * @param string      $uri
     * @param string|null $newName
     */
    public function actionDownload($uri, $newName = null)
    {
        return (new \AetherUpload\ResourceController())->download($uri, $newName);
    }
}

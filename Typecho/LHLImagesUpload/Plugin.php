<?php
namespace TypechoPlugin\LHLImagesUpload;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Common;
use Widget\Options;
use Widget\Upload;
use CURLFile;

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * LHL's Images Typecho上传插件 (https://img.lhl.one)
 * 
 * @package LHLImagesUpload
 * @author LHL
 * @version 2.0.0
 */
class Plugin implements PluginInterface
{
    const UPLOAD_DIR  = '/usr/uploads';
    const PLUGIN_NAME = 'LHLImagesUpload';
    const FIXED_API_URL = 'https://img.lhl.one';

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Upload')->uploadHandle     = __CLASS__ . '::uploadHandle';
        \Typecho\Plugin::factory('Widget_Upload')->modifyHandle     = __CLASS__ . '::modifyHandle';
        \Typecho\Plugin::factory('Widget_Upload')->deleteHandle     = __CLASS__ . '::deleteHandle';
        \Typecho\Plugin::factory('Widget_Upload')->attachmentHandle = __CLASS__ . '::attachmentHandle';
        
        // 通过 admin/common.php 处理 AJAX 请求
        \Typecho\Plugin::factory('admin/common.php')->begin = __CLASS__ . '::handleAjaxRequest';
    }

    public static function deactivate()
    {
    }

    public static function config(Form $form)
    {
        $desc = new Text('desc', NULL, '', '插件介绍：', '<p>本插件专用于上传图片至 <a href="https://img.lhl.one" target="_blank">https://img.lhl.one</a><br>请填写你的 Token，然后点击"加载存储策略"按钮。</p>');
        $form->addInput($desc);

        $token = new Text('token', NULL, '', 'API Token：', '在 <a href="https://img.lhl.one" target="_blank">img.lhl.one</a> 后台「用户中心 → 我的令牌 (My Tokens)」中创建<br>格式示例：<code>1|xxxxxx</code>');
        $form->addInput($token);

        $storage_id = new Text('storage_id', NULL, '', 'Storage ID（必选）：', '点击下方按钮加载可用的存储策略，必须选择一个。');
        $form->addInput($storage_id);

        // 添加加载按钮
        $load_button = new Text('_load_storages', NULL, '', '加载存储策略：', '<button type="button" id="load-storages-btn" onclick="loadStorages()">加载存储策略</button><br><div id="storage-status"></div>');
        $form->addInput($load_button);

        echo '<script>
        window.onload = function(){
            document.getElementsByName("desc")[0].type = "hidden";
            document.getElementsByName("_load_storages")[0].type = "hidden";
        }
        
        function loadStorages() {
            var token = document.getElementsByName("token")[0].value;
            if (!token) {
                alert("请先填写 Token");
                return;
            }
            
            document.getElementById("load-storages-btn").disabled = true;
            document.getElementById("storage-status").innerHTML = "正在加载...";
            
            var xhr = new XMLHttpRequest();
            xhr.open("POST", window.location.href, true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                var select = document.createElement("select");
                                select.name = "storage_id";
                                select.id = "storage_id_select";
                                select.required = true; // 设置为必选
                                
                                // 添加存储策略选项（不提供默认选项）
                                response.storages.forEach(function(storage) {
                                    var option = document.createElement("option");
                                    option.value = storage.id;
                                    option.text = storage.name;
                                    select.appendChild(option);
                                });
                                
                                if (response.storages.length === 0) {
                                    var option = document.createElement("option");
                                    option.value = "";
                                    option.text = "无可用存储策略";
                                    select.appendChild(option);
                                }
                                
                                // 替换原有的 storage_id 输入框
                                var oldInput = document.getElementsByName("storage_id")[0];
                                oldInput.parentNode.replaceChild(select, oldInput);
                                
                                document.getElementById("storage-status").innerHTML = "存储策略加载成功！";
                            } else {
                                document.getElementById("storage-status").innerHTML = "加载失败: " + response.message;
                            }
                        } catch (e) {
                            console.log("Response text:", xhr.responseText);
                            document.getElementById("storage-status").innerHTML = "解析响应失败: " + e.message;
                        }
                    } else {
                        document.getElementById("storage-status").innerHTML = "请求失败: " + xhr.status;
                    }
                    document.getElementById("load-storages-btn").disabled = false;
                }
            };
            
            xhr.send("do=lhl_images_upload_get_storages&token=" + encodeURIComponent(token));
        }
        </script>';
    }

    public static function personalConfig(Form $form)
    {
    }

    // 处理 AJAX 请求
    public static function handleAjaxRequest()
    {
        // 检查是否为 POST 请求且包含特定参数
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
            $do = $_POST['do'];
            
            if ($do === 'lhl_images_upload_get_storages') {
                $token = $_POST['token'] ?? '';
                if (!$token) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Token 不能为空']);
                    exit;
                }

                // 调用主类的获取存储策略方法
                $storages = self::fetchStorages($token);
                if ($storages !== null) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'storages' => $storages]);
                } else {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => '获取存储策略失败，请检查 Token 是否正确']);
                }
                exit; // 重要：结束执行
            }
        }
    }

    public static function uploadHandle($file)
    {
        if (empty($file['name'])) return false;
        $ext = self::_getSafeName($file['name']);
        if (!Upload::checkFileType($ext) || Common::isAppEngine()) return false;
        return self::_isImage($ext) ? self::_uploadImg($file, $ext) : self::_uploadOtherFile($file, $ext);
    }

    public static function deleteHandle(array $content): bool
    {
        $ext = $content['attachment']->type ?? '';
        if (self::_isImage($ext)) {
            return self::_deleteImg($content);
        }
        return unlink($content['attachment']->path ?? '');
    }

    public static function modifyHandle($content, $file)
    {
        if (empty($file['name'])) return false;
        $ext = self::_getSafeName($file['name']);
        if (($content['attachment']->type ?? '') != $ext || Common::isAppEngine()) return false;
        if (!self::_getUploadFile($file)) return false;
        if (self::_isImage($ext)) {
            self::_deleteImg($content);
            return self::_uploadImg($file, $ext);
        }
        return self::_uploadOtherFile($file, $ext);
    }

    public static function attachmentHandle(array $content): string
    {
        $ext = pathinfo($content['attachment']->name ?? '', PATHINFO_EXTENSION);
        if (self::_isImage($ext)) {
            return $content['attachment']->path ?? '';
        }
        $arr = @unserialize($content['text']);
        if (is_array($arr) && isset($arr['path'])) {
            $ret = explode(self::UPLOAD_DIR, $arr['path']);
            return Common::url(self::UPLOAD_DIR . @$ret[1], Options::alloc()->siteUrl);
        }
        return '';
    }

    // --- 工具方法 ---

    private static function _getUploadDir($ext = ''): string
    {
        if (self::_isImage($ext)) {
            $url = parse_url(Options::alloc()->siteUrl, PHP_URL_HOST);
            $DIR = str_replace('.', '_', $url);
            return '/' . $DIR . self::UPLOAD_DIR;
        } elseif (defined('__TYPECHO_UPLOAD_DIR__')) {
            return __TYPECHO_UPLOAD_DIR__;
        } else {
            return Common::url(self::UPLOAD_DIR, __TYPECHO_ROOT_DIR__);
        }
    }

    private static function _getUploadFile($file): string
    {
        return $file['tmp_name'] ?? ($file['bytes'] ?? ($file['bits'] ?? ''));
    }

    private static function _getSafeName(&$name): string
    {
        $name = str_replace(['"', '<', '>'], '', $name);
        $name = str_replace('\\', '/', $name);
        $name = false === strpos($name, '/') ? ('a' . $name) : str_replace('/', '/a', $name);
        $info = pathinfo($name);
        $name = substr($info['basename'], 1);
        return isset($info['extension']) ? strtolower($info['extension']) : '';
    }

    private static function _makeUploadDir($path): bool
    {
        if (is_dir($path)) return true;
        return @mkdir($path, 0755, true);
    }

    private static function _isImage($ext): bool
    {
        $img_ext_arr = ['gif', 'jpg', 'jpeg', 'png', 'bmp', 'ico', 'webp', 'svg'];
        return in_array(strtolower($ext), $img_ext_arr, true);
    }

    private static function _uploadOtherFile($file, $ext)
    {
        $dir = self::_getUploadDir($ext) . '/' . date('Y') . '/' . date('m');
        if (!self::_makeUploadDir($dir)) return false;
        $path = sprintf('%s/%u.%s', $dir, crc32(uniqid()), $ext);
        if (!isset($file['tmp_name']) || !@move_uploaded_file($file['tmp_name'], $path)) return false;
        return [
            'name' => $file['name'],
            'path' => $path,
            'size' => $file['size'] ?? filesize($path),
            'type' => $ext,
            'mime' => @Common::mimeContentType($path)
        ];
    }

    private static function _uploadImg($file, $ext)
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = self::FIXED_API_URL . '/api/v2/upload';
        $token   = 'Bearer ' . $options->token;
        $storageId = trim($options->storage_id);

        // 检查是否设置了 storage_id
        if (empty($storageId)) {
            file_put_contents(
                __DIR__ . '/error.log',
                date('Y-m-d H:i:s') . " Storage ID 不能为空，无法上传图片\n",
                FILE_APPEND
            );
            return false;
        }

        $tmp = self::_getUploadFile($file);
        if (empty($tmp)) return false;

        $tempFile = tempnam(sys_get_temp_dir(), 'lhl_images_') . '.' . $ext;
        if (!move_uploaded_file($tmp, $tempFile)) return false;

        $params = ['file' => new CURLFile($tempFile)];
        $params['storage_id'] = (int)$storageId;

        $res = self::_curlPost($api, $params, $token);
        unlink($tempFile);

        if (!$res) return false;

        $json = json_decode($res, true);
        if (!isset($json['status']) || $json['status'] !== 'success') {
            file_put_contents(
                __DIR__ . '/error.log',
                date('Y-m-d H:i:s') . " Upload Error:\n" . json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n",
                FILE_APPEND
            );
            return false;
        }

        $data = $json['data'];
        return [
            'img_key' => (string)$data['id'],
            'img_id'  => $data['md5'],
            'name'    => $data['filename'],
            'path'    => $data['public_url'],
            'size'    => $data['size'] ?? 0,
            'type'    => $data['extension'],
            'mime'    => $data['mimetype'],
        ];
    }

    private static function _deleteImg(array $content): bool
    {
        $options = Options::alloc()->plugin(self::PLUGIN_NAME);
        $api     = self::FIXED_API_URL . '/api/v2/images';
        $token   = 'Bearer ' . $options->token;

        $id = $content['attachment']->img_key ?? '';
        if (!is_numeric($id)) return false;

        $url = $api . '/' . (int)$id;
        $res = self::_curlDelete($url, $token);

        $json = json_decode($res, true);
        return isset($json['status']) && $json['status'] === 'success';
    }

    // --- 获取存储策略 ---
    private static function fetchStorages($token)
    {
        $api = self::FIXED_API_URL . '/api/v2/group';
        $headers = [
            "Accept: application/json",
            "Authorization: Bearer " . $token,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LHLImagesUpload/2.0.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$res) {
            error_log("LHLImagesUpload: cURL error - no response");
            return null;
        }

        if ($httpCode !== 200) {
            error_log("LHLImagesUpload: API returned HTTP " . $httpCode);
            return null;
        }

        $json = json_decode($res, true);
        if (!isset($json['status']) || $json['status'] !== 'success') {
            error_log("LHLImagesUpload: API returned status: " . ($json['status'] ?? 'unknown'));
            return null;
        }

        return $json['data']['storages'] ?? [];
    }

    private static function _curlPost($url, $post, $token)
    {
        $headers = [
            "Accept: application/json",
            "Authorization: Bearer " . $token,
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'LHLImagesUpload/2.0.0');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    private static function _curlDelete($url, $token)
    {
        $headers = [
            "Accept: application/json",
            "Authorization: Bearer " . $token,
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_USERAGENT, 'LHLImagesUpload/2.0.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}
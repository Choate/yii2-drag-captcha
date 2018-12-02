<?php

namespace choate\yii2\dragcaptcha;


use yii\base\Action;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\Url;
use yii\web\Response;

class CaptchaAction extends Action
{

    const REFRESH_GET_VAR = 'refresh';

    public $testLimit = 3;

    public $width = 240;

    public $height = 150;

    public $backgroundImageMax = 6;

    public $backgroundImagePath = '@choate/yii2/dragcaptcha/assets/images/background';

    public $placeholderImageFile = '@choate/yii2/dragcaptcha/assets/images/placeholder.png';

    public $placeholderModuleImageFile = '@choate/yii2/dragcaptcha/assets/images/placeholder_module.png';

    public $placeholderWidth = 50;

    public $placeholderHeight = 50;

    public $faultTolerance = 3;

    public $imageLibrary;

    public $fixedVerifyCode;


    /**
     * @throws InvalidConfigException
     */
    public function init()
    {
        Yii::setAlias('@choate/yii2/dragcaptcha', __DIR__);
        $this->backgroundImagePath = Yii::getAlias($this->backgroundImagePath);
        $this->placeholderImageFile = Yii::getAlias($this->placeholderImageFile);
        $this->placeholderModuleImageFile = Yii::getAlias($this->placeholderModuleImageFile);
        if (!is_dir($this->backgroundImagePath)) {
            throw new InvalidConfigException("The background image dir does not exist: {$this->backgroundImagePath}");
        }
        if (!is_file($this->placeholderImageFile)) {
            throw new InvalidConfigException("The placeholder image does not exist: {$this->placeholderImageFile}");
        }
        if (!is_file($this->placeholderModuleImageFile)) {
            throw new InvalidConfigException("The module image does not exist: {$this->placeholderModuleImageFile}");
        }
    }


    /**
     * @return mixed
     * @throws InvalidConfigException
     */
    public function run()
    {
        if (Yii::$app->request->getQueryParam(self::REFRESH_GET_VAR) !== null) {
            // AJAX request for regenerating code
            $this->getVerifyCode(true);
            Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'url' => Url::to([$this->id, 'v' => uniqid('', true)]),
            ];
        }

        $this->setHttpHeaders();
        Yii::$app->response->format = Response::FORMAT_RAW;
        list($xAxis, $yAxis) = explode(',', $this->getVerifyCode(true));

        return $this->renderImage($xAxis, $yAxis);
    }

    /**
     * Gets the verification code.
     * @param bool $regenerate whether the verification code should be regenerated.
     * @return string the verification code.
     */
    public function getVerifyCode($regenerate = false)
    {
        if ($this->fixedVerifyCode !== null) {
            return $this->fixedVerifyCode;
        }

        $session = Yii::$app->getSession();
        $session->open();
        $name = $this->getSessionKey();
        if ($session[$name] === null || $regenerate) {
            $session[$name] = $this->generateVerifyCode();
            $session[$name . 'count'] = 1;
        }

        return $session[$name];
    }

    /**
     * Validates the input to see if it matches the generated code.
     * @param string $input user input
     * @return bool whether the input is valid
     */
    public function validate($input)
    {
        list($xAxis, $yAxis) = explode(',', $this->getVerifyCode());
        $valid = abs($xAxis - $input) <= $this->faultTolerance;
        $session = Yii::$app->getSession();
        $session->open();
        $name = $this->getSessionKey() . 'count';
        $session[$name] += 1;
        if ($valid || $session[$name] > $this->testLimit && $this->testLimit > 0) {
            $this->getVerifyCode(true);
        }

        return $valid;
    }

    /**
     * Generates a new verification code.
     * @return string the generated verification code
     */
    protected function generateVerifyCode()
    {
        $xAxis = mt_rand(50, $this->width - $this->placeholderWidth - 1);
        $yAxis = mt_rand(0, $this->height - $this->placeholderHeight - 1);

        return $xAxis . ',' . $yAxis;
    }

    /**
     * Returns the session variable name used to store verification code.
     * @return string the session variable name
     */
    protected function getSessionKey()
    {
        return '__captcha/' . $this->getUniqueId();
    }


    /**
     * @param int $xAxis
     * @param int $yAxis
     * @return string
     * @throws InvalidConfigException
     */
    protected function renderImage($xAxis, $yAxis)
    {

        $backgroundImageIndex = mt_rand(1, $this->backgroundImageMax);
        $backgroundImageFile = $this->backgroundImagePath . DIRECTORY_SEPARATOR . $backgroundImageIndex . '.png';
        if (!is_file($backgroundImageFile)) {
            throw new InvalidConfigException("The background image file does not exist: {$backgroundImageFile}");
        }

        if (isset($this->imageLibrary)) {
            $imageLibrary = $this->imageLibrary;
        } else {
            $imageLibrary = $this->checkRequirements();
        }

        if ($imageLibrary === 'gd') {
            return $this->renderImageByGD($backgroundImageFile, $xAxis, $yAxis);
        }

        throw new InvalidConfigException("Defined library '{$imageLibrary}' is not supported");
    }

    protected function renderImageByGD($backgroundImageFile, $xAxis, $yAxis)
    {

        $backgroundImage = imagecreatefrompng($backgroundImageFile);

        // 创建模块图片
        $placeholderModuleImage = imagecreatefrompng($this->placeholderModuleImageFile);
        $placeholderModuleCanvasImage = imagecreatetruecolor($this->placeholderWidth, $this->height);
        // 从原图中拷贝出模块图
        imagecopy($placeholderModuleCanvasImage, $backgroundImage, 0, $yAxis, $xAxis, $yAxis, $this->placeholderWidth, $this->placeholderHeight);
        imagecopy($placeholderModuleCanvasImage, $placeholderModuleImage, 0, $yAxis, 0, 0, $this->placeholderWidth, $this->placeholderHeight);
        imagecolortransparent($placeholderModuleCanvasImage, 0);

        // 创建拼图框架
        $placeholderImage = imagecreatefrompng($this->placeholderImageFile);
        $backgroundCanvasImage = imagecreatetruecolor($this->width, $this->height);
        // 把占位图放到背景图中的某一处
        imagecopy($backgroundCanvasImage, $backgroundImage, 0, 0, 0, 0, $this->width, $this->height);
        imagecolortransparent($placeholderImage, 0);
        imagecopy($backgroundCanvasImage, $placeholderImage, $xAxis, $yAxis, 0, 0, $this->placeholderWidth, $this->placeholderHeight);

        // 合并图片
        $canvasImage = imagecreatetruecolor($this->width, $this->height * 3);
        imagecopy($canvasImage, $backgroundCanvasImage, 0, 0, 0, 0, $this->width, $this->height);
        imagecopy($canvasImage, $placeholderModuleCanvasImage, 0, $this->height, 0, 0, $this->width, $this->height);
        imagecopy($canvasImage, $backgroundImage, 0, $this->height * 2, 0, 0, $this->width, $this->height);
        imagecolortransparent($canvasImage, 0);

        // 输出图片
        ob_start();
        imagepng($canvasImage);

        // 销毁图片
        imagedestroy($backgroundImage);
        imagedestroy($backgroundCanvasImage);
        imagedestroy($placeholderImage);
        imagedestroy($placeholderModuleImage);
        imagedestroy($placeholderModuleCanvasImage);

        return ob_get_clean();
    }

    /**
     * @return string
     * @throws InvalidConfigException
     */
    protected function checkRequirements()
    {
        if (extension_loaded('gd')) {
            $gdInfo = gd_info();
            if (!empty($gdInfo['FreeType Support'])) {
                return 'gd';
            }
        }
        throw new InvalidConfigException('Either GD PHP extension with FreeType support or ImageMagick PHP extension with PNG support is required.');
    }

    /**
     * Sets the HTTP headers needed by image response.
     */
    protected function setHttpHeaders()
    {
        Yii::$app->getResponse()->getHeaders()
            ->set('Pragma', 'public')
            ->set('Expires', '0')
            ->set('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
            ->set('Content-Transfer-Encoding', 'binary')
            ->set('Content-type', 'image/png');
    }

}
<?php

/**
 * @author Paweł Bizley Brzozowski
 * @version 1.0
 * @license Apache 2.0
 * https://github.com/bizley-code/yii2-content-tools
 * http://www.yiiframework.com/extension/yii2-content-tools
 * 
 * ContentTools was created by Anthony Blackshaw
 * http://getcontenttools.com/
 * https://github.com/GetmeUK/ContentTools
 */

namespace bizley\contenttools;

use bizley\contenttools\assets\ContentToolsAsset;
use bizley\contenttools\assets\ContentToolsImagesAsset;
use bizley\contenttools\assets\ContentToolsTranslationsAsset;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Widget;
use yii\helpers\Html;
use yii\web\View;

/**
 * ContentTools editor implementation for Yii 2.
 * 
 * Wrap any part of the content with 
 * <?php bizley\contenttools\ContentTools::begin(); ?> and 
 * <?php bizley\contenttools\ContentTools::end(); ?>.
 * 
 * ~~~
 * <?php bizley\contenttools\ContentTools::begin(); ?>
 * This is the part of view that is editable.
 * <p>There are paragraphs</p>
 * <div>and more...</div>
 * <?php bizley\contenttools\ContentTools::end(); ?>
 * ~~~
 * 
 * You can use the widget multiple times on one page.
 * 
 * ContentTools saves content and uploaded images asynchronously and it requires 
 * some preparation on the backend side.
 * You have to create few controllers' actions:
 * - "upload new image" action,
 * - "rotate uploaded image" action,
 * - "insert & crop uploaded image" action,
 * - "save content" action.
 * 
 * Three first actions are already prepared if you don't want any special 
 * operations. You can find them in 'actions' folder.
 * - UploadAction - takes care of validating the uploaded images using 
 * bizley\contenttools\models\ImageForm (jpg, png and gif images are allowed, 
 * maximum width and height is 1000px and maximum size is 2MB), images are 
 * saved in 'content-tools-uploads' folder accessible from web.
 * - RotateAction - takes care of rotating the uploaded image using Imagine 
 * library (through yii2-imagine required in the composer.json).
 * - InsertAction - takes care of inserting image into the content with optional 
 * cropping using Imagine library.
 * 
 * The default option for the image urls is:
 * 
 * ~~~
 * 'imagesEngine' => [
 *      'upload' => '/site/content-tools-image-upload',
 *      'rotate' => '/site/content-tools-image-rotate',
 *      'insert' => '/site/content-tools-image-insert',
 * ],
 * ~~~
 * So if you don't want to change the 'imagesEngine' parameter add in your 
 * SiteController:
 * 
 * ~~~
 * public function actions()
 * {
 *      return [
 *          'content-tools-image-upload' => bizley\contenttools\actions\UploadAction::className(),
 *          'content-tools-image-insert' => bizley\contenttools\actions\InsertAction::className(),
 *          'content-tools-image-rotate' => bizley\contenttools\actions\RotateAction::className(),
 *      ];
 * }
 * ~~~
 * 
 * The last "save content" action is not prepared so go ahead and take care of 
 * it. Default configuration for this is:
 * 
 * ~~~
 * 'saveEngine' => [
 *      'save' => '/site/save-content',
 * ],
 * ~~~
 */
class ContentTools extends Widget
{

    /**
     * @var string Tag that will be used to wrap the editable content.
     */
    public $tag = 'div';
    
    /**
     * @var string Name of the data-* attribute that will store the identifier of editable region.
     */
    public $dataName = 'name';
    
    /**
     * @var string Name of the data-* attribute that will mark the region as editable.
     */
    public $dataInit = 'editable';
    
    /**
     * @var array Array of html options that will be applied to editable region's tag.
     */
    public $options = [];
    
    /**
     * @var array|boolean Array of the urls of the image actions OR false to 
     * switch off the default image engine (you will have to prepare js for handling images on your own).
     */
    public $imagesEngine = [
        'upload' => '/site/content-tools-image-upload',
        'rotate' => '/site/content-tools-image-rotate',
        'insert' => '/site/content-tools-image-insert',
    ];
    
    /**
     * @var array|boolean Array with the url of the content saving action OR 
     * false to switch off the default saving engine (you will have to prepare js for handling content saving on your own).
     */
    public $saveEngine = [
        'save' => '/site/save-content',
    ];
    
    /**
     * @var array Array of styles that can be applied to the edited content.
     * Every style should be added in array like:
     * ~~~
     * 'Name of the style' => [
     *      'class' => 'Name of the CSS class',
     *      'tags'  => [Array of the html tags this can be applied to] or 'comma-separated list of the html tags this can be applied to'
     * ],
     * ~~~
     * Example:
     * ~~~
     * 'Bootstrap Green' => [
     *      'class' => 'text-success',
     *      'tags'  => ['p', 'h2', 'h1']
     * ],
     * ~~~
     * 'tags' key is optional and if omitted style can be applied to every element.
     */
    public $styles = [];
    
    /**
     * @var boolean|string Boolean flag or language code of the widget translation. 
     * You can see the list of prepared translations in 'ContentTools/translations' folder.
     * false means that widget will not be translated (default language is English).
     * true means that widget will be translated using the application language.
     * If this parameter is a string widget tries to load the translation file 
     * with the given name. If it cannot be found and string is longer that 2 
     * characters widget tries again this time with parameter shortened to 2 
     * characters. If again it cannot be found language sets back to default.
     */
    public $language = false;
    
    /**
     * @var boolean Boolean flag whether the configuration should be global.
     * Global configuration means that every succeeding widget ignores 'tag', 
     * 'dataName', 'dataInit', 'imagesEngine', 'saveEngine' and 'language' 
     * parameters and sets them to be the same as in the first one. Also 'styles' 
     * are added only if they've got unique names.
     */
    public $globalConfig = true;
    
    /**
     * @inheritdoc
     */
    public static $autoIdPrefix = 'contentTools';
    
    /**
     * @var boolean Boolean flag whether global parameters are set or not.
     */
    private $_global;
    
    /**
     * @var array List of previously added styles.
     */
    private $_addedStyles = [];
    
    const GLOBAL_PARAMS_KEY = 'content-tools-global-configuration';
    
    /**
     * Checks if global configuration array is set.
     * If so it sets properties to global values.
     * If not and the globalConfig is set to true the current properties are 
     * saved in global configuration.
     */
    public function globalConfig()
    {
        $this->_global = isset($this->getView()->params[self::GLOBAL_PARAMS_KEY]);
        if ($this->_global) {
            $globalConfig = $this->getView()->params[self::GLOBAL_PARAMS_KEY];
            $this->dataName     = $globalConfig['dataName'];
            $this->dataInit     = $globalConfig['dataInit'];
            $this->imagesEngine = $globalConfig['imagesEngine'];
            $this->saveEngine   = $globalConfig['saveEngine'];
            $this->language     = $globalConfig['language'];
            $this->_addedStyles = $globalConfig['styles'];
        }
        else {
            if ($this->globalConfig) {
                $this->getView()->params[self::GLOBAL_PARAMS_KEY] = [
                    'dataName'     => $this->dataName,
                    'dataInit'     => $this->dataInit,
                    'imagesEngine' => $this->imagesEngine,
                    'saveEngine'   => $this->saveEngine,
                    'language'     => $this->language,
                    'styles'       => [],
                ];
            }
        }
    }
    
    /**
     * Returns the default js part for saving the content if saveEngine is not set to false.
     * saveEngine should be false or the array with 'save' key.
     * @return string
     * @throws InvalidConfigException
     */
    public function initSaveEngine()
    {
        if ($this->saveEngine !== false) {
            if (empty($this->saveEngine['save'])) {
                throw new InvalidConfigException('Invalid options for the saveEngine configuration!');
            }
            
            return ";editor.bind('save',function(regions){" .
                "var name,payload,xhr;" .
                "this.busy(true);" .
                "payload=new FormData();" .
                "for(name in regions) {" .
                    "if(regions.hasOwnProperty(name)){" .
                        "payload.append(name,regions[name]);" .
                    "}" .
                "}" .
                "payload.append('" . Yii::$app->request->csrfParam . "','" . Yii::$app->request->csrfToken . "');" .
                "var onStateChange=function(event){" .
                    "if(parseInt(event.target.readyState)===4){" .
                        "editor.busy(false);" .
                        "if(parseInt(event.target.status)===200){" .
                            "response=JSON.parse(event.target.responseText);" .
                            "if(response.errors){" .
                                "for(var k in response.errors)console.log(response.errors[k]);" .
                                "new ContentTools.FlashUI('no');" .
                            "}" .
                            "else new ContentTools.FlashUI('ok');" .
                        "} else {" .
                            "new ContentTools.FlashUI('no');" .
                        "}" .
                    "}" .
                "};" .
                "xhr = new XMLHttpRequest();" .
                "xhr.addEventListener('readystatechange',onStateChange);" .
                "xhr.open('POST','" . $this->saveEngine['save'] . "');" .
                "xhr.send(payload);" .
            "});";
        }
        return '';
    }

    /**
     * Registers ContentToolsAsset.
     * Initiates ContentTools editor engine.
     */
    public function initEditor()
    {
        ContentToolsAsset::register($this->getView());
        $this->getView()->registerJs(";window.addEventListener('load',function(){" .
            "var editor;" .
            "editor=ContentTools.EditorApp.get();" .
            "editor.init('*[" . static::dataAttribute($this->dataInit) . "]','" . static::dataAttribute($this->dataName) . "');" .
            $this->initSaveEngine() .
        "});", View::POS_END);
    }
    
    /**
     * Adds translation for the editor if language is not set to false.
     */
    public function addTranslation()
    {
        if ($this->language === true) {
            $this->language = Yii::$app->language;
        }
        if ($this->language !== false) {
            
            $lang      = strtolower(basename($this->language));
            $shortlang = strlen($lang) > 2 ? substr($lang, 0, 2) : null;
            $assets    = ContentToolsTranslationsAsset::register($this->getView());
            if (!empty($shortlang)) {
                $this->getView()->registerJs(";var loadTranslation=function(lang,next){" .
                        "var xhr=new XMLHttpRequest();" .
                        "xhr.open('GET','" . ($assets ? $assets->baseUrl : '') . "/'+lang+'.json',true);" .
                        "xhr.addEventListener('readystatechange',function(event){" .
                            "var translations;" .
                            "if(parseInt(event.target.readyState)===4){" .
                                "if(parseInt(event.target.status)===200){" .
                                    "translations=JSON.parse(event.target.responseText);" .
                                    "ContentEdit.addTranslations(lang,translations);" .
                                    "ContentEdit.LANGUAGE=lang;" .
                                "}else{if(next===true)loadTranslation('$shortlang',false);}" .
                            "}" .
                        "});" .
                        "xhr.send(null);" .
                    "};loadTranslation('$lang',true);", View::POS_END);
            }
            else {
                $this->getView()->registerJs(";var xhr=new XMLHttpRequest();" .
                    "xhr.open('GET','" . ($assets ? $assets->baseUrl : '') . "/$lang.json',true);" .
                    "xhr.addEventListener('readystatechange',function(event){" .
                        "var translations;" .
                        "if(parseInt(event.target.readyState)===4&&parseInt(event.target.status)===200){" .
                            "translations=JSON.parse(event.target.responseText);" .
                            "ContentEdit.addTranslations('$lang',translations);" .
                            "ContentEdit.LANGUAGE='$lang';" .
                        "}" .
                    "});" .
                    "xhr.send(null);", View::POS_END);
            }
        }
    }
    
    /**
     * Initiates images engine if imagesEngine is not set to false.
     * imagesEngine should be false or the array with 'upload', 'rotate' and 'insert' keys.
     * Registers ContentToolsImagesAsset.
     * @throws InvalidConfigException
     */
    public function initImagesEngine()
    {
        if ($this->imagesEngine !== false) {
            if (empty($this->imagesEngine['upload']) || empty($this->imagesEngine['rotate']) || empty($this->imagesEngine['insert'])) {
                throw new InvalidConfigException('Invalid options for the imagesEngine configuration!');
            }
            ContentToolsImagesAsset::register($this->getView());
            $this->registerCsrfToken();
            $this->getView()->registerJs(";var _imagesUrl=['" . $this->imagesEngine['upload'] . "','" . $this->imagesEngine['rotate'] . "','" . $this->imagesEngine['insert'] . "'];", View::POS_BEGIN);
        }
        else {
            $this->registerCsrfToken(true);
            $this->getView()->registerJs(";var _imagesUrl=[];", View::POS_BEGIN);
        }
    }
    
    /**
     * Registers CSRF parameters.
     * @param boolean $empty Whether to add parameters or just set the empty array
     */
    public function registerCsrfToken($empty = false)
    {
        $this->getView()->registerJs(";var _csrf=[" . ($empty ? '' : "'" . Yii::$app->request->csrfParam . "','" . Yii::$app->request->csrfToken . "'") . "];", View::POS_BEGIN);
    }
    
    /**
     * Adds the styles for the editor.
     * If globalConfig is set to true it will only add new styles (with new name).
     * @throws InvalidConfigException
     */
    public function addStyles()
    {
        if (!empty($this->styles)) {
            $newStyles = [];
            foreach ($this->styles as $name => $style) {
                if (empty($style['class']) || !is_string($style['class'])) {
                    throw new InvalidConfigException('Invalid options for styles configuration!');
                }
                if (!empty($style['tags']) && !is_string($style['tags']) && !is_array($style['tags'])) {
                    throw new InvalidConfigException('Invalid options for styles configuration!');
                }
                if (empty($this->_addedStyles) || !in_array(Html::encode($name), $this->_addedStyles)) {
                    $tmp = "new ContentTools.Style('" . Html::encode($name) . "','" . Html::encode($style['class']) . "'";
                    if (!empty($style['tags'])) {
                        $addTags = [];
                        if (is_string($style['tags'])) {
                            $tags = explode(',', $style['tags']);
                            foreach ($tags as $tag) {
                                if ($tag !== '') {
                                    $addTags[] = str_replace("'", '', trim($tag));
                                }
                            }
                        }
                        else {
                            foreach ($style['tags'] as $tag) {
                                if (!is_string($tag)) {
                                    throw new InvalidConfigException('Invalid options for styles configuration!');
                                }
                                if ($tag !== '') {
                                    $addTags[] = str_replace("'", '', trim($tag));
                                }
                            }
                        }
                        if (!empty($addTags)) {
                            $tmp .= ",['" . implode("','", $addTags) . "']";
                        }
                    }
                    $tmp .= ")";
                    $newStyles[] = $tmp;
                    if ($this->globalConfig) {
                        $this->getView()->params[self::GLOBAL_PARAMS_KEY]['styles'][] = Html::encode($name);
                    }
                }
            }
            if (!empty($newStyles)) {
                $this->getView()->registerJs(";ContentTools.StylePalette.add([" . implode(',', $newStyles) . "]);", View::POS_END);
            }
        }
    }
    
    /**
     * Closes the widget.
     * Adds engines, styles and translation.
     */
    public function run()
    {
        if (!$this->_global) {
            $this->addTranslation();
            $this->initImagesEngine();
            $this->initEditor();
        }
        $this->addStyles();
        echo Html::endTag($this->tag);
    }
    
    /**
     * Initiates the widget.
     * Checks configuration.
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        if (!is_string($this->tag)) {
            throw new InvalidConfigException('Invalid tag configuration!');
        }
        if (!is_string($this->dataInit)) {
            throw new InvalidConfigException('Invalid dataInit configuration!');
        }
        if (!is_string($this->dataName)) {
            throw new InvalidConfigException('Invalid dataName configuration!');
        }
        if (!is_array($this->options)) {
            throw new InvalidConfigException('Invalid options configuration!');
        }
        if (!is_array($this->imagesEngine) && $this->imagesEngine !== false) {
            throw new InvalidConfigException('Invalid imagesEngine configuration!');
        }
        if (!is_array($this->saveEngine) && $this->saveEngine !== false) {
            throw new InvalidConfigException('Invalid saveEngine configuration!');
        }
        if (!is_array($this->styles)) {
            throw new InvalidConfigException('Invalid styles configuration!');
        }
        if (!is_string($this->language) && $this->language !== false && $this->language !== true) {
            throw new InvalidConfigException('Invalid language configuration!');
        }
        if ($this->globalConfig !== false && $this->globalConfig !== true) {
            throw new InvalidConfigException('Invalid globalConfig configuration!');
        }
        $this->globalConfig();
        echo Html::beginTag($this->tag, $this->prepareOptions());
    }
    
    /**
     * Returns data-* attribute with the given name.
     * @param string $attribute Attribute name,
     * @return string
     */
    public static function dataAttribute($attribute)
    {
        return 'data-' . $attribute;
    }
    
    /**
     * Merges the dataInit and dataName attributes with the rest of the options array.
     * @return array
     */
    public function prepareOptions()
    {
        return array_merge(
                [static::dataAttribute($this->dataInit) => true, static::dataAttribute($this->dataName) => $this->id],
                $this->options
            );
    }
}
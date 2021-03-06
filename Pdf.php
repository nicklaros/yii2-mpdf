<?php

namespace nicklaros\yii2mpdf;

use Yii;
use yii\base\Component;
use yii\base\InvalidConfigException;
use yii\base\InvalidParamException;
use Mpdf\Mpdf;

/**
 * Class Pdf enable us to create pdf file using mPDF library
 *
 * @property mPDF $api
 * @property string $css
 */
class Pdf extends Component
{
    const DEST_BROWSER = 'I';
    const DEST_DOWNLOAD = 'D';
    const DEST_FILE = 'F';
    const DEST_STRING = 'S';

    const FORMAT_A3 = 'A3';
    const FORMAT_A4 = 'A4';
    const FORMAT_FOLIO = 'Folio';
    const FORMAT_LEDGER = 'Ledger-L';
    const FORMAT_LEGAL = 'Legal';
    const FORMAT_LETTER = 'Letter';
    const FORMAT_TABLOID = 'Tabloid';

    const MODE_ASIAN = '+aCJK';
    const MODE_BLANK = '';
    const MODE_CORE = 'c';
    const MODE_UTF8 = 'UTF-8';

    const ORIENT_PORTRAIT = 'P';
    const ORIENT_LANDSCAPE = 'L';

    /**
     * @var string HTML content to be converted to PDF
     */
    public $content = '';

    /**
     * @var string Css file used to style generated PDF
     */
    public $cssFile = '@vendor/nicklaros/yii2-mpdf/assets/bootstrap.min.css';

    /**
     * @var string Additional inline css
     */
    public $inlineCss = '';

    /**
     * @var string Default font-family
     */
    public $defaultFont = '';

    /**
     * @var int Default document font size in points (pt)
     */
    public $defaultFontSize = 0;

    /**
     * @var string Output destination
     */
    public $destination = '';

    /**
     * @var string Output filename
     */
    public $filename = '';

    /**
     * @var string|array Page size format, you can also pass an array of width and height in millimeter
     */
    public $format = self::FORMAT_A4;

    /**
     * @var float Page bottom margin (in millimeter)
     */
    public $marginBottom = 16;

    /**
     * @var float Page footer margin (in millimeter)
     */
    public $marginFooter = 9;

    /**
     * @var float Page header margin (in millimeter)
     */
    public $marginHeader = 9;

    /**
     * @var float Page left margin (in millimeter)
     */
    public $marginLeft = 15;

    /**
     * @var float Page right margin (in millimeter)
     */
    public $marginRight = 15;

    /**
     * @var float Page top margin (in millimeter)
     */
    public $marginTop = 16;

    /**
     * @var string Mode for generated document. If country or language code passed, it may affect available fonts,
     * text alignment, and RTL text direction
     */
    public $mode = self::MODE_BLANK;

    /**
     * @var string Page orientation
     */
    public $orientation = self::ORIENT_PORTRAIT;

    /**
     * @var string Folder path for storing temporary data generated by mPDF.
     * If not set this defaults to `Yii::getAlias('@runtime/mpdf')`.
     */
    public $tempPath;

    /**
     * @var array mPDF methods that will be called in the sequence listed before rendering the content.
     * It should be in `key value pair` format of mPDF method name and it's parameters
     */
    public $methods = [];

    /**
     * @var string mPDF configuration in `key value pair` format of property name and it's value
     */
    public $options = [
        'autoScriptToLang' => true,
        'ignore_invalid_utf8' => true,
        'tabSpaces' => 4
    ];

    /**
     * @var string Cached css file content
     */
    protected $cachedCss;

    /**
     * @var mPDF api instance
     */
    protected $mpdf;

    /**
     * @inherit doc
     */
    public function init()
    {
        $this->setTempPaths();

        parent::init();

        $this->parseFormat();
    }

    /**
     * Configures mPDF options
     *
     * @param array $options mPDF configuration in `key value pair` format of property name and it's value
     */
    public function configure($options = [])
    {
        if (empty($options)) {
            return;
        }

        $api = $this->api;

        foreach ($options as $key => $value) {
            if (property_exists($api, $key)) {
                $api->$key = $value;
            }
        }
    }

    /**
     * Generates pdf output
     *
     * @param string $content HTML content to be converted to PDF
     * @param string $file the name of the file. If not specified, the document will be
     * sent inline to the browser
     * @param string $destination
     * @return mixed
     */
    public function output($content = '', $file = '', $destination = '')
    {
        $api = $this->api;
        $css = $this->css;

        if (!empty($css)) {
            $api->WriteHTML($css, 1);
            $api->WriteHTML($content, 2);
        } else {
            $api->WriteHTML($content);
        }

        return $api->Output($file, $destination);
    }

    /**
     * Renders pdf output
     */
    public function render()
    {
        $this->configure($this->options);
        if (!empty($this->methods)) {
            foreach ($this->methods as $method => $param) {
                $this->runMethod($method, $param);
            }
        }

        return $this->output($this->content, $this->filename, $this->destination);
    }

    /**
     * Defines mPDF temporary path. it will create new folder if passed path does'nt exist
     *
     * @param string $type
     * @param string $path
     * @return bool
     * @throws InvalidConfigException
     */
    protected function defineTempPath($type, $path)
    {
        if (defined($type)) {
            $path = constant($type);
            if (is_writable($path)) {
                return;
            }
        }

        $status = true;

        if (!is_dir($path)) {
            $status = mkdir($path, 0777, true);
        }

        if (!$status) {
            throw new InvalidConfigException("Could not create folder '{$path}'");
        }

        define($type, $path);
    }

    /**
     * Returns mPDF instance
     *
     * @return mPDF
     */
    protected function getApi()
    {
        if (empty($this->mpdf) || !$this->mpdf instanceof mPDF) {
            $this->setApi();
        }

        return $this->mpdf;
    }

    /**
     * Returns css content used to style generated pdf
     *
     * @return string
     */
    protected function getCss()
    {
        if (!empty($this->cachedCss)) {
            return $this->cachedCss;
        }

        $cssFile = empty($this->cssFile) ? '' : Yii::getAlias($this->cssFile);

        if (empty($cssFile) || !file_exists($cssFile)) {
            $css = '';
        } else {
            $css = file_get_contents($cssFile);
        }

        $css .= $this->inlineCss;
        $this->cachedCss = $css;

        return $css;
    }

    /**
     * Parse the format automatically based on the orientation
     */
    protected function parseFormat()
    {
        $tag = '-' . self::ORIENT_LANDSCAPE;

        if (
            $this->orientation == self::ORIENT_LANDSCAPE &&
            is_string($this->format) &&
            substr($this->format, -2) != $tag
        ) {
            $this->format .= $tag;
        }
    }

    /**
     * Runs mPDF method
     *
     * @param string $method mPDF method name
     * @param array $params method parameters
     * @return mixed
     */
    protected function runMethod($method, $params = [])
    {
        $api = $this->api;

        if (!method_exists($api, $method)) {
            throw new InvalidParamException("Invalid or undefined mPDF method '{$method}' passed to 'Pdf::runMethod'.");
        }

        if (!is_array($params)) {
            $params = [$params];
        }

        return call_user_func_array([$api, $method], $params);
    }

    /**
     * Sets mPDF instance
     */
    protected function setApi()
    {
        $this->mpdf = new Mpdf([
            'mode' => $this->mode,
            'format' => $this->format,
            'default_font_size' => $this->defaultFontSize,
            'default_font' => $this->defaultFont,
            'margin_left' => $this->marginLeft,
            'margin_right' => $this->marginRight,
            'margin_top' => $this->marginTop,
            'margin_bottom' => $this->marginBottom,
            'margin_header' => $this->marginHeader,
            'margin_footer' => $this->marginFooter,
            'orientation' => $this->orientation
        ]);
    }

    /**
     * Sets paths for mPDF to write temporary data.
     */
    protected function setTempPaths()
    {
        if (empty($this->tempPath)) {
            $this->tempPath = Yii::getAlias('@runtime/mpdf');
        }
    }
}

<?php

namespace Sivka;

class Img {

    protected $path;
    protected $image;
    protected $width;
    protected $height;
    protected $mime;

    /** @var string $docRoot DOCUMENT ROOT path. If not defined, will be used $_SERVER['DOCUMENT_ROOT'] */
    private static $docRoot;

    /**
     * path to directory where resized images should be placed. If not defined,
     * cache directory(with name "thumbs") will be created in source image directory.
     * @var string $cacheDirectory
     */
    private static $cacheDirectory = '';

    private static $placeholder;

    /**
     * Default values
     * @var array $defaults
     * @param integer $width - with to resized
     * @param integer $height - height to resized
     * @param mixed $crop - boolean or number(1/0) or
     * string with crop position center|top|left|bottom|right|top left|top right|bottom left|bottom right (default: center)
     * @param mixed $scale bool or background color
     * @param string $watermark - path to watermark file_ext
     * @param string $wm_position - watermark position center|top|left|bottom|right|top left|top right|bottom left|bottom right
     * @param float $wm_opacity - opacity of watermark or watermark text
     * @param integer $quality - quality for result images
     * @param string $wm_text - text for overlay on images
     * @param mixed $wm_text_color - color of watermark text,
     * 							maybe string('#FFFFFF') or array ['r'=>255,'g'=>255, 'b'=> 255, 'a'=>1] or array [255, 255, 255, 1]
     * @param integer $wm_text_size - font size of watermark text
     * @param string $wm_text_font - name of font for watermark text, the font file mast be in same directory with Img.php
     * @param string $placeholder - path to placeholder, if defined and source image does not exists, this file will be converted
     */
    public static $defaults = array(
        'width' => 0,
        'height' => 0,
        'crop' => false,
        'scale' => false,
        'watermark' => '',
        'wm_position' => 'center',
        'wm_opacity' => 0.6,
        'wm_text' => '',
        'wm_text_color' => '#FFFFFF',
        'wm_text_size' => 32,
        'wm_text_font' => '',
        'placeholder' => '',
        'quality' => 80
    );

    /**
     * short aliases for parameters
     * @var array $aliases
     */
    public static $aliases = array(
        'w' => 'width',
        'h' => 'height',
        'c' => 'crop',
        's' => 'scale',
        'wm' => 'watermark',
        'wmp' => 'wm_position',
        'wmo' => 'wm_opacity',
        'wmt' => 'wm_text',
        'wmtc' => 'wm_text_color',
        'wmts' => 'wm_text_size',
        'wmtf' => 'wm_text_font',
        'p' => 'placeholder',
        'q' => 'quality'
    );

    /**
     * Img constructor.
     * @param string $path - source file path
     */
    public function __construct($path){
        $this->path = $path;
        @ini_set('gd.jpeg_ignore_warning', 1);

        $info = getimagesize($this->path);

        $this->width = $info[0];
        $this->height = $info[1];
        $this->mime = $info['mime'];

        $this->{'from_' . str_replace('/', '_', $this->mime)}();
    }

    /**
     * @param string $fileName - source file path
     * @param array $params
     * @param int $height
     * @param bool $crop
     * @return string
     */
    public static function get($fileName, $params = array(), $height = 0, $crop = false){

        if(!is_array($params)){
            $params = array(
                'width' => $params,
                'height' => $height,
            );
            if($crop && $crop == 'scale') $params['scale'] = true;
            elseif($crop) $params['crop'] = $crop;
        }

        $params = self::setParams($params);

        if(!$filepath = self::checkFilepath($fileName, $params)) return '';
        $newFilename = self::getNewFileName($filepath, $params);

        if(isset($params['onlyPath'])) return self::getRelativeLink($newFilename);

        $filepath = self::getAbsolutePath($filepath);
        $srcTime = filemtime($filepath);

        if(is_file($newFilename) && $srcTime == filemtime($newFilename) ) return self::getRelativeLink($newFilename);

        $image = new self($filepath);

        if($params['width'] && $params['height']){
            if($params['crop']){
                $image->crop($params['width'], $params['height'], $params['crop']);
            } elseif($params['scale']){
                if(!is_array($params['scale']) && !is_string($params['scale'])) $params['scale'] = 'fff';
                $image->scale($params['width'], $params['height'], $params['scale']);
            }
        }elseif($params['width'] && !$params['height']){
            $image->toWidth($params['width']);
        }elseif(!$params['width'] && $params['height']){
            $image->toHeight($params['height']);
        }

        if($params['watermark']) self::setWatermark($image, $params);
        if($params['wm_text']) self::setWatermarkText($image, $params);

        $image->save($newFilename, $params['quality']);

        touch($newFilename, $srcTime);

        return self::getRelativeLink($newFilename);

    }

    /**
     * @param \Sivka\Img $image
     * @param array $params
     */
    private static function setWatermarkText($image, $params){
        $image->text($params['wm_text'],
            array(
                'font' => $params['wm_text_font'],
                'size' => $params['wm_text_size'],
                'color' => self::normalizeColor($params['wm_text_color'], $params['wm_opacity'])
            )
        );
    }

    /**
     * @param \Sivka\Img $image
     * @param array $params
     */
    private static function setWatermark($image, $params){
        $watermark = self::getAbsolutePath($params['watermark']);
        if(!is_file($watermark)) return;
        $image->watermark($watermark, array('opacity' => $params['wm_opacity']));
    }

    /**
     * generate new file path
     * @param string $filepath
     * @param array $params
     * @return string
     */
    private static function getNewFileName($filepath, $params){
        $fileinfo = pathinfo($filepath);
        $thumbnailDir = self::getCacheDirectory($fileinfo['dirname']);

        $thumbnailDir .= self::generateThumbnailDirName($params);

        if (!file_exists($thumbnailDir)) mkdir($thumbnailDir);
        $extension = self::hasAlpha($params['scale']) ? 'png' : $fileinfo['extension'];
        return $thumbnailDir . '/' . $fileinfo['filename'] . '.' . $extension;
    }

    /**
     * @param mixed $color
     * @return bool
     */
    private static function hasAlpha($color){
        if(!is_array($color) || count($color) < 4) return false;
        return (isset($color['a']) && $color['a'] != 1) || (isset($color[3]) && $color[3] != 1);
    }

    /**
     * create cache directory
     * @param string $fileDir
     * @return string
     */
    private static function getCacheDirectory($fileDir){
        if(!self::$cacheDirectory){
            $thumbnailDir = self::getAbsolutePath($fileDir . '/thumbs');
            if (!file_exists($thumbnailDir)) mkdir($thumbnailDir);
            return $thumbnailDir .= '/';
        }
        $path = self::getRelativeLink(self::$cacheDirectory) . self::getRelativeLink($fileDir);
        $dirs = preg_split('~[/]~', $path, -1, PREG_SPLIT_NO_EMPTY);

        $thumbnailDir = self::getDocRoot();

        foreach($dirs as $dir){
            $thumbnailDir .= '/' . $dir;
            if(!is_dir($thumbnailDir)) mkdir($thumbnailDir);
        }
        return $thumbnailDir .= '/';
    }

    /**
     * @param array $params
     * @return string
     */
    private static function generateThumbnailDirName($params){
        $shorts = array();
        $wm = '';

        foreach($params as $k => $v){
            $key = array_search($k, self::$aliases);
            if(!$key || !$v || $key == 'p' || $key == 'q') continue;

            if(is_array($v)) $v = implode('', $v);

            if(strpos($key, 'wm') === 0 && ($params['watermark'] || $params['wm_text'])) $wm .= $v;
            elseif(strpos($key, 'wm') !== 0) $shorts[] = strtolower($key . str_replace(array(' ','#'), '', $v));
        }

        if($wm) $shorts[] = 'wm_' . hash('crc32', $wm);

        return implode('-', $shorts) . '-q' . $params['quality'];
    }

    /**
     * check if source file exists and returns absolute path or path to placeholder
     * @param string $filepath
     * @param array $params
     * @return string
     */
    private static function checkFilepath($filepath, $params){
        return  is_file( self::getAbsolutePath( $filepath ) ) ? $filepath : self::getPlaceholder($params);
    }

    /**
     * returns placeholder img if source img not exists
     * @param array $params
     * @return string
     */
    private static function getPlaceholder($params){
        if(self::$placeholder) return self::$placeholder;
        return $params['placeholder'] && is_file(self::getAbsolutePath($params['placeholder'])) ? self::getAbsolutePath($params['placeholder']) : '';
    }

    /**
     * set placeholder image
     * @param string $filepath
     * @return void
     */
    public static function setPlaceholder($filepath){
        self::$placeholder = self::getAbsolutePath($filepath);
    }

    /**
     * calculate relative path of file
     * @param string $path
     * @return string
     */
    private static function getRelativeLink($path){
        $docRoot = self::getDocRoot();
        if (substr($path, 0, strlen($docRoot)) == $docRoot) $path = substr($path, strlen($docRoot));
        return '/' . ltrim($path, '/');
    }

    /**
     * calculate absolute path of file
     * @param string $path
     * @return string
     */
    private static function getAbsolutePath($path){
        return self::getDocRoot() . '/' . ltrim( self::getRelativeLink($path), '/' );
    }

    /**
     * setup params
     * @param array $params
     * @return array
     */
    public static function setParams($params){
        $params = self::setFromAliases($params);
        self::$defaults['wm_text_font'] = __DIR__ . '/arial.ttf';
        $params = array_merge(self::$defaults, $params);
        if($params['crop'] && !is_string($params['crop'])) $params['crop'] = 'center';
        return $params;
    }

    /**
     * setup default params
     * @param array $params
     * @return void
     */
    public static function setDefaults($params){
        $params = self::setFromAliases($params);
        self::$defaults = array_merge(self::$defaults, $params);
    }

    /**
     * set params defined as short aliases
     * @param array $params
     * @return array
     */
    private static function setFromAliases($params){
        foreach($params as $k => $v){
            if(isset(self::$aliases[$k])){
                $params[ self::$aliases[$k] ] = $v;
                unset($params[$k]);
            }
        }
        return $params;
    }


    /**
     * set cache directory path
     * @param string $dir
     * @return void
     */
    public static function setCacheDirectory($dir){
        self::$cacheDirectory = self::getAbsolutePath($dir);
    }

    /**
     * set DOCUMENT ROOT
     * @param string $docRoot
     * @return void
     */
    public static function setDocRoot($docRoot){
        self::$docRoot = rtrim($docRoot, '/');
    }

    /**
     * get DOCUMENT ROOT
     * @return string
     */
    public static function getDocRoot(){
        return self::$docRoot ? self::$docRoot : $_SERVER['DOCUMENT_ROOT'];
    }


    ///////////////////////////////////////////////////// manipulations /////////////////////////////////////////////////////////////

    /**
     * save image to file
     * @param string $fileName
     * @param int $quality
     * @return $this
     */
    public function save($fileName, $quality = 100){
        $this->create($fileName, $quality);
        return $this;
    }

    /**
     * force image to download
     * @param string $fileName
     * @param int $quality
     * @return $this
     */
    public function download($fileName, $quality = 100){
        $data = $this->create($fileName, $quality, true);

        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Description: File Transfer');
        header('Content-Length: ' . strlen($data->image));
        header('Content-Transfer-Encoding: Binary');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');

        echo $data->image;

        return $this;
    }

    /**
     * returns image as data uri
     * @param string $mime
     * @param int $quality
     * @return string
     */
    public function dataUri($mime, $quality = 100){
        if(!strpos($mime, '/')) $mime = 'image/' . str_replace('jpg', 'jpeg', $mime);
        $data = $this->create(null, $quality, $mime);
        return 'data:' . $data->mime . ';base64,' . base64_encode($data->image);
    }

    /**
     * generate image file
     * @param string $fileName
     * @param int $quality
     * @param bool|string $toBinary
     * @return \stdClass
     * @throws \Exception
     */
    protected function create($fileName, $quality = 100, $toBinary = false){

        if($quality > 100 || $quality < 0) $quality = $quality > 100 ? 100 : 0;

        $data = new \stdClass();

        $data->mime = $fileName ? 'image/' . str_replace('jpg', 'jpeg', strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) : $toBinary;

        if($toBinary){
            ob_start();
            $fileName = null;
        }

        if($data->mime == 'image/jpeg'){
            imageinterlace($this->image, true);
            imagejpeg($this->image, $fileName, $quality);
        }elseif($data->mime == 'image/png'){
            imagesavealpha($this->image, true);
            imagepng($this->image, $fileName, round(9 * $quality / 100));
        }elseif($data->mime == 'image/gif'){
            imagesavealpha($this->image, true);
            imagegif($this->image, $fileName);
        }elseif($data->mime == 'image/webp'){
            imagesavealpha($this->image, true);
            imagewebp($this->image, $fileName, $quality);
        }else{
            throw new \Exception('Unsupported type: ' . $data->mime);
        }

        if($toBinary) $data->image = ob_get_clean();

        return $data;

    }

    /**
     * @param int $height
     * @return Img
     */
    public function toHeight($height){
        return $this->resize($height * ($this->width / $this->height), $height);
    }

    /**
     * @param int $width
     * @return Img
     */
    public function toWidth($width){
        return $this->resize($width, $width / ($this->width / $this->height));
    }

    /**
     * @param int $size
     * @return Img
     */
    public function toSize($size){
        return $this->width > $this->height ? $this->toWidth($size) : $this->toHeight($size);
    }

    /**
     * @param int $width
     * @param int $height
     * @return Img
     */
    public function fit($width, $height){

        if($width > $this->width) $width = $this->width;
        if($height > $this->height) $height = $this->height;

        if($this->width > $this->height) $height = $width / ($this->width / $this->height);
        else $width = $height * ($this->width / $this->height);

        return $this->resize($width, $height);
    }

    /**
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function resize($width, $height){
        if($width === $this->width && $this->height === $height) return $this;

        $newImage = imagecreatetruecolor($width, $height);

        if($this->mime == 'image/gif' || $this->mime == 'image/png'){
            $transparent = imagecolorallocatealpha($newImage, 0, 0, 0, 127);
            imagecolortransparent($newImage, $transparent);
            imagefill($newImage, 0, 0, $transparent);
        }

        imagecopyresampled(
            $newImage,
            $this->image,
            0, 0, 0, 0,
            $width,
            $height,
            $this->width,
            $this->height
        );

        $this->image = $newImage;
        return $this->updateDimensions();
    }

    /**
     * @param int $width
     * @param int $height
     * @param string $bgcolor
     * @return $this
     */
    public function scale($width, $height, $bgcolor = '#ffffff'){

        if($this->width == $width && $this->height == $height) return $this;

        $newImage = imagecreatetruecolor($width, $height);

        $color = self::normalizeColor($bgcolor);
        imagealphablending($newImage, true);
        imagesavealpha($newImage, true);
        $color = imagecolorallocatealpha($newImage, $color['r'], $color['g'], $color['b'], 127 - ($color['a'] * 127));

        imagefill($newImage, 0, 0, $color);

        $newWidth = $width > $this->width ? $newWidth = $this->width : $width;
        $newHeight = $height > $this->height ? $newHeight = $this->height : $height;

        if($this->width > $this->height) $newHeight = $width / ($this->width / $this->height);
        else $newWidth = $height * ($this->width / $this->height);

        imagecopyresampled(
            $newImage,
            $this->image,
            $this->width > $this->height ? 0 : floor(($width / 2) - ($newWidth / 2)),
            $this->width > $this->height ? floor(($height / 2) - ($newHeight / 2)) : 0,
            0, 0,
            $newWidth,
            $newHeight,
            $this->width,
            $this->height
        );

        $this->image = $newImage;
        return $this->updateDimensions();

    }

    /**
     * @param int $width
     * @param int $height
     * @param string $anchor
     * @return $this
     */
    public function crop($width, $height, $anchor = 'center'){

        if($this->width > $this->height) $this->toHeight($height);
        else $this->toWidth($width);

        $widthOffset = floor(($this->width / 2) - ($width / 2));
        $heightOffset = floor(($this->height / 2) - ($height / 2));

        if($anchor == 'top') return $this->_crop($widthOffset, 0, $width, $height);

        if($anchor == 'bottom') return $this->_crop($widthOffset, $this->height - $height, $width, $height);

        if($anchor == 'left') return $this->_crop(0, $heightOffset, $width, $height);

        if($anchor == 'right') return $this->_crop($this->width - $width, $heightOffset, $width, $height);

        if($anchor == 'top left') return $this->_crop(0, 0, $width, $height);

        if($anchor == 'top right') return $this->_crop($this->width - $width, 0, $width, $height);

        if($anchor == 'bottom left') return $this->_crop(0, $this->height - $height, $width, $height);

        if($anchor == 'bottom right') return $this->_crop($this->width - $width, $this->height - $height, $width, $height);

        return $this->_crop($widthOffset, $heightOffset, $width, $height);

    }

    /**
     * @param int $x
     * @param int $y
     * @param int $width
     * @param int $height
     * @param null|Img $image
     * @return $this
     */
    public function _crop($x, $y, $width, $height, $image = null){
        if($image) return imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
        $this->image = imagecrop($this->image, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
        return $this->updateDimensions();
    }

    /**
     * @param string|Img $wmImage
     * @param array $options
     * @return $this
     */
    public function watermark($wmImage, $options = []){
        $options = array_merge([
            'anchor' => 'center',
            'offsetX' => 0,
            'offsetY' => 0,
            'opacity' => 0.8
        ], $options);

        $opacity = min($options['opacity'], 1) * 100;

        if(!is_object($wmImage)) $wmImage = new self($wmImage);

        $x = $options['offsetX']; // top left
        $y = $options['offsetY']; // top left

        if($options['anchor'] == 'left'){
            $y = ($this->height / 2) - ($wmImage->getHeight() / 2) + $options['offsetY'];
        }elseif($options['anchor'] == 'top right'){
            $x = $this->width - $wmImage->getWidth() + $options['offsetX'];
        }elseif($options['anchor'] == 'top'){
            $x = ($this->width / 2) - ($wmImage->getWidth() / 2) + $options['offsetX'];
        }elseif($options['anchor'] == 'bottom'){
            $x = ($this->width / 2) - ($wmImage->getWidth() / 2) + $options['offsetX'];
            $y = $this->height - $wmImage->getHeight() + $options['offsetY'];
        }elseif($options['anchor'] == 'bottom left'){
            $y = $this->height - $wmImage->getHeight() + $options['offsetY'];
        }elseif($options['anchor'] == 'bottom right'){
            $x = $this->width - $wmImage->getWidth() + $options['offsetX'];
            $y = $this->height - $wmImage->getHeight() + $options['offsetY'];
        }elseif($options['anchor'] == 'right'){
            $x = $this->width - $wmImage->getWidth() + $options['offsetX'];
            $y = ($this->height / 2) - ($wmImage->getHeight() / 2) + $options['offsetY'];
        }else{
            $x = ($this->width / 2) - ($wmImage->getWidth() / 2) + $options['offsetX'];
            $y = ($this->height / 2) - ($wmImage->getHeight() / 2) + $options['offsetY'];
        }

        if($opacity < 100) {
            imagealphablending($wmImage->getImage(), false);
            imagefilter($wmImage->getImage(), IMG_FILTER_COLORIZE, 0, 0, 0, 127 - ($options['opacity'] * 127));
        }

        imagecopy($this->image, $wmImage->getImage(), $x, $y, 0, 0, $wmImage->getWidth(), $wmImage->getHeight());

        return $this;
    }

    /**
     * @param string $text
     * @param array $options
     * @param array $border
     * @return $this
     */
    public function text($text, $options = [], $border = []){
        $options = array_merge([
            'font' => __DIR__ . '/arial.ttf',
            'size' => 19,
            'color' => [0, 0, 0, 1],
            'anchor' => 'center',
            'offsetX' => 0,
            'offsetY' => 0,
            'shadow' => null
        ], $options);

        $sizePt = $options['size'] / 96 * 72;

        $box = imagettfbbox($sizePt, 0, $options['font'], 'QqyjpqZ');
        $height = abs($box[7] - $box[1]);

        $box = imagettfbbox($sizePt, 0, $options['font'], $text);
        $width = abs($box[6] - $box[4]);

        $x = $options['offsetX'];           // top left
        $y = $height + $options['offsetY']; // top left
        if($options['anchor'] == 'left'){
            $y = ($this->height / 2) - (($height / 2) - $height) + $options['offsetY'];
        }elseif($options['anchor'] == 'top right'){
            $x = $this->width - $width + $options['offsetX'];
        }elseif($options['anchor'] == 'top'){
            $x = ($this->width / 2) - ($width / 2) + $options['offsetX'];
        }elseif($options['anchor'] == 'bottom'){
            $x = ($this->width / 2) - ($width / 2) + $options['offsetX'];
            $y = $this->height - $options['offsetY'];
        }elseif($options['anchor'] == 'bottom left'){
            $y = $this->height - $options['offsetY'];
        }elseif($options['anchor'] == 'bottom right'){
            $x = $this->width - $width + $options['offsetX'];
            $y = $this->height - $options['offsetY'];
        }elseif($options['anchor'] == 'right'){
            $x = $this->width - $width + $options['offsetX'];
            $y = ($this->height / 2) - (($height / 2) - $height) + $options['offsetY'];
        }else{
            $x = ($this->width / 2) - ($width / 2) + $options['offsetX'];
            $y = ($this->height / 2) - (($height / 2) - $height) + $options['offsetY'];
        }

        imagettftext($this->image, $sizePt, 0, $x, $y, $this->getColor($options['color']), $options['font'], $text);

        if($border){

            $border = array_merge([
                'width' => 1,
                'color' => $options['color'],
                'offsetX' => $options['size'],
                'offsetY' => $options['size'] / 2
            ], (array)$border);

            imagesetthickness($this->image, $border['width']);
            imagerectangle(
                $this->image,
                $x - $border['offsetX'],
                $y - $height + $box[1] - $border['offsetY'],
                $x + $width + $border['offsetX'],
                $y + $box[1] + $border['offsetY'],
                $this->getColor($border['color'])
            );
        }

        return $this;
    }


    protected function from_image_jpeg(){
        $this->image = imagecreatefromjpeg($this->path);
    }

    protected function from_image_png(){
        $this->image = imagecreatefrompng($this->path);
    }

    protected function from_image_webp(){
        $this->image = imagecreatefromwebp($this->path);
    }

    protected function from_image_gif(){
        $image = imagecreatefromgif($this->path);
        $this->image = imagecreatetruecolor($this->width, $this->height);
        $transparent = imagecolorallocatealpha($this->image, 0, 0, 0, 127);
        imagefill($this->image, 0, 0, $transparent);
        imagecopy($this->image, $image, 0, 0, 0, 0, $this->width, $this->height);
        imagedestroy($image);
    }

    /**
     * @param mixed $color
     * @param int $opacity
     * @return array
     */
    private static function normalizeColor($color, $opacity = 1){
        if(is_array($color)){
            if(isset($color[0]) && !isset($color[3])) return ['r' => $color[0], 'g' => $color[1], 'b' => $color[2], 'a' => 0];
            if(isset($color[0]) && isset($color[3])) return ['r' => $color[0], 'g' => $color[1], 'b' => $color[2], 'a' => $color[3]];
            return $color;
        }
        $color = ltrim($color, '#');
        if(strlen($color) == 3) $color = $color[0] . $color[0] . $color[1] . $color[1] . $color[2] . $color[2];
        return array(
            'r' => hexdec($color[0] . $color[1]),
            'g' => hexdec($color[2] . $color[3]),
            'b' => hexdec($color[4] . $color[5]),
            'a' => $opacity
        );
    }

    public function getColor($color, $image = null){
        if(!$image) $image = $this->image;
        $color = self::normalizeColor($color);
        return imagecolorallocatealpha($image, $color['r'], $color['g'], $color['b'], 127 - ($color['a'] * 127));
    }


    public function updateDimensions(){
        $this->width = (int)imagesx($this->image);
        $this->height = (int)imagesy($this->image);
        return $this;
    }

    public function getImage(){
        return $this->image;
    }

    public function getWidth(){
        return $this->width;
    }

    public function getHeight(){
        return $this->height;
    }

}
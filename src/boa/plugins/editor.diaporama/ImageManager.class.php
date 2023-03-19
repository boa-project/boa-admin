<?php
// This file is part of BoA - https://github.com/boa-project
//
// BoA is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// BoA is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with BoA.  If not, see <http://www.gnu.org/licenses/>.
//
// The latest code can be found at <https://github.com/boa-project/>.

namespace BoA\Plugins\Editor\Diaporama;

defined('APP_EXEC') or die( 'Access not allowed');

define("PNG", "png");
define("GIF", "gif");
define("JPG", "jpg");
define("JPEG", "jpeg");

/**
 * Class to manage video conversion and others
 *
 * @package    [App Plugins]
 * @category   [Editor]
 * @copyright  2019 BoA Project
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU Affero GPL v3 or later
 */
class ImageManager {

    private $availableSizes;
    private $filename;
    private $imageinfo;


    function __construct($filename, $availablesizes){

        if (!file_exists($filename)) {
            throw new \Exception("File -$filename- not found in " . __FUNCTION__ . " method");
        }
        $this->availableSizes = is_array($availablesizes) ? $availablesizes : array();
        $this->filename = $filename;
        $this->imageinfo = $this->getImageInfo();
        if (is_null($this->imageinfo)) {
            throw new \Exception("File -$filename has unsupported extension");
        }
    }

    public function ensureFormat($format, $alternatepath) {
        foreach ($this->availableSizes as $size => $value) {
            $alternatefilename = "$alternatepath/$size.$format";

            if (!file_exists($alternatefilename)) {
                $this->convert($alternatefilename, $format, $size);
            }
        }
    }

    public function generateThumb($outputdir, $quality) {
        // Path for Thumb image
        $thumbpath = $outputdir . "/thumb.png";
        if (file_exists($thumbpath)) return;

        $this->toPNG($thumbpath, [256, 256], $quality);
    }

    private function convert($output, $format, $size) {
        $outputdirname = pathinfo($output, PATHINFO_DIRNAME);

        switch ($format) {
            case PNG:
                $this->toPNG($output, $size);
                break;

            case JPG:
                $this->toJPG($output, $size);
                break;

            case GIF:
                $this->toGIF($output, $size);
                break;

            case JPEG:
                $this->toJPG($output, $size);
                break;
        }
    }

    private function toPNG($output, $size, $quality = -1) { //-1 is to use the default zlib compression level
        list($width, $height) = is_array($size) ? $size : $this->availableSizes[$size];

        list ($nw, $nh, $dst_x, $dst_y) = $this->getDimensions($width, $height);
        $new_image = imagecreatetruecolor($width, $height);
        // Prepare alpha channel for transparent background
        $alpha_channel = imagecolorallocatealpha($new_image, 0, 0, 0, 127);
        imagecolortransparent($new_image, $alpha_channel);
        // Fill image
        imagefill($new_image, 0, 0, $alpha_channel);
        //Save transparency
        imagesavealpha($new_image,true);
        //Copy image
        imagecopyresampled($new_image, $this->getImage(), $dst_x, $dst_y, 0, 0, $nw, $nh, $this->imageinfo->width, $this->imageinfo->height);
        imagepng($new_image, $output, $quality);
        imagedestroy($new_image);
    }

    private function toJPG($output, $size) {
        $input = $this->clearFilename();
        list($width, $height) = $this->availableSizes[$size];

        list ($nw, $nh, $dst_x, $dst_y) = $this->getDimensions($width, $height);
        $new_image = imagecreatetruecolor($width, $height);

        imagecopyresampled($new_image, $this->getImage(), $dst_x, $dst_y, 0, 0, $nw, $nh, $this->imageinfo->width, $this->imageinfo->height);
        imagejpeg($new_image, $output);
        imagedestroy($new_image);
    }

    private function toGIF($output, $size) {
        $input = $this->clearFilename();
        list($width, $height) = $this->availableSizes[$size];

        list ($nw, $nh, $dst_x, $dst_y) = $this->getDimensions($width, $height);
        $new_image = imagecreatetruecolor($width, $height);

        imagecopyresampled($new_image, $this->getImage(), $dst_x, $dst_y, 0, 0, $nw, $nh, $this->imageinfo->width, $this->imageinfo->height);
        imagegif($new_image, $output);
        imagedestroy($new_image);
    }

    public function getImageInfo() {
        $src = $this->clearFilename();
        if(!list($w, $h) = getimagesize($src)) {
            return null;
        }

        $info = new \stdClass();
        $info->width = $w;
        $info->height = $h;
        $info->type = str_replace(".", "", image_type_to_extension(exif_imagetype($src)));
        return $info;
    }

    private function getImage() {
        if (isset($this->imageinfo->resource)) {
            return $this->imageinfo->resource;
        }

        $src = $this->clearFilename();
        switch ($this->imageinfo->type) {
            case JPEG:
            case JPG:
                $this->imageinfo->resource = imagecreatefromjpeg($src);
                break;

            case PNG:
                $this->imageinfo->resource = imagecreatefrompng($src);
                break;

            case GIF:
                $this->imageinfo->resource = imagecreatefromgif($src);
                break;

            default:
                $this->imageinfo->resource = false;
                break;
        }

        return $this->imageinfo->resource;
    }

    private function getDimensions($width, $height) {
        $ratio = $this->imageinfo->width / $this->imageinfo->height;

        $nw = $height * $ratio;
        if ($nw > $width) {
            $nw = $width;
            $nh = $nw / $ratio;
        }
        else {
            $nh = $height;
        }

        if ($nw > $this->imageinfo->width || $nh > $this->imageinfo->height) {
            $nw = $this->imageinfo->width;
            $nh = $this->imageinfo->height;
        }

        $dst_x = ($width - $nw) / 2;
        $dst_y = ($height - $nh) / 2;

        return array($nw, $nh, $dst_x, $dst_y);
    }

    public function clearFilename() {
        $name = str_replace("'", "\'", $this->filename);
        return $name;
    }
}

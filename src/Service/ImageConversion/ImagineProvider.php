<?php

// src/Service/ImageConversion/ImagineProvider.php

namespace App\Service\ImageConversion;

class ImagineProvider
{
    protected $conversionMap = [
        'image/*' => 'image/*',
    ];

    protected $imagine;
    protected $options;

    public function __construct($options = [])
    {
        // set the proper driver
        if (array_key_exists('imagine', $options)) {
            $this->imagine = $options['imagine'];
        }
        else {
            $driver = array_key_exists('driver', $options)
                && in_array($options['driver'], ['gd', 'gmagick', 'imagick'])
                ? $options['driver'] : null;

            if (is_null($driver)) {
                // try to set one depending on available modules
                // we prefer gmagick due to problems in certain imagick versions
                if (class_exists('Gmagick')) {
                    $driver = 'gmagick';
                }
                else if (class_exists('Imagick')) {
                    $driver = 'imagick';
                }
                else if (\function_exists('gd_info')) {
                    $driver = 'gd';
                }
            }

            switch ($driver) {
                case 'gd':
                    $this->imagine = new \Imagine\Gd\Imagine();
                    break;

                case 'gmagick':
                    $this->imagine = new \Imagine\Gmagick\Imagine();
                    break;

                case 'imagick':
                    $this->imagine = new \Imagine\Imagick\Imagine();
                    break;

                default:
                    throw new \InvalidArgumentException('Imagine could not be initialized (no valid driver found)');
            }
        }

        $this->options = $options;
    }

    public function getConversionMap()
    {
        // TODO: check which formats $this->imagine actually supports (depends on Imagick/GD)
        return $this->conversionMap;
    }

    public function convert($fname_src, $fname_dst, $options = [])
    {
        $type_src = $options['src_type'];
        $type_target = $options['target_type'];

        $image = $this->imagine->open($fname_src);

        if (!empty($options['geometry'])) {
            // 640x (set first dimension)
            // x480 (set second dimension)
            // 640x480^ (cover)
            // 640x480 (fill)
            // TODO: 640x480! (fit distort)
            // TODO: 640x480> (fit down)
            // TODO: 640x480< (fit up)
            // 640x480^! (fill and then crop gravity center, own syntax)
            // 40x30%^! (fill and then crop gravity center to certain aspect ratio, own syntax)

            if (!preg_match(
                '/^(\d*)x(\d*)([\^\!\<\>\%]*)$/',
                $options['geometry'],
                $matches
            )) {
                throw new \InvalidArgumentException('Invalid geometry ' . $options['geometry']);
            }

            if ('' === $matches[1] && '' === $matches[2]) {
                throw new \InvalidArgumentException('Invalid geometry ' . $options['geometry']);
            }

            $size = $image->getSize();

            if ($matches[1] > 0 && '' === $matches[2]) {
                // adjust to new width
                $resized = $size->widen(intval($matches[1]));
            }
            else if ($matches[2] > 0 && '' === $matches[1]) {
                // adjust to new height
                $resized = $size->heighten(intval($matches[2]));
            }
            else {
                if ('' === $matches[3]) {
                    // no modifiers, so just fit into box
                    $image = $image->thumbnail(
                        new \Imagine\Image\Box(intval($matches[1]), intval($matches[2])),
                        \Imagine\Image\ImageInterface::THUMBNAIL_INSET
                    );
                }
                else if ('^' === $matches[3]) {
                    // cover geometry

                    // first try adjust to new width
                    $resized = $size->widen(intval($matches[1]));
                    $height = intval($matches[2]);
                    if ($resized->getHeight() < $height) {
                        // if height is too small, then heighten
                        $resized = $resized->heighten($height);
                    }
                }
                else if ('^!' === $matches[3]) {
                    // fill and crop into box
                    $image = $image->thumbnail(
                        new \Imagine\Image\Box(intval($matches[1]), intval($matches[2])),
                        \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                    );
                }
                else if ('%^!' == $matches[3]) {
                    // crop into box with certain ratio
                    $ratio = 1.0 * $size->getWidth() / (1.0 * $size->getHeight());
                    $ratio_new = 1.0 * $matches[1] / (1.0 * $matches[2]);
                    if ($ratio_new > $ratio) {
                        // we crop top/bottom
                        $box = new \Imagine\Image\Box($size->getWidth(), intval($size->getWidth() / $ratio_new + 0.5));
                    }
                    else {
                        // we crop left/right
                        $box = new \Imagine\Image\Box(intval($size->getHeight() * $ratio_new + 0.5), $size->getHeight());
                    }
                    // die($ratio . ' ' . $ratio_new . ' ' . $box->__toString());
                    // fill and crop into box
                    $image = $image->thumbnail(
                        $box,
                        \Imagine\Image\ImageInterface::THUMBNAIL_OUTBOUND
                    );
                }
                else {
                    throw new \InvalidArgumentException('Invalid geometry ' . $options['geometry']);
                }
            }

            if (isset($resized)) {
                $image->resize($resized);
            }
        }

        $save_options_keys = [];

        if ('image/jpeg' == $type_target) {
            $save_options_keys[] = 'jpeg_quality';
        }
        else if ('image/png' == $type_target) {
            $save_options_keys[] = 'png_compression_level';
        }

        $save_options = [];
        foreach ($save_options_keys as $key) {
            if (array_key_exists($key, $options)) {
                $save_options_keys[$key] = $options[$key];
            }
        }

        if ((isset($save_options['resolution-x'])
             || isset($save_options['resolution-y']))
             && !isset($save_options['resolution-units'])) {
            $save_options['resolution-units'] = \Imagine\Image\ImageInterface::RESOLUTION_PIXELSPERINCH;
        }

        $image->save($fname_dst, $save_options);

        return true;
    }

    public function getName()
    {
        return 'imagine-provider';
    }
}

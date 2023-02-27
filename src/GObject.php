<?php

/**
 * Vips is a php binding for the vips image processing library
 *
 * PHP version 7
 *
 * LICENSE:
 *
 * Copyright (c) 2016 John Cupitt
 *
 * Permission is hereby granted, free of charge, to any person obtaining
 * a copy of this software and associated documentation files (the
 * "Software"), to deal in the Software without restriction, including
 * without limitation the rights to use, copy, modify, merge, publish,
 * distribute, sublicense, and/or sell copies of the Software, and to
 * permit persons to whom the Software is furnished to do so, subject to
 * the following conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
 * LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
 * OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
 * WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * @category  Images
 * @package   Jcupitt\Vips
 * @author    John Cupitt <jcupitt@gmail.com>
 * @copyright 2016 John Cupitt
 * @license   https://opensource.org/licenses/MIT MIT
 * @link      https://github.com/libvips/php-vips
 */

namespace Jcupitt\Vips;

use Closure;
use FFI\CData;

/**
 * This class holds a pointer to a GObject and manages object lifetime.
 *
 * @category  Images
 * @package   Jcupitt\Vips
 * @author    John Cupitt <jcupitt@gmail.com>
 * @copyright 2016 John Cupitt
 * @license   https://opensource.org/licenses/MIT MIT
 * @link      https://github.com/libvips/php-vips
 */
abstract class GObject
{
    /**
     * A pointer to the underlying GObject.
     *
     * @internal
     */
    private CData $pointer;

    /**
     * Wrap a GObject around an underlying vips resource. The GObject takes
     * ownership of the pointer and will unref it on finalize.
     *
     * Don't call this yourself, users should stick to (for example)
     * Image::newFromFile().
     *
     * @param CData $pointer The underlying pointer that this
     *  object should wrap.
     *
     * @internal
     */
    public function __construct(CData $pointer)
    {
        $this->pointer = \FFI::cast(FFI::ctypes("GObject"), $pointer);
    }

    public function __destruct()
    {
        $this->unref();
    }

    public function __clone()
    {
        $this->ref();
    }

    public function ref(): void
    {
        FFI::gobject()->g_object_ref($this->pointer);
    }

    public function unref(): void
    {
        FFI::gobject()->g_object_unref($this->pointer);
    }

    public function signalConnect(string $name, Closure $callback): void
    {
        $imageProgressCb = static function (
            CData $gClosure,
            ?CData $returnValue,
            int $numberOfParams,
            CData $params,
            CData $hint,
            ?CData $data
        ) use ($callback) {
            assert($numberOfParams === 3);
            /**
             * Marshal-Signature: void(VipsImage*, void*, void*)
             */
            $vi = \FFI::cast(FFI::ctypes('GObject'), FFI::gobject()->g_value_get_pointer(\FFI::addr($params[1])));
            FFI::gobject()->g_object_ref($vi);
            $image = new Image($vi);
            $pr = \FFI::cast(FFI::ctypes('VipsProgress'), FFI::gobject()->g_value_get_pointer(\FFI::addr($params[2])));
            $callback($image, $pr);
        };
        $marshalers = ['preeval' => $imageProgressCb, 'eval' => $imageProgressCb, 'posteval' => $imageProgressCb];

        if (FFI::atLeast(8, 9)) {
            $marshalers['read'] = static function (
                CData $gClosure,
                CData $returnValue,
                int $numberOfParams,
                CData $params,
                CData $hint,
                ?CData $data
            ) use (&$callback): void {
                assert($numberOfParams === 4);
                /*
                 * Marshal-Signature: gint64(VipsSourceCustom*, void*, gint64, void*)
                 */
                $bufferPointer = FFI::gobject()->g_value_get_pointer(\FFI::addr($params[1]));
                $bufferLength = (int) FFI::gobject()->g_value_get_int64(\FFI::addr($params[2]));
                $buffer = \FFI::string($bufferPointer, $bufferLength);
                $returnBufferLength = $callback($buffer);
                \FFI::memcpy($bufferPointer, $buffer, $returnBufferLength);
                FFI::gobject()->g_value_set_int64($returnValue, $returnBufferLength);
            };
            $marshalers['seek'] = static function (
                CData $gClosure,
                CData $returnValue,
                int $numberOfParams,
                CData $params,
                CData $hint,
                ?CData $data
            ) use (&$callback): void {
                assert($numberOfParams === 4);
                /*
                 * Marshal-Signature: gint64(VipsSourceCustom*, gint64, int, void*)
                 */
                $offset = (int) FFI::gobject()->g_value_get_int64(\FFI::addr($params[1]));
                $whence = (int) FFI::gobject()->g_value_get_int(\FFI::addr($params[2]));
                FFI::gobject()->g_value_set_int64($returnValue, $callback($offset, $whence));
            };
            $marshalers['write'] = static function (
                CData $gClosure,
                CData $returnValue,
                int $numberOfParams,
                CData $params,
                CData $hint,
                ?CData $data
            ) use (&$callback): void {
                assert($numberOfParams === 4);
                /*
                 * Marshal-Signature: gint64(VipsTargetCustom*, void*, gint64, void*)
                 */
                $bufferPointer = FFI::gobject()->g_value_get_pointer(\FFI::addr($params[1]));
                $bufferLength = (int) FFI::gobject()->g_value_get_int64(\FFI::addr($params[2]));
                $buffer = \FFI::string($bufferPointer, $bufferLength);
                $returnBufferLength = $callback($buffer);
                \FFI::memcpy($bufferPointer, $buffer, $returnBufferLength);
                FFI::gobject()->g_value_set_int64($returnValue, $returnBufferLength);
            };
            $marshalers['finish'] = static function (
                CData $gClosure,
                ?CData $returnValue,
                int $numberOfParams,
                CData $params,
                CData $hint,
                ?CData $data
            ) use (&$callback): void {
                assert($numberOfParams === 2);
                /**
                 * Marshal-Signature: void(VipsTargetCustom*, void*)
                 */
                $callback();
            };
        }

        if (FFI::atLeast(8, 13)) {
            $marshalers['end'] = static function (
                CData $gClosure,
                CData $returnValue,
                int $numberOfParams,
                CData $params,
                CData $hint,
                ?CData $data
            ) use (&$callback): void {
                assert($numberOfParams === 2);
                /**
                 * Marshal-Signature: int(VipsTargetCustom*, void*)
                 */
                FFI::gobject()->g_value_set_int($returnValue, $callback());
            };
        }

        if (!isset($marshalers[$name])) {
            throw new Exception("unsupported signal $name");
        }

        $go = \FFI::cast(FFI::ctypes('GObject'), $this->pointer);
        $gc = FFI::gobject()->g_closure_new_simple(\FFI::sizeof(FFI::ctypes('GClosure')), null);
        $gc->marshal = $marshalers[$name];
        FFI::gobject()->g_signal_connect_closure($go, $name, $gc, 0);
    }
}

/*
 * Local variables:
 * tab-width: 4
 * c-basic-offset: 4
 * End:
 * vim600: expandtab sw=4 ts=4 fdm=marker
 * vim<600: expandtab sw=4 ts=4
 */

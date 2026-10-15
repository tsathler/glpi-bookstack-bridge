<?php

namespace GlpiPlugin\Bookstackbridge;

use CommonGLPI;
use Session;

final class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('BookStack knowledge base', 'bookstackbridge');
    }

    public static function getMenuName($nb = 0): string
    {
        return self::getTypeName($nb);
    }

    public static function getMenuContent(): array
    {
        if (!Session::haveRight('config', UPDATE)) {
            return [];
        }
        return [
            'title' => self::getMenuName(),
            'page' => '/plugins/bookstackbridge/',
            'icon' => 'ti ti-books',
        ];
    }
}

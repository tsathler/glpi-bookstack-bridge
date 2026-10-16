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
        $canReadKnowledge = Session::haveRight('knowbase', READ);
        $canConfigure = Session::haveRight('config', UPDATE);
        if (!$canReadKnowledge && !$canConfigure) {
            return [];
        }
        return [
            'title' => self::getMenuName(),
            'page' => $canReadKnowledge ? '/plugins/bookstackbridge/knowledge' : '/plugins/bookstackbridge/',
            'icon' => 'ti ti-books',
        ];
    }
}

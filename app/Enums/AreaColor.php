<?php

namespace App\Enums;

use App\Models\Area;

/**
 * The colors an area can take on the calendar. Color is never the only way to tell areas apart; every block also names its area.
 */
enum AreaColor: string
{
    case Blue = 'blue';
    case Orange = 'orange';
    case Green = 'green';
    case Purple = 'purple';
    case Teal = 'teal';
    case Gray = 'gray';

    public function cssClass(): string
    {
        return 'area-'.$this->value;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /** The first color no area uses yet, or the first color again once every color is taken. */
    public static function next(): self
    {
        $used = Area::query()->pluck('color')
            ->map(fn ($color) => $color instanceof self ? $color : self::tryFrom((string) $color))
            ->filter()
            ->all();

        foreach (self::cases() as $color) {
            if (! in_array($color, $used, true)) {
                return $color;
            }
        }

        return self::cases()[0];
    }
}
